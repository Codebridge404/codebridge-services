#!/usr/bin/env python3
"""Render JSON-formatted Kubernetes YAML. Python standard library only."""
import argparse
import copy
import ipaddress
import json
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('config', nargs='?', type=Path, default=root / 'config.json')
parser.add_argument('--output', type=Path, default=root / 'rendered')
parser.add_argument('--allow-placeholders', action='store_true', help='Preview/CI only; never apply example values')
args = parser.parse_args()
source = args.config
cfg = json.loads(source.read_text())
if not args.allow_placeholders and any(value in json.dumps(cfg) for value in ('YOUR_ORG', 'example.com', 'REPLACE_WITH_')):
    raise SystemExit('Replace example host/images before rendering for deployment')
if not isinstance(cfg.get('activate_background'), bool):
    raise SystemExit('activate_background must be true or false')
host = cfg['host']
if not re.fullmatch(r'[a-z0-9][a-z0-9.-]+[a-z0-9]', host):
    raise SystemExit('host must be a DNS hostname, without scheme/port')
for cidr in cfg['trusted_proxy_cidrs']:
    if ipaddress.ip_network(cidr).prefixlen == 0:
        raise SystemExit('Do not trust all addresses as proxies')
images = [cfg['gateway_image']] + [s[k] for s in cfg['services'] for k in ('app_image', 'web_image')]
for image in images:
    if image.endswith(':latest') or not re.fullmatch(r'[a-zA-Z0-9_./:@-]+', image) or ':' not in image.rsplit('/', 1)[-1]:
        raise SystemExit('Use explicit versioned image tags/digests, not latest')
    if not args.allow_placeholders and image.split('@')[0].split(':')[0] != image.split('@')[0].split(':')[0].lower():
        raise SystemExit('Registry/repository names must be lowercase')
ns = cfg['namespace']
release = cfg['release']
for value in (ns, release):
    if not re.fullmatch(r'[a-z0-9][a-z0-9-]{0,29}', value):
        raise SystemExit('namespace/release must be lowercase DNS labels, max 30 chars')
if sorted(s['name'] for s in cfg['services']) != ['auth', 'operations']:
    raise SystemExit('The existing gateway requires exactly auth and operations')
out = args.output
out.mkdir(parents=True, exist_ok=True)

def obj(api, kind, name, spec=None, **extra):
    value = {'apiVersion': api, 'kind': kind, 'metadata': {'name': name, 'namespace': ns}}
    if spec is not None:
        value['spec'] = spec
    value.update(extra)
    return value

def write(name, resources):
    # JSON is valid YAML; a List can be consumed directly by kubectl.
    (out / name).write_text(json.dumps({'apiVersion': 'v1', 'kind': 'List', 'items': resources}, indent=2) + '\n')

