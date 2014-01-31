from gevent import monkey
monkey.patch_all()

import sys
import time
import yaml
import socket
import logging
import argparse
import requests
import gevent.pool

from scalrpy.util import helper
from scalrpy.util import dbmanager
from scalrpy.util import basedaemon
from scalrpy.util import cryptotool

from scalrpy import __version__


CONFIG = {
    'connections':{
        'mysql':{
            'user':None,
            'pass':None,
            'host':None,
            'port':3306,
            'name':None,
            'pool_size':4,
            },
        },
    'pool_size':100,
    'no_daemon':False,
    'cratio':120,
    'instances_connection_timeout':10,
    'instances_connection_policy':'public',
    'log_file':'/var/log/scalr.msg-sender.log',
    'pid_file':'/var/run/scalr.msg-sender.pid',
    'verbosity':1,
    }

LOG = logging.getLogger('ScalrPy')


class MsgSender(basedaemon.BaseDaemon):

    def __init__(self):
        super(MsgSender, self).__init__(pid_file=CONFIG['pid_file'])
        self._db = dbmanager.ScalrDB(CONFIG['connections']['mysql'], pool_size=1)
        self._pool = gevent.pool.Pool(CONFIG['pool_size'])


    def _encrypt(self, server_id, crypto_key, data, headers=None):
        assert server_id, 'server_id = %s' % server_id
        assert crypto_key, 'crypto_key'
        assert data, 'data'
        crypto_algo = dict(name="des_ede3_cbc", key_size=24, iv_size=8)
        data = cryptotool.encrypt(crypto_algo, data, cryptotool.decrypt_key(crypto_key))
        headers = headers or dict()
        headers['X-Signature'], headers['Date'] = cryptotool.sign(data, crypto_key)
        headers['X-Server-Id'] = server_id
        return data, headers


    def _get_db_messages(self):
        query = """SELECT `messageid`, `server_id`, `event_id`, `message_format`, """ +\
                """`handle_attempts`, `message`, `message_name` """ +\
                """FROM `messages` """ +\
                """WHERE `type`='out' AND """ +\
                """`status`=0 AND """ +\
                """`messageid` IS NOT NULL AND """ +\
                """`messageid`!='' AND """ +\
                """`message_version`=2 AND """ +\
                """UNIX_TIMESTAMP(`dtlasthandleattempt`)+""" +\
                """`handle_attempts`*%s<UNIX_TIMESTAMP() """ +\
                """ORDER BY `id` ASC """ +\
                """LIMIT 500"""
        query = query % CONFIG['cratio']
        return self._db.execute_query(query)


    def _get_db_servers(self, messages):
        servers_id = [_['server_id'] for _ in messages if _['server_id']]
        if not servers_id:
            return tuple()
        statuses = [
            'Running',
            'Initializing',
            'Importing',
            'Temporary',
            'Pending terminate',
            ]
        query = """SELECT `server_id`, `farm_id`, `farm_roleid`, """ +\
                """`remote_ip`, `local_ip` """ +\
                """FROM `servers` """ +\
                """WHERE `server_id` IN ( %s ) AND `status` IN ( %s )"""
        query = query % (str(servers_id)[1:-1], str(statuses)[1:-1])
        return self._db.execute_query(query)


    def _get_db_servers_properties(self, servers):
        servers_id = [_['server_id'] for _ in servers if _['server_id']]
        if not servers_id:
            return dict()
        query = """SELECT `server_id`, `name`, `value` """ +\
                """FROM `server_properties` """ +\
                """WHERE `name` IN ( 'scalarizr.ctrl_port', 'scalarizr.key' ) AND """ +\
                """`value` IS NOT NULL AND `value` != '' AND `server_id` IN ( %s )"""
        query = query % str(servers_id)[1:-1]
        result = self._db.execute_query(query)
        tmp = dict()
        for _ in result:
            tmp.setdefault(_['server_id'], {}).update({_['name']:_['value']})
        for _ in servers_id:
            tmp.setdefault(_, {})
            try:
                tmp[_]['scalarizr.key']
            except:
                tmp[_]['scalarizr.key'] = None
            try:
                tmp[_]['scalarizr.ctrl_port']
            except:
                tmp[_]['scalarizr.ctrl_port'] = 8013
        return tmp


    def get_messages(self):
        return self._get_db_messages()


    def get_servers(self, messages):
        servers = self._get_db_servers(messages)
        servers_properties = self._get_db_servers_properties(servers)
        servers_vpc_ip = self._db.get_servers_vpc_ip(servers)
        for server in servers:
            try:
                server_id = server['server_id']
                server['server_properties'] = servers_properties[server_id]
                if server_id in servers_vpc_ip:
                    server['vpc_ip'] = servers_vpc_ip[server_id]
            except:
                LOG.error(helper.exc_info())
                continue
        tmp = dict((_['server_id'], _) for _ in servers)
        res = dict()
        for message in messages:
            try:
                res.update({message['messageid']:tmp[message['server_id']]})
            except:
                res.update({message['messageid']:None})
        return res


    def make_request(self, message, server):
        data, headers = self._encrypt(
                message['server_id'],
                server['server_properties']['scalarizr.key'],
                message['message']
                )
        ip = {
            'public':server['remote_ip'],
            'local':server['local_ip'],
            'auto':server['remote_ip']
            if server['remote_ip'] else server['local_ip'],
            }[CONFIG['instances_connection_policy']]
        port = server['server_properties']['scalarizr.ctrl_port']
