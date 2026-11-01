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

