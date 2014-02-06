import os
import re
import sys
import time
import yaml
import socket
import logging
import urllib2
import rrdtool
import argparse
import multiprocessing as mp

from scalrpy.util import rpc
from scalrpy.util import helper
from scalrpy.util import dbmanager
from scalrpy.util import cryptotool
from scalrpy.util import basedaemon

from sqlalchemy import and_
from sqlalchemy import exc as db_exc

from multiprocessing import pool

from scalrpy import __version__


oids_data = {
        'cpu':{
                'user':'.1.3.6.1.4.1.2021.11.50.0',
                'nice':'.1.3.6.1.4.1.2021.11.51.0',
                'system':'.1.3.6.1.4.1.2021.11.52.0',
                'idle':'.1.3.6.1.4.1.2021.11.53.0',
        },
        'la':{
                'la1':'.1.3.6.1.4.1.2021.10.1.3.1',
                'la5':'.1.3.6.1.4.1.2021.10.1.3.2',
                'la15':'.1.3.6.1.4.1.2021.10.1.3.3',
        },
        'mem':{
                'swap':'.1.3.6.1.4.1.2021.4.3.0',
                'swapavail':'.1.3.6.1.4.1.2021.4.4.0',
                'total':'.1.3.6.1.4.1.2021.4.5.0',
                'avail':'.1.3.6.1.4.1.2021.4.6.0',
                'free':'.1.3.6.1.4.1.2021.4.11.0',
                'shared':'.1.3.6.1.4.1.2021.4.13.0',
                'buffer':'.1.3.6.1.4.1.2021.4.14.0',
                'cached':'.1.3.6.1.4.1.2021.4.15.0',
        },
        'net':{
                'in':'.1.3.6.1.2.1.2.2.1.10.2',
                'out':'.1.3.6.1.2.1.2.2.1.16.2',
        }
}

cpu_source = [
        'DS:user:COUNTER:600:U:U',
        'DS:system:COUNTER:600:U:U',
        'DS:nice:COUNTER:600:U:U',
        'DS:idle:COUNTER:600:U:U'
]

cpu_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800']

la_source = [
        'DS:la1:GAUGE:600:U:U',
        'DS:la5:GAUGE:600:U:U',
        'DS:la15:GAUGE:600:U:U'
]

la_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800'
]

mem_source = [
        'DS:swap:GAUGE:600:U:U',
        'DS:swapavail:GAUGE:600:U:U',
        'DS:total:GAUGE:600:U:U',
        'DS:avail:GAUGE:600:U:U',
        'DS:free:GAUGE:600:U:U',
        'DS:shared:GAUGE:600:U:U',
        'DS:buffer:GAUGE:600:U:U',
        'DS:cached:GAUGE:600:U:U'
]

mem_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800'
]

net_source = [
        'DS:in:COUNTER:600:U:21474836480',
        'DS:out:COUNTER:600:U:21474836480'
]

net_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800'
]

servers_num_source = [
        'DS:s_running:GAUGE:600:U:U'
]

servers_num_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800'
]

io_source = [
        'DS:read:COUNTER:600:U:U',
        'DS:write:COUNTER:600:U:U',
        'DS:rbyte:COUNTER:600:U:U',
        'DS:wbyte:COUNTER:600:U:U'
]

io_archive = [
        'RRA:AVERAGE:0.5:1:800',
        'RRA:AVERAGE:0.5:6:800',
        'RRA:AVERAGE:0.5:24:800',
        'RRA:AVERAGE:0.5:288:800',
        'RRA:MAX:0.5:1:800',
        'RRA:MAX:0.5:6:800',
        'RRA:MAX:0.5:24:800',
        'RRA:MAX:0.5:288:800',
        'RRA:LAST:0.5:1:800',
        'RRA:LAST:0.5:6:800',
        'RRA:LAST:0.5:24:800',
        'RRA:LAST:0.5:288:800'
]