""" 
Changed by OlegZ
This code prevents nodes in vpc to be provisioned. Will take a look later
        if 'vpc_ip' in server:
            if server['remote_ip']:
                ip = server['remote_ip']
            else:
                ip = server['vpc_ip']
                port = 80
                headers = {
                    'X-Receiver-Host':server['local_ip'],
                    'X-Receiver-Port':server['server_properties']['scalarizr.ctrl_port'],
                    }
"""
        if message['message_format'] == 'json':
            headers['Content-type'] = 'application/json'
        if not ip:
            raise Exception("server:%s error:can't determine IP" % server['server_id'])
        url = 'http://%s:%s/%s' % (ip, port, 'control')
        request = {
            'url':url,
            'data':data,
            'headers':headers,
            }
        return request


    @helper.apply_async
    def send_request(self, request):
        if not request['url']:
            raise Exception('Wrong request %s' % request)
        r = requests.post(
                request['url'],
                data=request['data'],
                headers=request['headers'],
                timeout=CONFIG['instances_connection_timeout']
                )
        if r.status_code != 201:
            raise Exception('Response code %s' % r.status_code)


    def db_update(self, messages, status):
        if not messages:
            return
        queries = []
        if status == 'ok':
            messages_for_delete = []
            messages_for_update = []
            for message in messages:
                if message['message_name'] == 'ExecScript':
                    messages_for_delete.append(message)
                else:
                    messages_for_update.append(message)
            if messages_for_delete:
                query = """DELETE FROM `messages` WHERE `messageid` IN ( %s )"""
                query = query % str([_['messageid'] for _ in messages_for_delete])[1:-1]
                queries.append(query)
            if messages_for_update:
                query = """UPDATE `messages` SET `status`=1, `message`='', """ +\
                        """`dtlasthandleattempt`=NOW() """ +\
                        """WHERE `messageid` IN ( %s )"""
                query = query % str([_['messageid'] for _ in messages_for_update])[1:-1]
                queries.append(query)
                events_id = [_['event_id'] for _ in messages_for_update if _['event_id']]
                if events_id:
                    query = """UPDATE `events` SET `msg_sent`=`msg_sent`+1 """ +\
                            """WHERE `event_id` IN ( %s )"""
                    query = query % str(events_id)[1:-1]
                    queries.append(query)
        if status == 'fail':
            for message in messages:
                query = """UPDATE `messages` SET `status`=%s, `handle_attempts`=%s, """ +\
                        """`dtlasthandleattempt`=NOW() WHERE `messageid`='%s'"""
                query = query % (
                        0 if message['handle_attempts'] < 2 else 3,
                        message['handle_attempts']+1,
                        message['messageid']
                    )
                queries.append(query)
        if status == 'wrong':
            query = """UPDATE `messages` SET `status`=3, `handle_attempts`=1, """ +\
                    """`dtlasthandleattempt`=NOW() WHERE `messageid` IN ( %s )"""
            query = query % str([_['messageid'] for _ in messages])[1:-1]
            queries.append(query)
        for query in queries:
            while True:
                try:
                    self._db.execute_query(query)
                    break
                except:
                    LOG.error(helper.exc_info())
                    time.sleep(10)


    class NothingToDoError(Exception):
        pass


    def do_iteration(self):
        messages = self.get_messages()
        if not messages:
            raise MsgSender.NothingToDoError()
        servers = self.get_servers(messages)
        results = dict()
        messages_for_update = {'ok':[], 'fail':[], 'wrong':[]}
        for message in messages:
            try:
                if message['messageid'] not in servers or not servers[message['messageid']]:
                    LOG.warning("Server:'%s' dosn't exists" % message['server_id'])
                    messages_for_update['wrong'].append(message)
                    continue
                request = self.make_request(message, servers[message['messageid']])
                msg = "Send message:'%s' url:'%s' headers:'%s'" \
                        % (message['messageid'], request['url'], request['headers'])
                LOG.debug(msg)
                results.update({self.send_request(request, pool=self._pool):message})
            except:
                msg = "Delivery failed, message:'%s' error:'%s'" \
                        % (message['messageid'], helper.exc_info())
                LOG.error(msg)
                messages_for_update['fail'].append(message)
        for result, message in results.iteritems():
            try:
                result.get()
                LOG.debug("Delivery ok, message:'%s'" % message['messageid'])
                messages_for_update['ok'].append(message)
            except:
                msg = "Delivery failed, message:'%s' error:'%s'" \
                        % (message['messageid'], helper.exc_info(line_no=False))
                LOG.warning(msg)
                messages_for_update['fail'].append(message)
        self.db_update(messages_for_update['ok'], 'ok')
        self.db_update(messages_for_update['fail'], 'fail')
        self.db_update(messages_for_update['wrong'], 'wrong')


    def run(self):
        while True:
            try:
                self.do_iteration()
            except MsgSender.NothingToDoError:
                time.sleep(5)
            except KeyboardInterrupt:
                raise KeyboardInterrupt
            except:
                LOG.error(helper.exc_info())
                time.sleep(10)


    def start(self, daemon=False):
        if daemon:
            super(MsgSender, self).start()
        else:
            self.run()


