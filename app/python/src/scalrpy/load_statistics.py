import os
import sys
import time
import yaml
import socket
import logging
import urlparse
import cherrypy
import argparse
import multiprocessing
import multiprocessing.pool

from scalrpy.util import rrd
from scalrpy.util import snmp
from scalrpy.util import helper
from scalrpy.util import dbmanager
from scalrpy.util import cryptotool
from scalrpy.util import basedaemon
from scalrpy.util import szr_api

from scalrpy import __version__


CONFIG = {
    'connections': {
        'plotter':{
            'host':'127.0.0.1',
            'port':8080,
            'pool_size':100,
            },
        'mysql': {
            'user':None,
            'pass':None,
            'host':None,
            'port':3306,
            'name':None,
            'pool_size':10,
            },
        },
    'poller':False,
    'plotter':False,
    'pool_size':100,
    'metrics':['cpu', 'la', 'mem', 'net', 'io', 'snum'],
    'instances_connection_policy':'public',
    'instances_connection_timeout':10,
    'with_snmp':False,
    'no_daemon':False,
    'rrd_dir':None,
    'img_dir':None,
    'img_url':None,
    'interval':120,
    'pid_file':'/var/run/scalr.load-statistics.pid',
    'log_file':'/var/log/scalr.load-statistics.log',
    'verbosity':1,
    }

LOG = logging.getLogger('ScalrPy')


def _get_metrics_api(server):
    assert server['server_properties']['scalarizr.key'], \
            "Server %s doesn't have a scalarizr key" % server['server_id']
    host = {
        'public':server['remote_ip'],
        'local':server['local_ip'],
        'auto':server['remote_ip'] if server['remote_ip'] else server['local_ip'],
        }[CONFIG['instances_connection_policy']]
    port = server['server_properties']['scalarizr.api_port']
    headers = None
    if False and 'vpc_ip' in server:
        if server['remote_ip']:
            host = server['remote_ip']
        else:
            host = server['vpc_ip']
            port = 80
            headers = {
                'X-Receiver-Host':server['local_ip'],
                'X-Receiver-Port':server['server_properties']['scalarizr.api_port'],
                }
    key = cryptotool.decrypt_key(server['server_properties']['scalarizr.key'])
    api_type = server['os_type']
    metrics = server['metrics']
    timeout = CONFIG['instances_connection_timeout']
    return szr_api.get_metrics(host, port, key, api_type, metrics, headers=headers, timeout=timeout)


def _get_metrics_snmp(server):
    host = server['remote_ip']
    port = server['server_properties']['scalarizr.snmp_port']
    community = server['farm_hash']
    metrics = server['metrics']
    return snmp.get_metrics(host, port, community, metrics)


def _is_snmp(server):
    is_public = not ('vpc_ip' in server and server['vpc_ip'])
    return CONFIG['with_snmp'] and server['os_type'] == 'linux' and is_public


def _process_server(server):
    data = dict()
    try:
        try:
            data = _get_metrics_api(server)
        except:
            msg = 'Server:%s API failed:%s' % (server['server_id'], helper.exc_info())
            LOG.warning(msg)
            if _is_snmp(server):
                try:
                    data = _get_metrics_snmp(server)
                except:
                    msg = 'Server %s SNMP failed: %s' % (server['server_id'], helper.exc_info())
                    LOG.warning(msg)
    except:
        LOG.error(helper.exc_info())
    result = {
        'farm_id': server['farm_id'],
        'farm_roleid': server['farm_roleid'],
        'index': server['index'],
        'data': data,
        }
    return result


def rf_key(result):
    r_key = os.path.join(
            CONFIG['rrd_dir'],
            helper.x1x2(result['farm_id']),
            '%s' % result['farm_id'],
            'FR_%s' % result['farm_roleid']
            )
    f_key = os.path.join(
            CONFIG['rrd_dir'],
            helper.x1x2(result['farm_id']),
            '%s' % result['farm_id'],
            'FARM'
            )
    return r_key, f_key