CONFIG = {
        'farm_procs':1,
        'serv_thrds':30,
        'rrd_thrds':2,
        'with_snmp':False,
        'no_daemon':False,
        'metrics':['cpu', 'la', 'mem', 'net'],
        'instances_connection_policy':'public',
        'instances_connection_timeout':10,
        'rrd_db_dir':'/tmp/rrd_db_dir',
        'pid_file':'/var/run/scalr.stats-poller.pid',
        'log_file':'/var/log/scalr.stats-poller.log',
        'verbosity':1,
        'interval':None
}

LOG = logging.getLogger('ScalrPy')


def post_processing(results):
    """ Calculating role average, farm average, role servers summary, farm servers summary """

    ra = {}
    fa = {}
    ras = {}
    fas = {}
    rs = {}
    fs = {}

    for result in results:
        if not result:
            continue

        try:
            r_key = '%s/%s' % (result['farm_id'], result['farm_role_id'])
            f_key = '%s' % result['farm_id']
            ra.setdefault(r_key, {})
            fa.setdefault(f_key, {})
            ras.setdefault(r_key, {})
            fas.setdefault(f_key, {})
            try:
                rs[r_key]['servers']['s_running'] += 1
            except KeyError:
                rs.setdefault(r_key, {'servers':{'s_running':1}})
            try:
                fs[f_key]['servers']['s_running'] += 1
            except KeyError:
                fs.setdefault(f_key, {'servers':{'s_running':1}})

            for metric_group, metrics in result['data'].iteritems():
                ra[r_key].setdefault(metric_group, {})
                fa[f_key].setdefault(metric_group, {})
                ras[r_key].setdefault(metric_group, {})
                fas[f_key].setdefault(metric_group, {})
                for metric, value in metrics.iteritems():
                    ra[r_key][metric_group].setdefault(metric, None)
                    fa[f_key][metric_group].setdefault(metric, None)
                    ras[r_key][metric_group].setdefault(metric, 0)
                    fas[f_key][metric_group].setdefault(metric, 0)
                    if value is not None:
                        ras[r_key][metric_group][metric] += 1
                        if ra[r_key][metric_group][metric] is None:
                            ra[r_key][metric_group][metric] = value
                        else:
                            k = float(ras[r_key][metric_group][metric]-1) /\
                                    float(ras[r_key][metric_group][metric])
                            ra[r_key][metric_group][metric] = \
                                    ra[r_key][metric_group][metric] * k + value /\
                                    ras[r_key][metric_group][metric]
                        fas[f_key][metric_group][metric] += 1
                        if fa[f_key][metric_group][metric] is None:
                            fa[f_key][metric_group][metric] = value
                        else:
                            k = float(fas[f_key][metric_group][metric]-1) /\
                                        float(fas[f_key][metric_group][metric])
                            fa[f_key][metric_group][metric] = \
                                    fa[f_key][metric_group][metric] * k + value /\
                                    fas[f_key][metric_group][metric]

        except:
            LOG.error(helper.exc_info())

    return ra, fa, rs, fs



def server_thread(args):
    try:
        task, rrd_pool = args
        if not task:
            return
        try:
            host = task['host']
            port = task['api_port']
            key = task['srz_key']
            os_type = task['os_type']
            metrics = task['metrics']
            proxy = task['proxy']
            data = ScalarizrAPI.get(
                    host=host, port=port, key=key, os_type=os_type, metrics=metrics, proxy=proxy)
        except:
            LOG.warning('%s:%s scalarizr api failed: %s'
                    % (task['host'], task['api_port'], helper.exc_info()))
            if CONFIG['with_snmp']:
                try:
                    host = task['host']
                    port = task['snmp_port']
                    community = task['community']
                    metrics = task['metrics']
                    data = SNMP.get(host=host, port=port, community=community, metrics=metrics)
                except:
                    LOG.warning('%s SNMP failed: %s' % (task['host'], helper.exc_info()))
                    return
            else:
                return

        key = '%s/%s/%s' % (task['farm_id'], task['farm_role_id'], task['index'])
        rrd_pool.map_async(RRDWorker().work, [{'server':{key:data}}])

        result = {'farm_id':task['farm_id'], 'farm_role_id':task['farm_role_id'],
                'index':task['index'], 'data':data}
    except:
        LOG.error(helper.exc_info())
        result = None

    return result