def configure(config, args=None):
    global CONFIG
    helper.update_config(config['connections']['mysql'], CONFIG['connections']['mysql'])
    if 'system' in config and 'instances_connection_timeout' in config['system']:
        timeout = config['system']['instances_connection_timeout']
        CONFIG['instances_connection_timeout'] = timeout
    if 'instances_connection_policy' in config:
        CONFIG['instances_connection_policy'] = config['instances_connection_policy']
    if 'msg_sender' in config:
        helper.update_config(config['msg_sender'], CONFIG)
    helper.update_config(config_to=CONFIG, args=args)
    helper.validate_config(CONFIG)
    helper.configure_log(
            log_level=CONFIG['verbosity'],
            log_file=CONFIG['log_file'],
            log_size=1024*1000
            )


def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--start', action='store_true', default=False,
            help='start program')
    group.add_argument('--stop', action='store_true', default=False,
            help='stop program')
    parser.add_argument('--no-daemon', action='store_true', default=None,
            help="run in no daemon mode")
    parser.add_argument('-p', '--pid-file', default=None,
            help="pid file")
    parser.add_argument('-l', '--log-file', default=None,
            help="log file")
    parser.add_argument('-c', '--config-file', default='./config.yml',
            help='config file')
    parser.add_argument('-t', '--instances-connection-timeout', type=int, default=None,
            help='instances connection timeout')
    parser.add_argument('-v', '--verbosity', action='count', default=None,
            help='increase output verbosity')
    parser.add_argument('--version', action='version', version='Version %s' % __version__)
    args = parser.parse_args()
    try:
        config = yaml.safe_load(open(args.config_file))['scalr']
        configure(config, args)
    except:
        if args.verbosity > 3:
            raise
        else:
            sys.stderr.write('%s\n' % helper.exc_info())
        sys.exit(1)
    try:
        socket.setdefaulttimeout(CONFIG['instances_connection_timeout'])
        daemon = MsgSender()
        if args.start:
            LOG.info('Start')
            if helper.check_pid(CONFIG['pid_file']):
                LOG.info('Another copy of process already running. Exit')
                sys.exit(0)
            daemon.start(daemon= not args.no_daemon)
        elif args.stop:
            LOG.info('Stop')
            daemon.stop()
        else:
            print 'Usage %s -h' % sys.argv[0]
    except KeyboardInterrupt:
        LOG.critical('KeyboardInterrupt')
        return
    except SystemExit:
        pass
    except:
        LOG.exception('Something happened and I think I died')
        sys.exit(1)


if __name__ == '__main__':
    main()
