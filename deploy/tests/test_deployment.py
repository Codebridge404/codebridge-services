"""Regression checks for route preservation and safe deployment defaults."""
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]


class DeploymentTest(unittest.TestCase):
    def render(self, config, output, preview=False):
        cmd = [sys.executable, str(REPO / 'deploy/scripts/render.py'), str(config), '--output', str(output)]
        if preview:
            cmd += ['--allow-placeholders']
        return subprocess.run(cmd, capture_output=True, text=True)

    def test_production_render_rejects_example_values(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = self.render(REPO / 'deploy/config.example.json', Path(tmp) / 'out')
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Replace example', result.stderr)
            self.assertFalse((Path(tmp) / 'out').exists())

    def test_route_modules_are_preserved_and_tls_is_forwarded(self):
        with tempfile.TemporaryDirectory() as tmp:
            output = Path(tmp) / 'gateway'
            subprocess.run([sys.executable, str(REPO / 'deploy/scripts/prepare-gateway.py'), str(REPO / 'gateway'), str(output)], check=True)
            for path in (REPO / 'gateway/services').glob('*.conf'):
                if path.name == 'fastcgi_php.conf':
                    continue
                self.assertEqual(path.read_bytes(), (output / 'services' / path.name).read_bytes())
            config = (output / 'nginx.conf').read_text()
            self.assertNotIn('127.0.0.11', config)
            self.assertIn('listen 8080;', config)
            self.assertIn('rewrite ^/v1/(.*)$ /api/v1/$1 last;', config)
            self.assertIn('HTTPS $origin_https', (output / 'services/fastcgi_php.conf').read_text())

    def test_gateway_adaptation_fails_if_source_shape_changes(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / 'source'
            shutil.copytree(REPO / 'gateway', source)
            p = source / 'nginx.conf'
            p.write_text(p.read_text().replace('listen 80;', 'listen 8000;'))
            result = subprocess.run([sys.executable, str(REPO / 'deploy/scripts/prepare-gateway.py'), str(source), str(Path(tmp) / 'out')], capture_output=True)
            self.assertNotEqual(result.returncode, 0)

    def test_gateway_targets_readiness_gated_pods_and_background_is_paused(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertEqual(self.render(REPO / 'deploy/config.example.json', tmp, preview=True).returncode, 0)
            items = [item for p in Path(tmp).glob('*.yaml') for item in json.loads(p.read_text())['items']]
            index = {(o['kind'], o['metadata']['name']): o for o in items}
            for name in ('auth', 'operations'):
                web = index['Deployment', name + '-web']['spec']
                service = index['Service', name + '-service']['spec']
                self.assertEqual(service['selector'], web['template']['metadata']['labels'])
                self.assertEqual(service['ports'][0]['targetPort'], 9000)
                nginx = next(c for c in web['template']['spec']['containers'] if c['name'] == 'nginx')
                self.assertEqual(nginx['readinessProbe']['httpGet']['path'], '/up')
                self.assertEqual(index['Deployment', name + '-worker']['spec']['replicas'], 0)
                self.assertTrue(index['CronJob', name + '-scheduler']['spec']['suspend'])
                job = next(o for (k, n), o in index.items() if k == 'Job' and n.startswith(name + '-migrate-'))
                c = job['spec']['template']['spec']['containers'][0]
                self.assertNotIn('command', c, 'Kubernetes must not bypass required secret checks in ENTRYPOINT')
                self.assertNotIn('migrate:fresh', c['args'])
                self.assertNotIn('--seed', c['args'])
            ingress = index['Ingress', 'gateway']['spec']
            self.assertEqual(len([o for o in items if o['kind'] == 'Ingress']), 1)
            self.assertEqual(ingress['rules'][0]['http']['paths'][0]['backend']['service']['name'], 'gateway')
            self.assertFalse(any(o['kind'] == 'Secret' for o in items))

    def test_background_activation_and_gateway_proxy_trust_validation(self):
        with tempfile.TemporaryDirectory() as tmp:
            config = json.loads((REPO / 'deploy/config.example.json').read_text())
            config['activate_background'] = True
            p = Path(tmp) / 'config.json'
            p.write_text(json.dumps(config))
            out = Path(tmp) / 'out'
            self.assertEqual(self.render(p, out, preview=True).returncode, 0)
            items = json.loads((out / '30-workloads.yaml').read_text())['items']
            self.assertTrue(all(not o['spec']['suspend'] for o in items if o['kind'] == 'CronJob'))
            config['trusted_proxy_cidrs'] = ['0.0.0.0/0']
            p.write_text(json.dumps(config))
            self.assertNotEqual(self.render(p, out, preview=True).returncode, 0)


if __name__ == '__main__':
    unittest.main()
