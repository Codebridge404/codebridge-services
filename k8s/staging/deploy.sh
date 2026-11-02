#!/usr/bin/env bash
# ==============================================================================
# CodeBridge Staging K3s / Kubernetes Deployment Automation
# ==============================================================================
# Architecture:
#   Cloudflare -> Traefik Ingress -> Nginx Gateway (:80) -> PHP-FPM (:9000)
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAMESPACE="codebridge-staging"

echo "======================================================================"
echo "🚀 CodeBridge Staging K3s / Kubernetes Deployment"
echo "======================================================================"
echo "Manifest Directory: ${SCRIPT_DIR}"
echo "Target Namespace:   ${NAMESPACE}"
echo "Timestamp:          $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "======================================================================"

# 1. Pre-flight Checks
echo "▶ 1. Checking Kubernetes cluster connectivity..."
if ! kubectl cluster-info > /dev/null 2>&1; then
  echo "❌ Error: Cannot connect to Kubernetes cluster via kubectl."
  echo "   Please ensure K3S_KUBECONFIG or ~/.kube/config is valid."
  exit 1
fi
echo "✔ Connected to cluster: $(kubectl config current-context 2>/dev/null || echo 'active-cluster')"

# Ensure local container image registry is active on 127.0.0.1:5000
if ! docker ps --filter "name=registry" --format '{{.Names}}' | grep -q "^registry$"; then
  echo "▶ Starting local image registry on 127.0.0.1:5000..."
  docker start registry 2>/dev/null || docker run -d -p 5000:5000 --restart=always --name registry registry:2
fi

# 2. Namespace
echo "▶ 2. Ensuring namespace [${NAMESPACE}] exists..."
kubectl apply -f "${SCRIPT_DIR}/namespace.yaml"

# 3. Configuration & Secrets
echo "▶ 3. Applying ConfigMaps and Secrets..."
if [ ! -f "${SCRIPT_DIR}/secrets.yaml" ]; then
  echo "❌ Error: ${SCRIPT_DIR}/secrets.yaml not found!"
  echo "   Copy secrets.example.yaml to secrets.yaml and populate required credentials."
  exit 1
fi
kubectl apply -f "${SCRIPT_DIR}/secrets.yaml"
kubectl apply -f "${SCRIPT_DIR}/configmaps.yaml"

# Ensure TLS secrets for Traefik Ingress exist
NPM_LE_DIR="${SCRIPT_DIR}/../../infrastructure/nginx-proxy-manager/letsencrypt"
if [ -d "${NPM_LE_DIR}/archive/npm-11" ] && [ -d "${NPM_LE_DIR}/archive/npm-7" ]; then
  echo "▶ Provisioning Let's Encrypt TLS secrets for Traefik Ingress..."
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-11/fullchain1.pem" > /tmp/docs-cert.pem 2>/dev/null || true
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-11/privkey1.pem" > /tmp/docs-key.pem 2>/dev/null || true
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-7/fullchain1.pem" > /tmp/api-cert.pem 2>/dev/null || true
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-7/privkey1.pem" > /tmp/api-key.pem 2>/dev/null || true
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-8/fullchain1.pem" > /tmp/cdn-cert.pem 2>/dev/null || true
  docker run --rm -v "${NPM_LE_DIR}:/le" alpine sh -c "cat /le/archive/npm-8/privkey1.pem" > /tmp/cdn-key.pem 2>/dev/null || true

  if [ -s /tmp/docs-cert.pem ] && [ -s /tmp/docs-key.pem ]; then
    kubectl create secret tls staging-docs-tls --cert=/tmp/docs-cert.pem --key=/tmp/docs-key.pem -n "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f -
  fi
  if [ -s /tmp/api-cert.pem ] && [ -s /tmp/api-key.pem ]; then
    kubectl create secret tls staging-api-tls --cert=/tmp/api-cert.pem --key=/tmp/api-key.pem -n "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f -
  fi
  if [ -s /tmp/cdn-cert.pem ] && [ -s /tmp/cdn-key.pem ]; then
    kubectl create secret tls staging-cdn-tls --cert=/tmp/cdn-cert.pem --key=/tmp/cdn-key.pem -n "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f -
  fi
  rm -f /tmp/docs-cert.pem /tmp/docs-key.pem /tmp/api-cert.pem /tmp/api-key.pem /tmp/cdn-cert.pem /tmp/cdn-key.pem
