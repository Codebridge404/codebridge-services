#!/usr/bin/env python3
"""Adapt the checked-in gateway for a non-root Kubernetes container.

The route modules are copied byte-for-byte, apart from the common TLS parameter.
Fail on unexpected source changes rather than silently losing an adaptation.
"""
import shutil
import sys
from pathlib import Path

source, output = map(Path, sys.argv[1:])
output.mkdir(parents=True, exist_ok=True)
shutil.copytree(source / 'services', output / 'services', dirs_exist_ok=True)
shutil.copyfile(source / 'docs.html', output / 'docs.html')
text = (source / 'nginx.conf').read_text()

def replace_once(text, before, after):
    if text.count(before) != 1:
        raise SystemExit(f'Gateway source changed: expected exactly one {before!r}')
    return text.replace(before, after, 1)

text = replace_once(text, 'listen 80;', 'listen 8080;')
text = replace_once(text, '    resolver 127.0.0.11 valid=10s ipv6=off;\n', '')
text = replace_once(text, 'http {', '''http {
    client_body_temp_path /tmp/client_temp;
    proxy_temp_path /tmp/proxy_temp;
    fastcgi_temp_path /tmp/fastcgi_temp;
    uwsgi_temp_path /tmp/uwsgi_temp;
    scgi_temp_path /tmp/scgi_temp;
    include /etc/nginx/trusted-proxies.conf;
    map $http_x_forwarded_proto $origin_https {
        default "";
        https on;
    }
''')
text = 'pid /tmp/nginx.pid;\n' + text
(output / 'nginx.conf').write_text(text)
common = output / 'services/fastcgi_php.conf'
common.write_text(replace_once(common.read_text(),
    'fastcgi_param HTTPS $https if_not_empty;',
    'fastcgi_param HTTPS $origin_https if_not_empty;'))
# Overlaid by a ConfigMap at runtime; useful for image validation without a cluster.
(output / 'trusted-proxies.conf').write_text('set_real_ip_from 127.0.0.1;\nreal_ip_header X-Forwarded-For;\nreal_ip_recursive on;\n')