namespace = {'apiVersion': 'v1', 'kind': 'Namespace', 'metadata': {'name': ns}}
write('00-namespace.yaml', [namespace])
base, jobs, workloads, ingresses = [], [], [], []
for svc in cfg['services']:
    name = svc['name']
    if name not in ('auth', 'operations'):
        raise SystemExit('Supported service names: auth, operations')
    if not isinstance(svc['replicas'], int) or not 1 <= svc['replicas'] <= 4:
        raise SystemExit('Use 1-4 replicas per service on this single-node starter')
    for key in ('app_image', 'web_image'):
        image = svc[key]
        if not image or image.endswith(':latest') or (':' not in image.rsplit('/', 1)[-1] and '@sha256:' not in image):
            raise SystemExit('Use versioned image tags or digests, not latest')
    env = {
        'APP_NAME': 'CodeBridge-' + name, 'APP_ENV': 'production', 'APP_DEBUG': 'false',
        'APP_URL': 'https://' + host, 'APP_TIMEZONE': 'Asia/Phnom_Penh',
        'LOG_CHANNEL': 'stderr', 'LOG_LEVEL': 'warning', 'DB_CONNECTION': 'mysql',
        'CACHE_STORE': 'redis', 'SESSION_DRIVER': 'redis', 'SESSION_SECURE_COOKIE': 'true',
        'QUEUE_CONNECTION': 'redis', 'REDIS_CLIENT': 'phpredis',
        'REDIS_DB': svc['redis_db'], 'REDIS_CACHE_DB': svc['redis_cache_db'],
        'REDIS_PREFIX': 'codebridge_' + name + '_', 'CACHE_PREFIX': 'codebridge_' + name + '_cache_',
        'REDIS_QUEUE_RETRY_AFTER': '120', 'FILESYSTEM_DISK': 'local',
        'JWT_ALGO': 'HS256', 'JWT_ISSUER': 'codebridge-auth-service',
        'JWT_ACCESS_TTL': '15', 'JWT_TTL': '15',
        'AUTH_SERVICE_URL': 'http://auth-web/api/v1',
        'APP_MAINTENANCE_DRIVER': 'cache', 'APP_MAINTENANCE_STORE': 'redis'
    }
    base.append(obj('v1', 'ConfigMap', name + '-config', data=env))
    base.append(obj('v1', 'PersistentVolumeClaim', name + '-files', {
        'accessModes': ['ReadWriteOnce'], 'storageClassName': cfg['storage_class'],
        'resources': {'requests': {'storage': cfg['storage_size']}}
    }))
    base.append(obj('v1', 'Service', name + '-web', {
        'type': 'ClusterIP', 'selector': {'app': name, 'component': 'web'},
        'ports': [{'name': 'http', 'port': 80, 'targetPort': 8080}]
    }))
    base.append(obj('v1', 'Service', name + '-service', {
        'type': 'ClusterIP', 'selector': {'app': name, 'component': 'web'},
        'ports': [{'name': 'fpm', 'port': 9000, 'targetPort': 9000}]
    }))
    base.append(obj('networking.k8s.io/v1', 'NetworkPolicy', name + '-web', {
        'podSelector': {'matchLabels': {'app': name, 'component': 'web'}},
        'policyTypes': ['Ingress'], 'ingress': [
            {'from': [{'podSelector': {'matchLabels': {'app': 'gateway'}}}], 'ports': [{'protocol': 'TCP', 'port': 9000}]},
            {'from': [{'podSelector': {}}], 'ports': [{'protocol': 'TCP', 'port': 8080}]}
        ]
    }))
    sec = {'allowPrivilegeEscalation': False, 'capabilities': {'drop': ['ALL']}}
    refs = [{'configMapRef': {'name': name + '-config'}}, {'secretRef': {'name': name + '-env'}}]
    app = {'name': 'app', 'image': svc['app_image'], 'imagePullPolicy': 'IfNotPresent',
        'envFrom': refs, 'securityContext': sec,
        'resources': {'requests': {'cpu': '100m', 'memory': '256Mi'}, 'limits': {'cpu': '1', 'memory': '768Mi'}},
        'volumeMounts': [{'name': 'files', 'mountPath': '/var/www/html/storage/app'}]}
    def pod(containers, restart='Always'):
        spec = {
            'automountServiceAccountToken': False, 'restartPolicy': restart,
            'terminationGracePeriodSeconds': 100,
            'securityContext': {'runAsNonRoot': True, 'runAsUser': 33, 'runAsGroup': 33,
                'fsGroup': 33, 'fsGroupChangePolicy': 'OnRootMismatch',
                'seccompProfile': {'type': 'RuntimeDefault'}},
            'containers': containers,
            'volumes': [{'name': 'files', 'persistentVolumeClaim': {'claimName': name + '-files'}}]
        }
        if cfg.get('image_pull_secret'):
            spec['imagePullSecrets'] = [{'name': cfg['image_pull_secret']}]
        return spec
    def template(component, containers, restart='Always'):
        return {'metadata': {'labels': {'app': name, 'component': component},
            'annotations': {'codebridge/release': release}}, 'spec': pod(containers, restart)}
    webapp = copy.deepcopy(app)
    webapp['ports'] = [{'containerPort': 9000, 'name': 'fpm'}]
    # Startup/liveness check only the process; readiness also boots Laravel via /up.
    webapp['startupProbe'] = {'tcpSocket': {'port': 9000}, 'periodSeconds': 5, 'failureThreshold': 60}
    webapp['livenessProbe'] = {'tcpSocket': {'port': 9000}, 'periodSeconds': 20, 'failureThreshold': 3}
    webapp['lifecycle'] = {'preStop': {'exec': {'command': ['sh', '-c', 'sleep 10']}}}
    websec = copy.deepcopy(sec)
    websec.update({'runAsUser': 101, 'runAsGroup': 101})
    nginx = {'name': 'nginx', 'image': svc['web_image'], 'imagePullPolicy': 'IfNotPresent',
        'securityContext': websec, 'ports': [{'name': 'http', 'containerPort': 8080}],
        'resources': {'requests': {'cpu': '25m', 'memory': '32Mi'}, 'limits': {'cpu': '250m', 'memory': '128Mi'}},
        'volumeMounts': [{'name': 'files', 'mountPath': '/var/www/html/storage/app', 'readOnly': True}],
        'startupProbe': {'httpGet': {'path': '/fpm-ping', 'port': 8080}, 'periodSeconds': 5, 'failureThreshold': 60},
        'readinessProbe': {'httpGet': {'path': '/up', 'port': 8080}, 'periodSeconds': 10, 'timeoutSeconds': 5, 'failureThreshold': 3},
        'livenessProbe': {'httpGet': {'path': '/nginx-health', 'port': 8080}, 'periodSeconds': 20, 'timeoutSeconds': 3, 'failureThreshold': 3},
        'lifecycle': {'preStop': {'exec': {'command': ['sh', '-c', 'sleep 10']}}}}
    workloads.append(obj('apps/v1', 'Deployment', name + '-web', {
        'replicas': svc['replicas'], 'revisionHistoryLimit': 3, 'progressDeadlineSeconds': 600,
        'strategy': {'type': 'RollingUpdate', 'rollingUpdate': {'maxSurge': 1, 'maxUnavailable': 0}},
        'selector': {'matchLabels': {'app': name, 'component': 'web'}},
        'template': template('web', [webapp, nginx])
    }))
    worker = copy.deepcopy(app)
    # args preserves ENTRYPOINT (runtime config cache and required secret checks).
    worker['args'] = ['php', 'artisan', 'queue:work', 'redis', '--queue=default', '--sleep=3', '--tries=3', '--timeout=60', '--max-time=3600']
    workloads.append(obj('apps/v1', 'Deployment', name + '-worker', {
        'replicas': 1 if cfg['activate_background'] else 0, 'revisionHistoryLimit': 3, 'strategy': {'type': 'Recreate'},
        'selector': {'matchLabels': {'app': name, 'component': 'worker'}},
        'template': template('worker', [worker])
    }))
    scheduler = copy.deepcopy(app)
    scheduler['args'] = ['php', 'artisan', 'schedule:run', '--no-interaction']
    workloads.append(obj('batch/v1', 'CronJob', name + '-scheduler', {
        'schedule': '* * * * *', 'timeZone': 'Asia/Phnom_Penh', 'concurrencyPolicy': 'Forbid',
        'suspend': not cfg['activate_background'],
        'startingDeadlineSeconds': 30, 'successfulJobsHistoryLimit': 1, 'failedJobsHistoryLimit': 2,
        'jobTemplate': {'spec': {'backoffLimit': 0, 'activeDeadlineSeconds': 300,
            'template': template('scheduler', [scheduler], 'Never')}}
    }))
    migrate = copy.deepcopy(app)
    migrate['args'] = ['php', 'artisan', 'migrate', '--force', '--no-interaction']
    jobs.append(obj('batch/v1', 'Job', name + '-migrate-' + release, {
        'backoffLimit': 0, 'activeDeadlineSeconds': 600, 'ttlSecondsAfterFinished': 86400,
        'template': template('migration', [migrate], 'Never')
    }))