def farm_process(tasks):
    if not tasks:
        return

    try:
        servs_pool = pool.ThreadPool(processes=CONFIG['serv_thrds'])
        rrd_pool = pool.ThreadPool(processes=CONFIG['rrd_thrds'])
        results = servs_pool.map(server_thread, [(t, rrd_pool) for t in tasks])
        servs_pool.close()

        if not results:
            return

        ra, fa, rs, fs = post_processing(results)

        for k, v in ra.iteritems():
            rrd_pool.map_async(RRDWorker().work, [{'ra':{k:v}}])

        for k, v in fa.iteritems():
            rrd_pool.map_async(RRDWorker().work, [{'fa':{k:v}}])

        for k, v in rs.iteritems():
            rrd_pool.map_async(RRDWorker().work, [{'rs':{k:v}}])

        for k, v in fs.iteritems():
            rrd_pool.map_async(RRDWorker().work, [{'fs':{k:v}}])
    except:
        LOG.error(helper.exc_info())
    finally:
        servs_pool.close()
        servs_pool.join()
        rrd_pool.close()
        rrd_pool.join()



class StatsPoller(basedaemon.BaseDaemon):

    def __init__(self):
        super(StatsPoller, self).__init__(pid_file=CONFIG['pid_file'])
        self._db_manager = dbmanager.DBManager(CONFIG['connections']['mysql'], autoflush=False)


    def __call__(self):
        try:
            tasks = self._produce_tasks()
            if tasks:
                self._process_tasks(tasks)
        except db_exc.SQLAlchemyError:
            LOG.error(helper.exc_info())
        except:
            LOG.exception('Exception')


    def run(self):
        while True:
            start_time = time.time()
            LOG.info('Start iteration')

            p = mp.Process(target=self.__call__, args=())
            p.start()
            p.join(300)
            if p.is_alive():
                LOG.error('Timeout. Terminating ...')
                try:
                    helper.kill_ps(p.pid, child=True)
                except:
                    LOG.exception('Exception')
                p.terminate()

            LOG.info('Working time: %s' % (time.time() - start_time))

            if not CONFIG['interval']:
                break

            sleep_time = start_time + CONFIG['interval'] - time.time()
            if sleep_time > 0:
                time.sleep(sleep_time)


    def start(self, daemon=False):
        if daemon:
            super(StatsPoller, self).start()
        else:
            self.run()


    def restart(self, daemon=False):
        self.stop()
        self.start(daemon=daemon)


    def _get_clients(self):
        db = self._db_manager.get_db()
        clients = db.session.query(db.clients.id).filter_by(status='Active')
        return clients


    def _get_farms(self, clients_id):
        db = self._db_manager.get_db()
        farms = db.session.query(
                db.farms.id, db.farms.hash).filter(
                db.farms.clientid.in_(clients_id))
        return farms


    def _filter_vpc_farms(self, farms_id):
        db = self._db_manager.get_db()
        where = and_(
                db.farm_settings.farmid.in_(farms_id),
                db.farm_settings.name=='ec2.vpc.id',
                db.farm_settings.value!='NULL')
        return [farm.farmid for farm in \
                db.session.query(db.farm_settings.farmid).filter(where)]


    def _get_vpc_router_roles(self, farms_id):
        db = self._db_manager.get_db()
        where = and_(
                db.role_behaviors.behavior=='router')
        vpc_roles = db.session.query(db.role_behaviors.role_id).filter(where)

        where = and_(
                db.farm_roles.role_id.in_([behavior.role_id for behavior in vpc_roles]),
                db.farm_roles.farmid.in_(farms_id))
        return dict((el.farmid, el.id) for el in db.session.query(
                db.farm_roles.farmid, db.farm_roles.id).filter(where))


    def _get_servers(self, farms_id):
        db = self._db_manager.get_db()
        servers = db.session.query(db.servers.server_id, db.servers.farm_id,
                db.servers.farm_roleid, db.servers.index, db.servers.remote_ip,
                db.servers.local_ip, db.servers.env_id, db.servers.os_type).filter(and_(
                db.servers.farm_id.in_(farms_id),
                db.servers.status=='Running'))
        return servers


    def _get_env_statuses(self, environments_id):
        db = self._db_manager.get_db()
        statuses = db.session.query(
                db.client_environments.id, db.client_environments.status).filter(
                db.client_environments.id.in_(environments_id))
        return statuses


    def _get_snmp_ports(self, servers_id):
        db = self._db_manager.get_db()
        where_port = and_(
                db.server_properties.server_id.in_(servers_id),
                db.server_properties.name=='scalarizr.snmp_port',
                db.server_properties.value!='NULL')
        snmp_ports = db.session.query(db.server_properties.server_id,
                db.server_properties.value).filter(where_port)
        return snmp_ports


    def _get_api_ports(self, servers_id):
        db = self._db_manager.get_db()
        where_port = and_(
                db.server_properties.server_id.in_(servers_id),
                db.server_properties.name=='scalarizr.api_port',
                db.server_properties.value!='NULL')
        api_ports = db.session.query(db.server_properties.server_id,
                db.server_properties.value).filter(where_port)
        return api_ports


    def _get_srz_keys(self, servers_id):
        db = self._db_manager.get_db()
        where_key = and_(
                db.server_properties.server_id.in_(servers_id),
                db.server_properties.name=='scalarizr.key',
                db.server_properties.value!='NULL',
                db.server_properties.value!='')
        srz_keys = db.session.query(db.server_properties.server_id,
                db.server_properties.value).filter(where_key)
        return srz_keys


    def _produce_tasks(self):
        tasks = []
        db = self._db_manager.get_db()

        try:
            clients = self._get_clients()
            if not clients:
                return

            farms = self._get_farms([client.id for client in clients])
            if not farms:
                return

            servers = self._get_servers([farm.id for farm in farms])
            if not servers:
                return

            servers_id = [server.server_id for server in servers]

            vpc_farms_id = self._filter_vpc_farms([farm.id for farm in farms])
            vpc_router_roles = self._get_vpc_router_roles(vpc_farms_id)

            env_statuses = dict((el.id, el.status)
                    for el in self._get_env_statuses([server.env_id for server in servers]))

            api_ports = dict((el.server_id, el.value)
                    for el in self._get_api_ports(servers_id))
            srz_keys = dict((el.server_id, el.value)
                    for el in self._get_srz_keys(servers_id))
            snmp_ports = dict((el.server_id, el.value)
                    for el in self._get_snmp_ports(servers_id))

            communities = dict((farm.id, farm.hash) for farm in farms)

            for server in servers:
                try:
                    if env_statuses[server.env_id] != 'Active':
                        continue

                    ip = {
                            'public':server.remote_ip,
                            'local':server.local_ip,
                            'auto':server.remote_ip
                            if server.remote_ip else server.local_ip
                            }[CONFIG['instances_connection_policy']]

                    if server.os_type == 'linux':
                        metrics = CONFIG['metrics']
                    elif server.os_type == 'windows':
                        metrics = [metric for metric in CONFIG['metrics'] if metric != 'la']

                    task = {
                            'farm_id':server.farm_id,
                            'farm_role_id':server.farm_roleid,
                            'index':server.index,
                            'metrics':metrics}

                    try:
                        task['srz_key'] = srz_keys[server.server_id]
                    except:
                        LOG.warning('Scalarizr key not found for server %s' % server.server_id)

                    try:
                        task['api_port'] = api_ports[server.server_id]
                    except:
                        task['api_port'] = 8010

                    task['proxy'] = None

                    task['os_type'] = server.os_type
                    """
                    Added by OlegZ. Need to figure out how this vpc logic actual works
                    """
                    if False and server.farm_id in vpc_farms_id:
                        if server.farm_id in vpc_router_roles:
                            if server.remote_ip:
                                ip = server.remote_ip
                            else:
                                where = and_(
                                        db.farm_role_settings.farm_roleid==vpc_router_roles[server.farm_id],
                                        db.farm_role_settings.name=='router.vpc.ip',
                                        db.farm_role_settings.value!='NULL')
                                ip_query = db.session.query(
                                        db.farm_role_settings.value).filter(where).first()
                                if ip_query:
                                    ip = None
                                    headers = {
                                            'X-Receiver-Host':server.local_ip,
                                            'X-Receiver-Port':task['api_port']}
                                    task['proxy'] = {
                                            'headers':headers,
                                            'host':ip_query.value,
                                            'port':80}
                                else:
                                    continue
                    else:
                        if server.os_type != 'windows':
                            task['community'] = communities[server.farm_id]
                            try:
                                task['snmp_port'] = snmp_ports[server.server_id]
                            except:
                                task['snmp_port'] = 161

                    task['host'] = ip

                    tasks.append(task)
                except:
                    LOG.error(helper.exc_info())
        finally:
            db.session.remove()

        return tasks


    def _compose_tasks(self, tasks):
        farm_tasks = {}

        for task in tasks:
            farm_tasks.setdefault(task['farm_id'], []).append(task)

        chunks = [[]]
        chunk_length = len(tasks) / CONFIG['farm_procs']
        for tasks_ in farm_tasks.values():
            if len(chunks[-1]) >= chunk_length:
                chunks.append([])
            chunks[-1] += tasks_

        return chunks


    def _process_tasks(self, tasks):
        chunks = self._compose_tasks(tasks)
        if not chunks:
            return

        farms_pool = mp.Pool(processes=CONFIG['farm_procs'])
        try:
            farms_pool.map_async(farm_process, chunks)
        finally:
            farms_pool.close()
            farms_pool.join()