def _average(results, ra=None, fa=None, rs=None, fs=None):
    if not ra:
        ra = dict()
    if not fa:
        fa = dict()
    if not rs:
        rs = dict()
    if not fs:
        fs = dict()
    for result in results:
        try:
            r_key, f_key = rf_key(result)
            if 'snum' in CONFIG['metrics']:
                rs.setdefault(r_key, {'snum': {'s_running': 0}})
                fs.setdefault(f_key, {'snum': {'s_running': 0}})
                rs[r_key]['snum']['s_running'] += 1
                fs[f_key]['snum']['s_running'] += 1
            if not result['data']:
                continue
            for metrics_group_name, metrics_data in result['data'].iteritems():
                if not metrics_data or metrics_group_name == 'io':
                    continue
                for metric_name, value in metrics_data.iteritems():
                    try:
                        ra.setdefault(r_key, {})
                        ra[r_key].setdefault(metrics_group_name, {})
                        ra[r_key][metrics_group_name].setdefault(metric_name, Average())

                        fa.setdefault(f_key, {})
                        fa[f_key].setdefault(metrics_group_name, {})
                        fa[f_key][metrics_group_name].setdefault(metric_name, Average())

                        ra[r_key][metrics_group_name][metric_name] += value
                        fa[f_key][metrics_group_name][metric_name] += value
                    except:
                        LOG.error(helper.exc_info())
        except:
            LOG.error(helper.exc_info())
    return ra, fa, rs, fs


class Average(object):

    def __init__(self):
        self._count = 0
        self._value = None


    def __iadd__(self, other):
        if other == None:
            return self
        if self._value == None:
            self._value = float(other)
        else:
            self._value = (self._value * self._count + other) / (self._count + 1)
        self._count += 1
        return self


    def __str__(self):
        return str(self._value)


    def __repr__(self):
        return str(self._value)


    def __int__(self):
        return int(self._value)


    def __float__(self):
        return float(self._value)