base.append(obj('v1', 'Service', 'gateway', {
    'type': 'ClusterIP', 'selector': {'app': 'gateway'},
    'ports': [{'name': 'http', 'port': 80, 'targetPort': 8080}]
}))
base.append(obj('v1', 'ConfigMap', 'gateway-proxies', data={'trusted-proxies.conf':
    '\n'.join('set_real_ip_from ' + cidr + ';' for cidr in cfg['trusted_proxy_cidrs']) +
    '\nreal_ip_header X-Forwarded-For;\nreal_ip_recursive on;\n'}))
base.append(obj('networking.k8s.io/v1', 'NetworkPolicy', 'gateway', {
    'podSelector': {'matchLabels': {'app': 'gateway'}}, 'policyTypes': ['Ingress'],
    'ingress': [{'from': [{'namespaceSelector': {'matchLabels': {'kubernetes.io/metadata.name': 'kube-system'}},
        'podSelector': {'matchLabels': {'app.kubernetes.io/name': 'traefik'}}}],
        'ports': [{'protocol': 'TCP', 'port': 8080}]}]
}))
gateway_pod = {
    'automountServiceAccountToken': False, 'terminationGracePeriodSeconds': 100,
    'securityContext': {'runAsNonRoot': True, 'runAsUser': 101, 'runAsGroup': 101, 'seccompProfile': {'type': 'RuntimeDefault'}},
    'containers': [{'name': 'nginx', 'image': cfg['gateway_image'], 'imagePullPolicy': 'IfNotPresent',
        'securityContext': {'allowPrivilegeEscalation': False, 'capabilities': {'drop': ['ALL']}},
        'ports': [{'containerPort': 8080}],
        'resources': {'requests': {'cpu': '25m', 'memory': '32Mi'}, 'limits': {'cpu': '500m', 'memory': '128Mi'}},
        'readinessProbe': {'httpGet': {'path': '/health', 'port': 8080}, 'periodSeconds': 10},
        'livenessProbe': {'httpGet': {'path': '/health', 'port': 8080}, 'periodSeconds': 20},
        'lifecycle': {'preStop': {'exec': {'command': ['sh', '-c', 'sleep 10']}}},
        'volumeMounts': [{'name': 'proxies', 'mountPath': '/etc/nginx/trusted-proxies.conf', 'subPath': 'trusted-proxies.conf', 'readOnly': True}]}],
    'volumes': [{'name': 'proxies', 'configMap': {'name': 'gateway-proxies'}}]
}
if cfg.get('image_pull_secret'):
    gateway_pod['imagePullSecrets'] = [{'name': cfg['image_pull_secret']}]
