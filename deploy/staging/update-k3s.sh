#!/usr/bin/env bash
# Update existing K3s staging service deployments without touching shared infrastructure, gateway, or secrets.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

NAMESPACE="codebridge-staging"

# ------------------------------------------------------------------------------
# 1. ARGUMENT VALIDATION & LOGGING
# ------------------------------------------------------------------------------
if [ $# -lt 1 ]; then
    echo "ERROR: Missing required service argument."
    echo "Usage: $0 <auth|operations|all> [commit-sha]"
    exit 1
fi

SERVICE="$1"
if [[ "$SERVICE" != "auth" && "$SERVICE" != "operations" && "$SERVICE" != "all" ]]; then
    echo "ERROR: Invalid service '$SERVICE'. Allowed values: auth, operations, all."
    echo "Usage: $0 <auth|operations|all> [commit-sha]"
    exit 1
fi

REVISION="${2:-$(git rev-parse HEAD)}"
if [ -z "$REVISION" ]; then
    echo "ERROR: Unable to resolve Git commit revision."
    exit 1
fi

echo "=========================================================================="
echo "K3s Staging Microservice Deployment"
echo "  Target Service : $SERVICE"
echo "  Image Tag (SHA): $REVISION"
echo "  K3s Namespace  : $NAMESPACE"
echo "=========================================================================="

# ------------------------------------------------------------------------------
# 2. KUBECONFIG & NAMESPACE AVAILABILITY VERIFICATION
# ------------------------------------------------------------------------------
if [ -n "${KUBECONFIG:-}" ]; then
    test -r "$KUBECONFIG" || { echo "ERROR: KUBECONFIG is unreadable: $KUBECONFIG"; exit 1; }
else
    for candidate in "$HOME/.kube/config" /home/deploy/.kube/config /etc/rancher/k3s/k3s.yaml; do
        if [ -r "$candidate" ]; then
            export KUBECONFIG="$candidate"
            break
        fi
    done
    if [ -z "${KUBECONFIG:-}" ]; then
        if [ -d /etc/rancher/k3s ]; then
            echo "ERROR: K3s is installed but no readable kubeconfig is available."
            exit 1
        fi
        echo "ERROR: No Kubernetes configuration found. K3s is the required staging runtime."
        exit 1
    fi
fi

if ! kubectl get namespace "$NAMESPACE" >/dev/null 2>&1; then
    echo "ERROR: Kubernetes namespace '$NAMESPACE' is inaccessible or does not exist."
    exit 1
fi

# ------------------------------------------------------------------------------
# 3. VERIFY LOCAL DOCKER REGISTRY (127.0.0.1:5000)
# ------------------------------------------------------------------------------
echo "Verifying local Docker registry container 'registry'..."
if ! docker inspect registry >/dev/null 2>&1; then
    echo "ERROR: Local Docker registry container 'registry' not found."
    exit 1
fi
docker start registry >/dev/null 2>&1 || true

# ------------------------------------------------------------------------------
# 4. HELPER FUNCTIONS
# ------------------------------------------------------------------------------
run_artisan_scramble_if_available() {
    local deploy_name="$1"
    if kubectl -n "$NAMESPACE" exec "deployment/$deploy_name" -- php artisan list | grep -q "scramble:cache"; then
        echo "Running scramble:cache for $deploy_name..."
        kubectl -n "$NAMESPACE" exec "deployment/$deploy_name" -- php artisan scramble:cache
    else
        echo "scramble:cache command not found on $deploy_name; skipping."
    fi
}

deploy_auth() {
    local img="127.0.0.1:5000/codebridge-auth-service:$REVISION"
    echo ""
    echo ">>> Building and deploying Auth Service ($img) <<<"

    docker build -t "$img" ./auth-service
    docker push "$img"

    echo "Updating deployment/auth-service container 'auth-service'..."
    kubectl -n "$NAMESPACE" set image deployment/auth-service "auth-service=$img"

    echo "Updating deployment/auth-horizon container 'worker'..."
    kubectl -n "$NAMESPACE" set image deployment/auth-horizon "worker=$img"

    if kubectl -n "$NAMESPACE" get cronjob/auth-scheduler >/dev/null 2>&1; then
        echo "Updating cronjob/auth-scheduler container 'scheduler'..."
        kubectl -n "$NAMESPACE" set image cronjob/auth-scheduler "scheduler=$img"
    fi

    if kubectl -n "$NAMESPACE" get cronjob/auth-db-backup >/dev/null 2>&1; then
        echo "Updating cronjob/auth-db-backup container 'db-backup'..."
        kubectl -n "$NAMESPACE" set image cronjob/auth-db-backup "db-backup=$img"
    fi

    echo "Waiting for rollout of deployment/auth-service..."
    kubectl -n "$NAMESPACE" rollout status deployment/auth-service --timeout=180s

    echo "Waiting for rollout of deployment/auth-horizon..."
    kubectl -n "$NAMESPACE" rollout status deployment/auth-horizon --timeout=180s

    echo "Running migrations for auth-service..."
    kubectl -n "$NAMESPACE" exec deployment/auth-service -- php artisan migrate --force

    echo "Running optimization for auth-service..."
    kubectl -n "$NAMESPACE" exec deployment/auth-service -- php artisan optimize

    run_artisan_scramble_if_available "auth-service"

    echo "✔ Auth Service successfully updated and verified on K3s."
}

deploy_operations() {
    local img="127.0.0.1:5000/codebridge-operations-service:$REVISION"
    echo ""
    echo ">>> Building and deploying Operations Service ($img) <<<"

    docker build -t "$img" ./operations-service
    docker push "$img"

    echo "Updating deployment/operations-service container 'operations-service'..."
    kubectl -n "$NAMESPACE" set image deployment/operations-service "operations-service=$img"

    echo "Updating deployment/operations-horizon container 'worker'..."
    kubectl -n "$NAMESPACE" set image deployment/operations-horizon "worker=$img"

    if kubectl -n "$NAMESPACE" get cronjob/operations-scheduler >/dev/null 2>&1; then
        echo "Updating cronjob/operations-scheduler container 'scheduler'..."
        kubectl -n "$NAMESPACE" set image cronjob/operations-scheduler "scheduler=$img"
    fi

    if kubectl -n "$NAMESPACE" get cronjob/operations-db-backup >/dev/null 2>&1; then
        echo "Updating cronjob/operations-db-backup container 'db-backup'..."
        kubectl -n "$NAMESPACE" set image cronjob/operations-db-backup "db-backup=$img"
    fi

    echo "Waiting for rollout of deployment/operations-service..."
    kubectl -n "$NAMESPACE" rollout status deployment/operations-service --timeout=180s

    echo "Waiting for rollout of deployment/operations-horizon..."
    kubectl -n "$NAMESPACE" rollout status deployment/operations-horizon --timeout=180s

    echo "Running migrations for operations-service..."
    kubectl -n "$NAMESPACE" exec deployment/operations-service -- php artisan migrate --force

    echo "Running optimization for operations-service..."
    kubectl -n "$NAMESPACE" exec deployment/operations-service -- php artisan optimize

    run_artisan_scramble_if_available "operations-service"

    echo "✔ Operations Service successfully updated and verified on K3s."
}

update_gateway_infrastructure() {
    # Reserved for full infrastructure / gateway updates in dedicated workflow.
    # Do NOT execute during auth-only or operations-only deployments.
    echo "Updating gateway ConfigMap..."
    python3 - <<'PY'
import json, pathlib, re, subprocess
root = pathlib.Path('gateway')
nginx = (root / 'nginx.conf').read_text().replace(
    'resolver 127.0.0.11', 'resolver kube-dns.kube-system.svc.cluster.local')

def inline_service_include(match):
    target = match.group(1)
    if target == '*.conf':
        files = [p for p in sorted((root / 'services').glob('*.conf')) if p.name != 'fastcgi_php.conf']
        content = '\n'.join(p.read_text() for p in files)
    else:
        path = root / 'services' / target
        content = path.read_text() if path.exists() else ''
    return content.replace('/etc/nginx/services/fastcgi_php.conf', '/etc/nginx/fastcgi_php.conf')

nginx = re.sub(
    r'^\s*include\s+/etc/nginx/services/([^;\s]+);\s*$',
    inline_service_include,
    nginx,
    flags=re.MULTILINE,
)
assert '/etc/nginx/services/' not in nginx, 'Unresolved /etc/nginx/services include in generated K3s nginx.conf'

config = {'apiVersion': 'v1', 'kind': 'ConfigMap',
          'metadata': {'name': 'gateway-nginx-config', 'namespace': 'codebridge-staging'},
          'data': {'nginx.conf': nginx,
                   'fastcgi_php.conf': (root / 'services/fastcgi_php.conf').read_text()}}
subprocess.run(['kubectl', 'apply', '-f', '-'], input=json.dumps(config), text=True, check=True)
PY
    kubectl -n "$NAMESPACE" patch deployment gateway --type=strategic -p '{"spec":{"template":{"spec":{"containers":[{"name":"nginx","readinessProbe":{"httpGet":{"httpHeaders":[{"name":"Host","value":"localhost"}]}},"livenessProbe":{"httpGet":{"httpHeaders":[{"name":"Host","value":"localhost"}]}}}]}}}}'
    kubectl -n "$NAMESPACE" rollout restart deployment/gateway
    kubectl -n "$NAMESPACE" rollout status deployment/gateway --timeout=180s
}

# ------------------------------------------------------------------------------
# 5. EXECUTION DISPATCH
# ------------------------------------------------------------------------------
case "$SERVICE" in
    auth)
        deploy_auth
        ;;
    operations)
        deploy_operations
        ;;
    all)
        deploy_auth
        deploy_operations
        ;;
esac

echo ""
echo "=========================================================================="
echo "✔ K3s staging rollout successfully completed for: $SERVICE ($REVISION)"
echo "=========================================================================="