class Poller(object):

    def __init__(self):
        self._db = dbmanager.ScalrDB(CONFIG['connections']['mysql'], pool_size=1)


    def _get_db_clients(self):
        i, chunk = 0, 5000
        while True:
            query = """SELECT `id` """ +\
                    """FROM `clients` """ +\
                    """WHERE `status`='Active' """ +\
                    """LIMIT %s,%s"""
            query = query % (i * chunk, chunk)
            result = self._db.execute_query(query)
            if not result:
                break
            i += 1
            yield result


    def _get_db_farms(self, clients):
        clients_id = [_['id'] for _ in clients if _['id']]
        if not clients_id:
            return
        i, chunk = 0, 5000
        while True:
            query = """SELECT `id`, `hash` """ +\
                    """FROM `farms` """+\
                    """WHERE `clientid` IN ( %s ) """ +\
                    """LIMIT %s,%s"""
            query = query % (str(clients_id).replace('L', '')[1:-1], i * chunk, chunk)
            result = self._db.execute_query(query)
            if not result:
                break
            i += 1
            yield result


    def _get_db_servers(self, farms):
        farms_id = [_['id'] for _ in farms if _['id']]
        if not farms_id:
            return
        i, chunk = 0, 5000
        while True:
            query = """SELECT `server_id`, `farm_id`, `farm_roleid`, `index`, """ +\
                    """`remote_ip`, `local_ip`, `env_id`, `os_type` """ +\
                    """FROM `servers` """ +\
                    """WHERE `status`='Running' AND `farm_id` IN ( %s ) """ +\
                    """LIMIT %s,%s"""
            query = query % (str(farms_id).replace('L', '')[1:-1], i * chunk, chunk)
            result = self._db.execute_query(query)
            if not result:
                break
            i += 1
            yield result


    def _get_db_servers_properties(self, servers):
        servers_id = [_['server_id'] for _ in servers if _['server_id']]
        if not servers_id:
            return dict()
        query = """SELECT `server_id`, `name`, `value` """ +\
                """FROM `server_properties` """ +\
                """WHERE `name` IN """ +\
                """( 'scalarizr.snmp_port', 'scalarizr.api_port', 'scalarizr.key' ) """ +\
                """AND `value` IS NOT NULL AND `server_id` IN ( %s )"""
        query = query % str(servers_id)[1:-1]
        result = self._db.execute_query(query)
        props = dict()
        for _ in result:
            props.setdefault(_['server_id'], {}).update({_['name']: _['value']})
        for _ in servers_id:
            props.setdefault(_, {})
            try:
                props[_]['scalarizr.key']
            except:
                props[_]['scalarizr.key'] = None
            try:
                props[_]['scalarizr.api_port']
            except:
                props[_]['scalarizr.api_port'] = 8010
            try:
                props[_]['scalarizr.snmp_port']
            except:
                props[_]['scalarizr.snmp_port'] = 161
        return props


    def _get_farms_hash(self, farms):
        return dict((_['id'], _['hash']) for _ in farms)


    def _get_servers(self, farms):
        for servers in self._get_db_servers(farms):
            servers_properties = self._get_db_servers_properties(servers)
            servers_vpc_ip = self._db.get_servers_vpc_ip(servers)
            farms_hash = self._get_farms_hash(farms)
            envs_status = self._db.get_envs_status_by_servers(servers)
            out = []
            for server in servers:
                try:
                    if envs_status[server['env_id']] != 'Active':
                        continue
                    server_id = server['server_id']
                    server['server_properties'] = servers_properties[server_id]
                    server['farm_hash'] = farms_hash[server['farm_id']]
                    if server_id in servers_vpc_ip:
                        server['vpc_ip'] = servers_vpc_ip[server_id]
                    if server['os_type'] == 'linux':
                        exclude = ['snum']
                    elif server['os_type'] == 'windows':
                        exclude = ['la', 'io', 'snum']
                    else:
                        msg = 'Wrong os type for server %s' % server['server_id']
                        raise Exception(msg)
                    metrics = [m for m in CONFIG['metrics'] if m not in exclude]
                    server['metrics'] = metrics
                    out.append(server)
                except:
                    LOG.error(helper.exc_info())
                    continue
            yield out


    def __call__(self):
        srv_pool = multiprocessing.pool.ThreadPool(CONFIG['pool_size'])
        rrd_pool = multiprocessing.pool.ThreadPool(10)
        try:
            for clients in self._get_db_clients():
                for farms in self._get_db_farms(clients):
                    ra, fa, rs, fs = dict(), dict(), dict(), dict()
                    for servers in self._get_servers(farms):
                        results = srv_pool.map(_process_server, servers)
                        for result in results:
                            if result['data']:
                                file_dir = os.path.join(
                                        CONFIG['rrd_dir'],
                                        helper.x1x2(result['farm_id']),
                                        '%s' % result['farm_id'],
                                        'INSTANCE_%s_%s' % (result['farm_roleid'], result['index'])
                                    )
                                rrd_pool.apply_async(rrd.write, (file_dir, result['data'],))
                        ra, fa, rs, fs = _average(results, ra=ra, fa=fa, rs=rs, fs=fs)
                    for k, v in ra.iteritems():
                        rrd_pool.apply_async(rrd.write, args=(k, v,))
                    for k, v in fa.iteritems():
                        rrd_pool.apply_async(rrd.write, args=(k, v,))
                    if 'snum' in CONFIG['metrics']:
                        for k, v in rs.iteritems():
                            rrd_pool.apply_async(rrd.write, args=(k, v,))
                        for k, v in fs.iteritems():
                            rrd_pool.apply_async(rrd.write, args=(k, v,))
        except:
            LOG.error(helper.exc_info())
        finally:
            srv_pool.close()
            rrd_pool.close()
            srv_pool.join()
            rrd_pool.join()