class SNMP(object):

    @staticmethod
    def get(host=None, port=None, community=None, metrics=None):
        assert host and port and community and metrics

        oids = []
        for k, v in oids_data.iteritems():
            if k in metrics:
                for kk, vv in v.iteritems():
                    oids.append(vv)

        import netsnmp
        session = netsnmp.Session(
                DestHost = '%s:%s' %(host, port),
                Version = 1,
                Community = community,
                Timeout=2000000)
        Vars = netsnmp.VarList(*oids)

        snmp_data = dict((oid, val) for oid, val in zip(oids, session.get(Vars)))

        data = {}
        for metric_name in metrics:
            if metric_name not in oids_data:
                continue
            for metric in oids_data[metric_name].keys():
                try:
                    value = float(snmp_data[oids_data[metric_name][metric]])
                except:
                    value = None
                data.setdefault(metric_name, {}).setdefault(metric, value)

        return data


class ScalarizrAPI(object):

    @staticmethod
    def _get_cpu_stat(hsp, api_type):
        if api_type not in ['linux', 'windows']:
            raise Exception('CPU stat, unsupported api type: %s' % api_type)

        timeout = CONFIG['instances_connection_timeout']
        cpu = hsp.sysinfo.cpu_stat(timeout=timeout)

        for k, v in cpu.iteritems():
            cpu[k] = float(v)

        return {'cpu':cpu}


    @staticmethod
    def _get_la_stat(hsp, api_type):
        if api_type != 'linux':
            raise Exception('LA stat, unsupported api type: %s' % api_type)

        timeout = CONFIG['instances_connection_timeout']
        la = hsp.sysinfo.load_average(timeout=timeout)

        return {'la':{'la1':float(la[0]), 'la5':float(la[1]), 'la15':float(la[2])}}


    @staticmethod
    def _get_mem_info(hsp, api_type):
        if api_type not in ['linux', 'windows']:
            raise Exception('MEM info, unsupported api type: %s' % api_type)

        timeout = CONFIG['instances_connection_timeout']
        mem = hsp.sysinfo.mem_info(timeout=timeout)

        if api_type == 'linux':
            ret = {
                    'swap':float(mem['total_swap']),
                    'swapavail':float(mem['avail_swap']),
                    'total':float(mem['total_real']),
                    'avail':None, # FIXME
                    'free':float(mem['total_free']),
                    'shared':float(mem['shared']),
                    'buffer':float(mem['buffer']),
                    'cached':float(mem['cached'])
                    }
        elif api_type == 'windows':
            ret = {
                    'swap':float(mem['total_swap']),
                    'swapavail':float(mem['avail_swap']),
                    'total':float(mem['total_real']),
                    'avail':None, # FIXME
                    'free':float(mem['total_free'])
                    }
        else:
            raise Exception('Unsupported api type: %s' % api_type)

        return {'mem':ret}


    @staticmethod
    def _get_net_stat(hsp, api_type):
        if api_type not in ['linux', 'windows']:
            raise Exception('NET stat, unsupported api type: %s' % api_type)

        timeout = CONFIG['instances_connection_timeout']
        net = hsp.sysinfo.net_stats(timeout=timeout)

        if api_type == 'linux':
            ret = {'net':{
                        'in':float(net['eth0']['receive']['bytes']),
                        'out':float(net['eth0']['transmit']['bytes'])}}

        if api_type == 'windows':
            for key in net:
                if re.match(r'^.* Ethernet Adapter _0$', key):
                    ret = {'net':{
                                'in':float(net[key]['receive']['bytes']),
                                'out':float(net[key]['transmit']['bytes'])}}
                    break
            else:
                raise Exception('Can\'t find \'* Ethernet Adapter _0\' pattern in api response')

        return ret


    @staticmethod
    def get(host=None, port=None, key=None, os_type=None, metrics=None, proxy=None):
        assert (host or proxy) and port and key and os_type and metrics

        if proxy:
            host = proxy['host']
            port = proxy['port']
            headers = proxy['headers']
        else:
            headers = None

        endpoint = 'http://%s:%s' % (host, port)
        security = rpc.Security(cryptotool.decrypt_key(key))
        hsp = rpc.HttpServiceProxy(endpoint, security=security, headers=headers)

        data = dict()

        if 'cpu' in metrics:
            try:
                data.update(ScalarizrAPI._get_cpu_stat(hsp, os_type))
            except Exception as e:
                if type(e) in (urllib2.URLError, socket.timeout): raise e
                LOG.warning('%s:%s scalarizr api CPU failed: %s'
                        % (host, port, helper.exc_info()))
        if 'la' in metrics:
            try:
                data.update(ScalarizrAPI._get_la_stat(hsp, os_type))
            except Exception as e:
                if type(e) in (urllib2.URLError, socket.timeout): raise e
                LOG.warning('%s:%s scalarizr api LA failed: %s'
                        % (host, port, helper.exc_info()))
        if 'mem' in metrics:
            try:
                data.update(ScalarizrAPI._get_mem_info(hsp, os_type))
            except Exception as e:
                if type(e) in (urllib2.URLError, socket.timeout): raise e
                LOG.warning('%s:%s scalarizr api MEM failed: %s'
                        % (host, port, helper.exc_info()))
        if 'net' in metrics:
            try:
                data.update(ScalarizrAPI._get_net_stat(hsp, os_type))
            except Exception as e:
                if type(e) in (urllib2.URLError, socket.timeout): raise e
                LOG.warning('%s:%s scalarizr api NET failed: %s'
                        % (host, port, helper.exc_info()))

        return data