workloads.append(obj('apps/v1', 'Deployment', 'gateway', {
    'replicas': 2, 'revisionHistoryLimit': 3,
    'strategy': {'type': 'RollingUpdate', 'rollingUpdate': {'maxUnavailable': 0, 'maxSurge': 1}},
    'selector': {'matchLabels': {'app': 'gateway'}},
    'template': {'metadata': {'labels': {'app': 'gateway'}, 'annotations': {'codebridge/release': release}}, 'spec': gateway_pod}
}))
ingress = obj('networking.k8s.io/v1', 'Ingress', 'gateway', {
    'ingressClassName': 'traefik', 'tls': [{'hosts': [host], 'secretName': 'gateway-tls'}],
    'rules': [{'host': host, 'http': {'paths': [{'path': '/', 'pathType': 'Prefix',
        'backend': {'service': {'name': 'gateway', 'port': {'number': 80}}}}]}}]
})
ingress['metadata']['annotations'] = {'traefik.ingress.kubernetes.io/router.entrypoints': 'websecure',
    'traefik.ingress.kubernetes.io/router.tls': 'true'}
ingresses.append(ingress)

write('10-base.yaml', base)
write('20-migrations.yaml', jobs)
write('30-workloads.yaml', workloads)
write('40-ingress.yaml', ingresses)
print('Rendered manifests in', out)
print('Apply in stages from README; migrations change the database.')