class Plotter(object):

    class BadRequestError(Exception):
        pass


    class FarmTerminatedError(Exception):
        pass


    class IOError(Exception):
        pass


    def __init__(self):
        self._db = dbmanager.ScalrDB(CONFIG['connections']['mysql'])
        self.plotters = {
                'cpu':rrd.plot_cpu,
                'la':rrd.plot_la,
                'mem':rrd.plot_mem,
                'net':rrd.plot_net,
                'snum':rrd.plot_snum,
                }

        import os
        import threading

        os_local = threading.local()
        for k, v in os.__dict__.iteritems():
            os_local.__setattr__(k, v)
        os = os_local


    def __call__(self):
        try:
            hostname = urlparse.urlparse(CONFIG['connections']['plotter']['host']).hostname
            if hostname:
                host = socket.gethostbyaddr(hostname)[0]
            else:
                host = CONFIG['connections']['plotter']['host']
            cherrypy.config.update({
                'engine.autoreload_on':False,
                'server.socket_host':host,
                'server.socket_port':CONFIG['connections']['plotter']['port'],
                'server.thread_pool':CONFIG['connections']['plotter']['pool_size'],
                'error_page.404': Plotter.error_page_404,
                })
            cherrypy.quickstart(self)
        except:
            LOG.critical(helper.exc_info())


    def _get_farm(self, kwds):
        farm_id = kwds['farmId']
        if not farm_id:
            return tuple()
        query = """SELECT `status`, `env_id` FROM `farms` WHERE `id`=%s""" % farm_id
        result = [_ for _ in self._db.execute_query(query)]
        return result[0] if result else tuple()


    def _get_tz(self, farm):
        env_id = farm['env_id']
        if not env_id:
            return tuple()
        query = """SELECT `value` """ +\
                """FROM `client_environment_properties` """ +\
                """WHERE `name`='timezone' AND `env_id`=%s""" % env_id
        result = [_ for _ in self._db.execute_query(query)]
        if not result or result[0]['value'] == '0':
            return None
        else:
            return result[0]['value']


    def _check_request(self, kwds):
        try:
            assert 'farmId' in kwds, "Missing required parameter 'farmId'"
            int(kwds['farmId'])
            assert 'metric' in kwds, "Missing required parameter 'metric'"
            assert 'period' in kwds, "Missing required parameter 'period'"
            if 'index' in kwds:
                assert 'farmRoleId' in kwds, "Missing required parameter 'farmRoleId'"
            assert kwds['metric'] in ['cpu', 'la', 'mem', 'net', 'io', 'snum'], \
                    "Unsupported metric '%s'" % kwds['metric']
            assert kwds['period'] in ['daily', 'weekly', 'monthly', 'yearly'], \
                    "Unsupported period '%s'" % kwds['period']
        except (AssertionError, ValueError):
            LOG.warning(helper.exc_info())
            raise Plotter.BadRequestError('Bad request')


    def _get_relative_dir(self, kwds):
        if 'index' in kwds:
            tmp = 'INSTANCE_%s_%s' % (kwds['farmRoleId'], kwds['index'])
            return os.path.join('%s' % kwds['farmId'], tmp)
        elif 'farmRoleId' in kwds:
            return os.path.join('%s' % kwds['farmId'], 'FR_%s' % kwds['farmRoleId'])
        else:
            return os.path.join('%s' % kwds['farmId'], 'FARM')


    def _get_rrd_dir(self, kwds, relative_dir):
        base_rrd_dir = os.path.join(CONFIG['rrd_dir'], helper.x1x2(kwds['farmId']))
        rrd_dir = os.path.join(base_rrd_dir, relative_dir)
        return rrd_dir


    def _get_rrd_files(self, kwds, rrd_dir):
        if kwds['metric'] == 'io':
            m = 'IO'
        elif kwds['metric'] == 'snum':
            m = 'SERVERS'
        else:
            m = '%sSNMP' % kwds['metric'].upper()
        try:
            rrd_files = [os.path.join(rrd_dir, m, f) for f in
                    os.walk(os.path.join(rrd_dir, m)).next()[2]]
        except StopIteration:
            rrd_files = []
        for rrd_file in rrd_files:
            if not os.path.exists(rrd_file):
                raise IOError('No such file or directory: %s' % rrd_file)
        return rrd_files


    def _get_image_dir(self, relative_dir):
        img_dir = os.path.join(CONFIG['img_dir'], relative_dir)
        if not os.path.exists(img_dir):
            try:
                os.makedirs(img_dir)
            except OSError as e:
                if e.errno != 17: # File exists
                    raise
        return img_dir


    def _get_url_dir(self, relative_dir):
        return os.path.join(CONFIG['img_url'], relative_dir)


    def _plot_io(self, kwds, rrd_files, img_dir, url_dir, tz):
        url = dict()
        options = rrd.GRAPH_OPT[kwds['period']]
        for rrd_file in rrd_files:
            dev = os.path.basename(rrd_file)[:-4]
            url[dev] = dict()
            img_file = os.path.join(
                    img_dir,
                    '%s_bits_%s_%s.png' % (kwds['metric'], dev, kwds['period'])
                    )
            url[dev]['bits_per_sec'] = os.path.join(
                    url_dir,
                    '%s_bits_%s_%s.png' % (kwds['metric'], dev, kwds['period'])
                    )
            rrd.plot_io_bits(str(img_file), str(rrd_file), options, tz=tz)
            img_file = os.path.join(
                    img_dir,
                    '%s_ops_%s_%s.png' % (kwds['metric'], dev, kwds['period'])
                    )
            url[dev]['operations_per_sec'] = os.path.join(
                    url_dir,
                    '%s_ops_%s_%s.png' % (kwds['metric'], dev, kwds['period'])
                    )
            rrd.plot_io_ops(str(img_file), str(rrd_file), options, tz=tz)
        return url


    def _plot(self, kwds, rrd_files, img_dir, url_dir, tz):
        if kwds['metric'] == 'io':
            url = self._plot_io(kwds, rrd_files, img_dir, url_dir, tz)
        else:
            rrd_file = rrd_files[0]
            img_file = os.path.join(img_dir, '%s_%s.png' % (kwds['metric'], kwds['period']))
            if not os.path.exists(img_file) or os.path.getmtime(img_file) + 60 < time.time():
                options = rrd.GRAPH_OPT[kwds['period']]
                self.plotters[kwds['metric']](str(img_file), str(rrd_file), options)
            url = os.path.join(url_dir, '%s_%s.png' % (kwds['metric'], kwds['period']))
        return url


    @staticmethod
    def error_page_404(*args, **kwds):
        return "We're terribly sorry, the page you're looking for doesn't seem to exist!"


    @cherrypy.expose
    @cherrypy.tools.json_in()
    @cherrypy.tools.json_out()
    def load_statistics(self, **kwds):
        try:
            self._check_request(kwds)
            farm = self._get_farm(kwds)
            if not farm or farm['status'] != 1:
                msg = 'Statistics are not available for terminated farms'
                raise Plotter.FarmTerminatedError(msg)
            tz = self._get_tz(farm)
            if tz:
                os.environ['TZ'] = tz
            relative_dir = self._get_relative_dir(kwds)
            rrd_dir = self._get_rrd_dir(kwds, relative_dir)
            img_dir = self._get_image_dir(relative_dir)
            url_dir = self._get_url_dir(relative_dir)
            rrd_files = self._get_rrd_files(kwds, rrd_dir)
            if not rrd_files:
                LOG.warning("Coudn't find rrd file(s) for request:%s" % kwds)
                raise Plotter.IOError('Statistics are not available')
            url = self._plot(kwds, rrd_files, img_dir, url_dir, tz)
            result = {'success': True, 'msg': url}
        except (Plotter.BadRequestError, Plotter.IOError, Plotter.FarmTerminatedError) as e:
            result = {'success': False, 'msg': str(e)}
        except:
            result = {'success': False, 'msg': 'Internal error. Unable to load statistics'}
            LOG.error(helper.exc_info())
        cherrypy.response.headers['Access-Control-Allow-Origin'] = '*'
        cherrypy.response.headers['Access-Control-Max-Age'] = 300
        if 'Access-Control-Request-Headers' in cherrypy.request.headers:
            cherrypy.response.headers['Access-Control-Allow-Headers'] = \
                    cherrypy.request.headers['Access-Control-Request-Headers']
        return result