class RRDWriter(object):

    def __init__(self, source, archive):
        self.source = source
        self.archive = archive


    def _create_db(self, rrd_db_path):
        if not os.path.exists(os.path.dirname(rrd_db_path)):
            os.makedirs(os.path.dirname(rrd_db_path))
        rrdtool.create(rrd_db_path, self.source, self.archive)


    def write(self, rrd_db_path, data):
        rrd_db_path = str(rrd_db_path)

        if not os.path.isfile(rrd_db_path):
            self._create_db(rrd_db_path)

        data_to_write = 'N'
        for s in self.source:
            data_type = {'COUNTER':int, 'GAUGE':float}[s.split(':')[2]]
            try:
                data_to_write += ':%s' % (data_type)(data[s.split(':')[1]])
            except:
                data_to_write += ':U'

        LOG.debug('%s, %s, %s' %(time.time(), rrd_db_path, data_to_write))
        try:
            rrdtool.update(rrd_db_path, "--daemon", "unix:/var/run/rrdcached.sock", data_to_write)
        except rrdtool.error, e:
            LOG.error('RRDTool update error:%s, %s' %(e, rrd_db_path))



class RRDWorker(object):

    writers = {
            'cpu':RRDWriter(cpu_source, cpu_archive),
            'la':RRDWriter(la_source, la_archive),
            'mem':RRDWriter(mem_source, mem_archive),
            'net':RRDWriter(net_source, net_archive),
            'servers':RRDWriter(servers_num_source, servers_num_archive)}


    def _x1x2(self, farm_id):
        i = int(farm_id[-1])-1
        x1 = str(i-5*(i/5)+1)[-1]
        x2 = str(i-5*(i/5)+6)[-1]

        return 'x%sx%s' % (x1, x2)


    def _process_server_task(self, task):
        for key, data in task.iteritems():
            farm_id, farm_role_id, index = key.split('/')

            for metrics_group_name, metrics_group in data.iteritems():
                RRDWorker.writers[metrics_group_name].write(
                        '%s/%s/%s/INSTANCE_%s_%s/%sSNMP/db.rrd'\
                        % (CONFIG['rrd_db_dir'], self._x1x2(farm_id), farm_id, farm_role_id,
                        index, metrics_group_name.upper()), metrics_group)


    def _process_ra_task(self, task):
        for key, data in task.iteritems():
            farm_id, farm_role_id = key.split('/')

            for metrics_group_name, metrics_group in data.iteritems():
                RRDWorker.writers[metrics_group_name].write(
                        '%s/%s/%s/FR_%s/%sSNMP/db.rrd'\
                        % (CONFIG['rrd_db_dir'], self._x1x2(farm_id), farm_id, farm_role_id,
                        metrics_group_name.upper()), metrics_group)


    def _process_fa_task(self, task):
        for key, data in task.iteritems():
            farm_id = key

            for metrics_group_name, metrics_group in data.iteritems():
                RRDWorker.writers[metrics_group_name].write(
                        '%s/%s/%s/FARM/%sSNMP/db.rrd'\
                        % (CONFIG['rrd_db_dir'], self._x1x2(farm_id), farm_id,
                        metrics_group_name.upper()), metrics_group)


    def _process_rs_task(self, task):
        for key, data in task.iteritems():
            farm_id, farm_role_id = key.split('/')

            for metrics_group_name, metrics_group in data.iteritems():
                RRDWorker.writers[metrics_group_name].write(
                        '%s/%s/%s/FR_%s/SERVERS/db.rrd'\
                        % (CONFIG['rrd_db_dir'], self._x1x2(farm_id), farm_id, farm_role_id),
                        metrics_group)


    def _process_fs_task(self, task):
        for key, data in task.iteritems():
            farm_id = key

            for metrics_group_name, metrics_group in data.iteritems():
                RRDWorker.writers[metrics_group_name].write(
                        '%s/%s/%s/FARM/SERVERS/db.rrd'\
                        % (CONFIG['rrd_db_dir'], self._x1x2(farm_id), farm_id), metrics_group)


    def work(self, task):
        try:
            task_name = task.keys()[0]
            if task_name == 'server':
                self._process_server_task(task[task_name])
            elif task_name == 'ra':
                self._process_ra_task(task[task_name])
            elif task_name == 'fa':
                self._process_fa_task(task[task_name])
            elif task_name == 'rs':
                self._process_rs_task(task[task_name])
            elif task_name == 'fs':
                self._process_fs_task(task[task_name])
        except:
            LOG.error(helper.exc_info())



