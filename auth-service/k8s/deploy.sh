#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Kubernetes Deployment Automation
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

cd "${SCRIPT_DIR}"

log_info "--------------------------------------------------------"
log_info "Deploying CodeBridges Auth Service to Kubernetes"
log_info "--------------------------------------------------------"

# 1. Verify kubectl connectivity
if ! kubectl cluster-info >/dev/null 2>&1; then
    log_warn "Kubernetes cluster is not currently reachable."
    if command -v minikube >/dev/null 2>&1; then
        log_info "Attempting to start local Minikube cluster..."
        minikube start --driver=docker
        kubectl config use-context minikube
    else
        log_error "No reachable cluster found. Please start Minikube or configure your kubeconfig."
        exit 1
    fi
fi

# 2. Build local image if Minikube is active
CURRENT_CONTEXT="$(kubectl config current-context 2>/dev/null || echo "")"
if [[ "${CURRENT_CONTEXT}" == *"minikube"* ]]; then
    log_info "Loading local Docker image into Minikube cluster..."
    if ! docker images --format "{{.Repository}}:{{.Tag}}" | grep -q "codebridge-auth-service:latest"; then
        log_info "Building codebridge-auth-service:latest image..."
        docker build -t codebridge-auth-service:latest -f "${ROOT_DIR}/auth-service/Dockerfile" "${ROOT_DIR}/auth-service"
    fi
    minikube image load codebridge-auth-service:latest || true
fi

# 3. Apply manifests using Kustomize
log_info "Applying Kubernetes manifests via Kustomize..."
kubectl apply -k .

# 4. Wait for stateful services to become ready
log_info "Waiting for MySQL, Redis, and MinIO to be ready in namespace [codebridge]..."
kubectl rollout status statefulset/auth-mysql -n codebridge --timeout=120s || true
kubectl rollout status deployment/auth-redis -n codebridge --timeout=120s || true
kubectl rollout status deployment/minio -n codebridge --timeout=120s || true

# 5. Run Database Migrations Job
log_info "Triggering database migration Job..."
kubectl delete job auth-migration-job -n codebridge --ignore-not-found=true
kubectl apply -f migration-job.yaml
kubectl wait --for=condition=complete job/auth-migration-job -n codebridge --timeout=120s 

# 6. Wait for Auth Service deployment rollout
log_info "Waiting for auth-service deployment rollout..."
kubectl rollout status deployment/auth-service -n codebridge --timeout=180s

log_info "--------------------------------------------------------"
log_success "Deployment completed successfully to namespace [codebridge]!"
log_info "Pods status:"
kubectl get pods -n codebridge
log_info "Services:"
kubectl get svc -n codebridge
log_info "--------------------------------------------------------"
log_info "To test locally via port-forward:"
log_info "  kubectl port-forward svc/auth-service 8080:80 -n codebridge"
log_info "  curl http://localhost:8080/api/v1/health"
log_info "--------------------------------------------------------"