class LoadStatistics(basedaemon.BaseDaemon):

    def __init__(self):
        super(LoadStatistics, self).__init__(pid_file=CONFIG['pid_file'])


    def run(self):
        plotter_ps = None
        if CONFIG['plotter']:
            plotter = Plotter()
            plotter_ps = multiprocessing.Process(target=plotter, args=())
            plotter_ps.start()
        if CONFIG['poller']:
            poller = Poller()
            while True:
                try:
                    start_time = time.time()
                    LOG.info('Start iteration')
                    poller_ps = multiprocessing.Process(target=poller, args=())
                    poller_ps.start()
                    poller_ps.join(CONFIG['interval'] * 2)
                    if poller_ps.is_alive():
                        LOG.error('Timeout. Terminating ...')
                        try:
                            helper.kill_ps(poller_ps.pid, child=True)
                        except:
                            LOG.error(sys.exc_info())
                        poller_ps.terminate()
                    LOG.info('Working time: %.2f' % (time.time() - start_time))
                    sleep_time = start_time + CONFIG['interval'] - time.time() - 0.1
                    if sleep_time > 0:
                        time.sleep(sleep_time)
                except KeyboardInterrupt:
                    raise
                except:
                    LOG.error(helper.exc_info())
        if plotter_ps:
            plotter_ps.join()


    def start(self, daemon=False):
        if daemon:
            super(LoadStatistics, self).start()
        else:
            self.run()