def configure(args, config):
    global CONFIG

    if 'instances_connection_policy' in config:
        CONFIG['instances_connection_policy'] = config['instances_connection_policy']
    if 'system' in config and 'instances_connection_timeout' in config['system']:
        CONFIG['instances_connection_timeout'] = config['system']['instances_connection_timeout']

    if 'stats_poller' not in config:
        raise Exception("Can't find 'stats_poller' section in %s" % args.config_file)

    for k, v in config['stats_poller'].iteritems():
        CONFIG.update({k:v})

    for k, v in vars(args).iteritems():
        if v is not None:
            CONFIG.update({k:v})

    log_size = 1024*500 if CONFIG['verbosity'] < 2 else 1024*10000
    helper.configure_log(
            log_level=CONFIG['verbosity'],
            log_file=CONFIG['log_file'],
            log_size=log_size
            )


def main():
    sys.stderr.write("This script is deprecated. Instead use load_statistics.py\n\n")

    parser = argparse.ArgumentParser()

    group = parser.add_mutually_exclusive_group()
    group.add_argument('--start', action='store_true', default=False, help='start daemon')
    group.add_argument('--stop', action='store_true', default=False, help='stop daemon')
    group.add_argument('--restart', action='store_true', default=False, help='restart daemon')

    parser.add_argument('--no-daemon', action='store_true', default=None,
            help="Run in no daemon mode")
    parser.add_argument('--with-snmp', action='store_true', default=None,
            help="Use snmp")
    parser.add_argument('-i', '--interval', type=int, default=None,
            help="execution interval in seconds. Default is 0 - exec once")
    parser.add_argument('-p', '--pid-file', default=None, help="Pid file")
    parser.add_argument('-l', '--log-file', default=None, help="Log file")
    parser.add_argument('-m', '--metrics', default=None, choices=['cpu', 'la', 'mem', 'net'],
            action='append', help="metrics type for processing")
    parser.add_argument('-c', '--config-file', default='./config.yml', help='config file')
    parser.add_argument('-t', '--instances-connection-timeout', type=int, default=None,
            help='instances connection timeout')
    parser.add_argument('-v', '--verbosity', default=None, action='count',
            help='increase output verbosity [0:4]. Default is 1 - Error')
    parser.add_argument('--version', action='version', version='Version %s' % __version__)

    args = parser.parse_args()

    try:
        config = yaml.safe_load(open(args.config_file))['scalr']
        configure(args, config)
    except:
        if args.verbosity > 3:
            raise
        else:
            sys.stderr.write('%s\n' % helper.exc_info())
        sys.exit(1)

    try:
        socket.setdefaulttimeout(CONFIG['instances_connection_timeout'])
        daemon = StatsPoller()

        if args.start:
            LOG.info('Start')
            if helper.check_pid(CONFIG['pid_file']):
                LOG.info('Another copy of process already running. Exit')
                sys.exit(0)
            daemon.start(daemon= not args.no_daemon)
        elif args.stop:
            LOG.info('Stop')
            daemon.stop()
        elif args.restart:
            LOG.info('Restart')
            daemon.restart(daemon= not args.no_daemon)
        else:
            print 'Usage %s -h' % sys.argv[0]

        LOG.info('Exit')

    except KeyboardInterrupt:
        LOG.critical(helper.exc_info())
        helper.kill_ps(mp.current_process().pid, child=True)
        sys.exit(0)
    except SystemExit:
        pass
    except Exception:
        LOG.critical('Something happened and I think I died')
        LOG.exception('Critical exception')
        sys.exit(1)


if __name__ == '__main__':
    main()
