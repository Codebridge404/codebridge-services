#!/usr/bin/env bash
# Update an existing K3s staging stack without replacing its secrets or data.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"
NAMESPACE=codebridge-staging

if [ -n "${KUBECONFIG:-}" ]; then
    test -r "$KUBECONFIG" || { echo "KUBECONFIG is unreadable: $KUBECONFIG"; exit 1; }
else
    for candidate in "$HOME/.kube/config" /home/deploy/.kube/config /etc/rancher/k3s/k3s.yaml; do
        if [ -r "$candidate" ]; then
            export KUBECONFIG="$candidate"
            break
        fi
    done
    if [ -z "${KUBECONFIG:-}" ]; then
        if [ -d /etc/rancher/k3s ]; then
            echo "K3s is installed but no readable kubeconfig is available."
            exit 1
        fi
        echo "No Kubernetes configuration found; Compose-only staging."
        exit 0
    fi
fi
kubectl get namespace "$NAMESPACE" >/dev/null

REVISION="$(git rev-parse HEAD)"
AUTH_IMAGE="127.0.0.1:5000/codebridge-auth-service:$REVISION"
OPS_IMAGE="127.0.0.1:5000/codebridge-operations-service:$REVISION"
docker inspect registry >/dev/null
docker start registry >/dev/null
docker build -t "$AUTH_IMAGE" ./auth-service
docker push "$AUTH_IMAGE"
docker build -t "$OPS_IMAGE" ./operations-service
docker push "$OPS_IMAGE"

# Generate the mounted gateway config from the same routes as Compose.
# subPath mounts require a pod restart to pick up ConfigMap changes.
python3 - <<'PY'
import json, pathlib, subprocess
root = pathlib.Path('gateway')
nginx = (root / 'nginx.conf').read_text().replace(
    'resolver 127.0.0.11', 'resolver kube-dns.kube-system.svc.cluster.local')
routes = '\n'.join(p.read_text() for p in sorted((root / 'services').glob('*.conf'))
                   if p.name != 'fastcgi_php.conf')
routes = routes.replace('/etc/nginx/services/fastcgi_php.conf', '/etc/nginx/fastcgi_php.conf')
nginx = nginx.replace('include /etc/nginx/services/*.conf;', routes)
config = {'apiVersion': 'v1', 'kind': 'ConfigMap',
          'metadata': {'name': 'gateway-nginx-config', 'namespace': 'codebridge-staging'},
          'data': {'nginx.conf': nginx,
                   'fastcgi_php.conf': (root / 'services/fastcgi_php.conf').read_text()}}
subprocess.run(['kubectl', 'apply', '-f', '-'], input=json.dumps(config), text=True, check=True)
PY

kubectl -n "$NAMESPACE" set image deployment/auth-service "auth-service=$AUTH_IMAGE"
kubectl -n "$NAMESPACE" set image deployment/operations-service "operations-service=$OPS_IMAGE"
for service in auth-service operations-service; do
    kubectl -n "$NAMESPACE" rollout status "deployment/$service" --timeout=180s
    kubectl -n "$NAMESPACE" exec "deployment/$service" -- php artisan migrate --force
    kubectl -n "$NAMESPACE" exec "deployment/$service" -- php artisan optimize
done
kubectl -n "$NAMESPACE" set image deployment/auth-horizon "worker=$AUTH_IMAGE"
kubectl -n "$NAMESPACE" set image deployment/operations-horizon "worker=$OPS_IMAGE"
kubectl -n "$NAMESPACE" set image cronjob/auth-scheduler "scheduler=$AUTH_IMAGE"
kubectl -n "$NAMESPACE" set image cronjob/operations-scheduler "scheduler=$OPS_IMAGE" 2>/dev/null || true
kubectl -n "$NAMESPACE" set image cronjob/auth-db-backup "db-backup=$AUTH_IMAGE" 2>/dev/null || true
kubectl -n "$NAMESPACE" set image cronjob/operations-db-backup "db-backup=$OPS_IMAGE" 2>/dev/null || true

# The shared gateway rejects unknown Host headers, including a probe's pod IP.
kubectl -n "$NAMESPACE" patch deployment gateway --type=strategic -p '{"spec":{"template":{"spec":{"containers":[{"name":"nginx","readinessProbe":{"httpGet":{"httpHeaders":[{"name":"Host","value":"localhost"}]}},"livenessProbe":{"httpGet":{"httpHeaders":[{"name":"Host","value":"localhost"}]}}}]}}}}'
kubectl -n "$NAMESPACE" rollout restart deployment/gateway
for service in gateway auth-horizon operations-horizon; do
    kubectl -n "$NAMESPACE" rollout status "deployment/$service" --timeout=180s
done
echo "K3s staging updated to $REVISION (APIs, workers, scheduler, gateway and docs)."