def configure(config, args=None):
    global CONFIG
    helper.update_config(config['connections']['mysql'], CONFIG['connections']['mysql'])
    if 'instances_connection_policy' in config:
        CONFIG['instances_connection_policy'] = config['instances_connection_policy']
    if 'system' in config and 'instances_connection_timeout' in config['system']:
        timeout = config['system']['instances_connection_timeout']
        CONFIG['instances_connection_timeout'] = timeout
    if 'load_statistics' in config:
        helper.update_config(config['load_statistics'], CONFIG)
    helper.update_config(config_to=CONFIG, args=args)
    helper.validate_config(CONFIG)
    helper.configure_log(
            log_level=CONFIG['verbosity'],
            log_file=CONFIG['log_file'],
            log_size=1024 * 10000
            )


def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--start', action='store_true', default=False,
            help='start program')
    group.add_argument('--stop', action='store_true', default=False,
            help='stop program')
    parser.add_argument('--poller', action='store_true', default=None,
            help='poller mode')
    parser.add_argument('--plotter', action='store_true', default=None,
            help='plotter mode')
    parser.add_argument('--no-daemon', action='store_true', default=None,
            help="run in no daemon mode")
    parser.add_argument('--with-snmp', action='store_true', default=None,
            help="use snmp")
    parser.add_argument('-i', '--interval', type=int, default=None,
            help="execution interval in seconds. Default is 120")
    parser.add_argument('-p', '--pid-file', default=None,
            help="pid file")
    parser.add_argument('-l', '--log-file', default=None,
            help="log file")
    parser.add_argument('-m', '--metrics', default=None,
            choices=['cpu', 'la', 'mem', 'net', 'io', 'snum'], action='append',
            help="metrics type for processing")
    parser.add_argument('-c', '--config-file', default='./config.yml',
            help='config file')
    parser.add_argument('-v', '--verbosity', default=None, action='count',
            help='increase output verbosity [0:4]. Default is 1 - Error')
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
        daemon = LoadStatistics()
        if args.start:
            LOG.info('Start')
            if helper.check_pid(CONFIG['pid_file']):
                LOG.info('Another copy of process already running. Exit')
                sys.exit(0)
            daemon.start(daemon=not args.no_daemon)
        elif args.stop:
            LOG.info('Stop')
            daemon.stop()
        else:
            print 'Please use %s -h' % sys.argv[0]
        LOG.info('Exit')
    except KeyboardInterrupt:
        sys.stdout.write('Keyboard interrupt\n')
        helper.kill_ps(multiprocessing.current_process().pid, child=True)
        sys.exit(0)
    except SystemExit:
        pass
    except Exception:
        LOG.exception('Something happened and I think I died')
        sys.exit(1)


if __name__ == '__main__':
    main()