elif ! kubectl get secret staging-docs-tls -n "${NAMESPACE}" >/dev/null 2>&1; then
  echo "▶ Provisioning staging wildcard TLS certificate for Traefik Ingress..."
  mkdir -p /tmp/tls
  openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /tmp/tls/tls.key -out /tmp/tls/tls.crt \
    -subj "/CN=staging-codebridge-api.servicefixit.me" \
    -addext "subjectAltName=DNS:staging-codebridge-api.servicefixit.me,DNS:staging-codebridge-cdn.servicefixit.me,DNS:staging-codebridge-docs.servicefixit.me,DNS:staging-codebridge-events.servicefixit.me,DNS:*.servicefixit.me" >/dev/null 2>&1
  kubectl create secret tls staging-docs-tls --cert=/tmp/tls/tls.crt --key=/tmp/tls/tls.key -n "${NAMESPACE}"
  kubectl create secret tls staging-api-tls --cert=/tmp/tls/tls.crt --key=/tmp/tls/tls.key -n "${NAMESPACE}"
  kubectl create secret tls staging-cdn-tls --cert=/tmp/tls/tls.crt --key=/tmp/tls/tls.key -n "${NAMESPACE}"
  rm -rf /tmp/tls
fi

# 4. Storage & Infrastructure (MySQL, Redis, MinIO)
echo "▶ 4. Deploying Persistent Storage, MySQL, Redis, and MinIO..."
kubectl apply -f "${SCRIPT_DIR}/mysql.yaml"
kubectl apply -f "${SCRIPT_DIR}/redis.yaml"
kubectl apply -f "${SCRIPT_DIR}/minio.yaml"

echo "⏳ Waiting for MySQL statefulsets to become ready..."
kubectl rollout status statefulset/auth-mysql -n "${NAMESPACE}" --timeout=120s
kubectl rollout status statefulset/operations-mysql -n "${NAMESPACE}" --timeout=120s

echo "⏳ Waiting for Redis and MinIO deployments..."
kubectl rollout status deployment/auth-redis -n "${NAMESPACE}" --timeout=60s
kubectl rollout status deployment/operations-redis -n "${NAMESPACE}" --timeout=60s
kubectl rollout status deployment/minio -n "${NAMESPACE}" --timeout=60s

# 5. MinIO S3 API Bucket Provisioning
echo "▶ 5. Initializing S3 Buckets via MinIO API..."
# Remove any prior completed bucket provisioning job
kubectl delete job minio-provision-buckets -n "${NAMESPACE}" --ignore-not-found=true
kubectl apply -f "${SCRIPT_DIR}/minio.yaml"
kubectl wait --for=condition=complete job/minio-provision-buckets -n "${NAMESPACE}" --timeout=60s || {
  echo "⚠️ Warning: minio-provision-buckets job timed out. Dumping logs:"
  kubectl logs job/minio-provision-buckets -n "${NAMESPACE}" --tail=30 || true
}

# 6. Database Migrations
echo "▶ 6. Executing Database Migrations via Kubernetes Jobs..."
# Remove any prior migration jobs to permit re-running
kubectl delete job auth-migration-job operations-migration-job -n "${NAMESPACE}" --ignore-not-found=true
kubectl apply -f "${SCRIPT_DIR}/migration-jobs.yaml"

echo "⏳ Waiting for auth-service migration..."
kubectl wait --for=condition=complete job/auth-migration-job -n "${NAMESPACE}" --timeout=120s || {
  echo "❌ Error: Auth migration failed. Logs:"
  kubectl logs job/auth-migration-job -c artisan-migrate -n "${NAMESPACE}" --tail=50 || true
  exit 1
}

echo "⏳ Waiting for operations-service migration..."
kubectl wait --for=condition=complete job/operations-migration-job -n "${NAMESPACE}" --timeout=120s || {
  echo "❌ Error: Operations migration failed. Logs:"
  kubectl logs job/operations-migration-job -c artisan-migrate -n "${NAMESPACE}" --tail=50 || true
  exit 1
}
echo "✔ All database migrations completed successfully."

# 7. Deploy Microservices & Gateway
echo "▶ 7. Deploying Microservices, Workers, Scheduler, and Gateway..."
kubectl apply -f "${SCRIPT_DIR}/auth.yaml"
kubectl apply -f "${SCRIPT_DIR}/operations.yaml"
kubectl apply -f "${SCRIPT_DIR}/gateway.yaml"
kubectl apply -f "${SCRIPT_DIR}/ingress.yaml"

echo "⏳ Verifying Microservices rollouts..."
kubectl rollout status deployment/gateway -n "${NAMESPACE}" --timeout=90s
kubectl rollout status deployment/auth-service -n "${NAMESPACE}" --timeout=90s
kubectl rollout status deployment/operations-service -n "${NAMESPACE}" --timeout=90s
kubectl rollout status deployment/auth-horizon -n "${NAMESPACE}" --timeout=60s
kubectl rollout status deployment/operations-horizon -n "${NAMESPACE}" --timeout=60s

echo "======================================================================"
echo "✔ CodeBridge Staging Kubernetes Deployment Succeeded!"
echo "======================================================================"
echo "Status Summary in Namespace [${NAMESPACE}]:"
kubectl get pods,services,ingress -n "${NAMESPACE}"
echo "======================================================================"
echo "🌐 API Endpoint:  https://staging-codebridge-api.servicefixit.me"
echo "🌐 CDN Endpoint:  https://staging-codebridge-cdn.servicefixit.me"
echo "======================================================================"

