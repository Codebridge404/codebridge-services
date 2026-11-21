#!/usr/bin/env bash
# ==============================================================================
# CodeBridge K3s Service Deployment Helper (Single Service Rollout)
# ==============================================================================
# Supported targets:
#   auth        -> deployment/auth-service, deployment/auth-horizon
#   operations  -> deployment/operations-service, deployment/operations-horizon
# ==============================================================================
set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT_DIR}"

# ------------------------------------------------------------------------------
# 1. ARGUMENT VALIDATION
# ------------------------------------------------------------------------------
if [ $# -lt 2 ]; then
    echo "ERROR: Invalid arguments." >&2
    echo "Usage: $0 <auth|operations> <commit-sha>" >&2
    exit 1
fi

SERVICE="$1"
COMMIT_SHA="$2"
NAMESPACE="codebridge-staging"

if [[ "$SERVICE" != "auth" && "$SERVICE" != "operations" ]]; then
    echo "ERROR: Unsupported service '$SERVICE'. Allowed values: auth, operations." >&2
    exit 1
fi

if [[ ! "$COMMIT_SHA" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
    echo "ERROR: Invalid commit SHA format: '$COMMIT_SHA'." >&2
    exit 1
fi

SHORT_SHA="${COMMIT_SHA:0:7}"
IMAGE_NAME="codebridge-${SERVICE}-service"
IMAGE_TAG="127.0.0.1:5000/${IMAGE_NAME}:${COMMIT_SHA}"

case "$SERVICE" in
    auth)
        DEPLOY_API="auth-service"
        CONTAINER_API="auth-service"
        DEPLOY_WORKER="auth-horizon"
        CONTAINER_WORKER="worker"
        CRON_SCHEDULER="auth-scheduler"
        CRON_BACKUP="auth-db-backup"
        CONFIGMAP_NAME="auth-config"
        SECRET_NAME="auth-secrets"
        SERVICE_DIR="auth-service"
        ;;
    operations)
        DEPLOY_API="operations-service"
        CONTAINER_API="operations-service"
        DEPLOY_WORKER="operations-horizon"
        CONTAINER_WORKER="worker"
        CRON_SCHEDULER="operations-scheduler"
        CRON_BACKUP="operations-db-backup"
        CONFIGMAP_NAME="operations-config"
        SECRET_NAME="operations-secrets"
        SERVICE_DIR="operations-service"
        ;;
esac

echo "=========================================================================="
echo "CodeBridge K3s Single-Service Deployment"
echo "  Target Service : ${SERVICE}"
echo "  Target SHA     : ${COMMIT_SHA} (${SHORT_SHA})"
echo "  Image Tag      : ${IMAGE_TAG}"
echo "  Namespace      : ${NAMESPACE}"
echo "  Working Root   : ${ROOT_DIR}"
echo "=========================================================================="

# ------------------------------------------------------------------------------
# 2. VPS FLOCK LOCK (Covers checkout & full deployment)
# ------------------------------------------------------------------------------
LOCK_FILE="/tmp/codebridge-staging-deploy.lock"
if command -v flock >/dev/null 2>&1; then
    exec 200>"${LOCK_FILE}"
    echo "Acquiring VPS deployment lock on ${LOCK_FILE}..."
    if ! flock -w 600 200; then
        echo "ERROR: Timed out waiting for deployment lock (${LOCK_FILE})." >&2
        exit 1
    fi
    echo "✔ Acquired deployment lock."
else
    echo "Notice: 'flock' utility not found. Proceeding without file lock."
fi

# ------------------------------------------------------------------------------
# 3. SAFE REPOSITORY CHECKOUT (No destructive reset/clean)
# ------------------------------------------------------------------------------
git config core.filemode false || true

echo "Checking tracked working tree on VPS..."
if ! git diff-index --quiet HEAD --; then
    echo "Notice: Local modifications to tracked files detected on VPS:"
    git status --short
    echo "Safely stashing tracked modifications (untracked .env and secrets are preserved)..."
    git stash push -m "k3s-deploy-stash-$(date +%s)" || true
fi

echo "Fetching commit ${COMMIT_SHA} from origin..."
git fetch origin "${COMMIT_SHA}" --depth=2 2>/dev/null || git fetch origin demo

CURRENT_SHA="$(git rev-parse HEAD)"
if [ "$CURRENT_SHA" != "$COMMIT_SHA" ]; then
    echo "Checking out commit ${COMMIT_SHA}..."
    git checkout "${COMMIT_SHA}"
fi

RESOLVED_SHA="$(git rev-parse HEAD)"
echo "✔ Confirmed checkout on commit ${RESOLVED_SHA}."

# ------------------------------------------------------------------------------
# 4. PRE-FLIGHT CHECKS (Docker, Kubeconfig, Namespace, Resources)
# ------------------------------------------------------------------------------
echo "Running pre-flight environment checks..."

# A. Docker daemon
if ! docker info >/dev/null 2>&1; then
    echo "ERROR: Docker daemon is not running or accessible." >&2
    exit 1
fi

# B. Local Docker registry container on 127.0.0.1:5000
if ! docker inspect registry >/dev/null 2>&1; then
    echo "ERROR: Local registry container 'registry' not found." >&2
    echo "Run deploy/vps/setup.sh to configure the persistent registry." >&2
    exit 1
fi
docker start registry >/dev/null 2>&1 || true

# Verify registry HTTP endpoint
if ! curl -sSf "http://127.0.0.1:5000/v2/" >/dev/null 2>&1; then
    echo "ERROR: Local registry at 127.0.0.1:5000 is not responding." >&2
    exit 1
fi
echo "✔ Local registry 127.0.0.1:5000 is healthy."

# C. Kubeconfig resolution
if [ -n "${KUBECONFIG:-}" ] && [ -r "${KUBECONFIG}" ]; then
    export KUBECONFIG
else
    for candidate in "${HOME}/.kube/config" /home/deploy/.kube/config /etc/rancher/k3s/k3s.yaml; do
        if [ -r "$candidate" ]; then
            export KUBECONFIG="$candidate"
            break
        fi
    done
fi

if [ -z "${KUBECONFIG:-}" ]; then
    echo "ERROR: No readable kubeconfig found." >&2
    exit 1
fi
echo "✔ Using kubeconfig: ${KUBECONFIG}"

# D. Namespace check
if ! kubectl get namespace "${NAMESPACE}" >/dev/null 2>&1; then
    echo "ERROR: Kubernetes namespace '${NAMESPACE}' is inaccessible or missing." >&2
    exit 1
fi
echo "✔ Namespace '${NAMESPACE}' verified."

# E. ConfigMap & Secret check
if ! kubectl -n "${NAMESPACE}" get configmap "${CONFIGMAP_NAME}" >/dev/null 2>&1; then
    echo "ERROR: Required ConfigMap '${CONFIGMAP_NAME}' is missing in '${NAMESPACE}'." >&2
    exit 1
fi
if ! kubectl -n "${NAMESPACE}" get secret "${SECRET_NAME}" >/dev/null 2>&1; then
    echo "ERROR: Required Secret '${SECRET_NAME}' is missing in '${NAMESPACE}'." >&2
    exit 1
fi
echo "✔ ConfigMap and Secret verified for ${SERVICE}."

# F. Target Deployments check
if ! kubectl -n "${NAMESPACE}" get deployment "${DEPLOY_API}" >/dev/null 2>&1; then
    echo "ERROR: Deployment '${DEPLOY_API}' does not exist in '${NAMESPACE}'." >&2
    exit 1
fi
if ! kubectl -n "${NAMESPACE}" get deployment "${DEPLOY_WORKER}" >/dev/null 2>&1; then
    echo "ERROR: Deployment '${DEPLOY_WORKER}' does not exist in '${NAMESPACE}'." >&2
    exit 1
fi
echo "✔ Target Deployments verified for ${SERVICE}."

# ------------------------------------------------------------------------------
# 5. DOCKER BUILD & PUSH (Selected Service Only)
# ------------------------------------------------------------------------------
echo ""
echo ">>> Building Docker image: ${IMAGE_TAG} <<<"
docker build -t "${IMAGE_TAG}" "./${SERVICE_DIR}"
echo ">>> Pushing image to local registry <<<"
docker push "${IMAGE_TAG}"
echo "✔ Successfully built and pushed ${IMAGE_TAG}."

# ------------------------------------------------------------------------------
# 6. RUN DATABASE MIGRATION ONCE (Kubernetes Migration Job)
# ------------------------------------------------------------------------------
# Migrations run once from the new image before deployment rollout.
# NOTE: Migrations must be backward-compatible with running pods.
MIGRATION_JOB_NAME="${SERVICE}-migration-${SHORT_SHA}"
echo ""
echo ">>> Running Database Migrations via Kubernetes Job: ${MIGRATION_JOB_NAME} <<<"

# Clean up any leftover completed/failed job with the same name
kubectl -n "${NAMESPACE}" delete job "${MIGRATION_JOB_NAME}" --ignore-not-found=true >/dev/null 2>&1 || true

cat <<EOF | kubectl -n "${NAMESPACE}" apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: ${MIGRATION_JOB_NAME}
  namespace: ${NAMESPACE}
  labels:
    app.kubernetes.io/name: ${SERVICE}-migration
    app.kubernetes.io/part-of: codebridge
    environment: staging
spec:
  backoffLimit: 1
  ttlSecondsAfterFinished: 600
  template:
    metadata:
      labels:
        app.kubernetes.io/name: ${SERVICE}-migration
    spec:
      restartPolicy: Never
      containers:
        - name: artisan-migrate
          image: ${IMAGE_TAG}
          imagePullPolicy: IfNotPresent
          command:
            - php
            - artisan
            - migrate
            - --force
          envFrom:
            - configMapRef:
                name: ${CONFIGMAP_NAME}
            - secretRef:
                name: ${SECRET_NAME}
EOF

echo "Waiting for migration job ${MIGRATION_JOB_NAME} to complete..."
if ! kubectl -n "${NAMESPACE}" wait --for=condition=complete "job/${MIGRATION_JOB_NAME}" --timeout=180s; then
    echo "ERROR: Migration job failed or timed out. Fetching pod logs:" >&2
    kubectl -n "${NAMESPACE}" logs "job/${MIGRATION_JOB_NAME}" --all-containers=true 2>&1 || true
    exit 1
fi
echo "✔ Database migrations completed successfully."

# ------------------------------------------------------------------------------
# 7. UPDATE K3S DEPLOYMENTS & ROLLOUT
# ------------------------------------------------------------------------------
echo ""
echo ">>> Updating deployment/${DEPLOY_API} container '${CONTAINER_API}' <<<"
kubectl -n "${NAMESPACE}" set image "deployment/${DEPLOY_API}" "${CONTAINER_API}=${IMAGE_TAG}"

echo ">>> Updating deployment/${DEPLOY_WORKER} container '${CONTAINER_WORKER}' <<<"
kubectl -n "${NAMESPACE}" set image "deployment/${DEPLOY_WORKER}" "${CONTAINER_WORKER}=${IMAGE_TAG}"

# Update optional CronJobs if they exist, distinguishing NotFound from RBAC/network failures
update_optional_cronjob() {
    local cj_name="$1"
    local container_name="$2"

    local query_out
    if query_out=$(kubectl -n "${NAMESPACE}" get "cronjob/${cj_name}" 2>&1); then
        echo "Updating cronjob/${cj_name} container '${container_name}' to ${IMAGE_TAG}..."
        kubectl -n "${NAMESPACE}" set image "cronjob/${cj_name}" "${container_name}=${IMAGE_TAG}"
    else
        if [[ "$query_out" =~ "NotFound" ]] || [[ "$query_out" =~ "not found" ]]; then
            echo "Optional cronjob/${cj_name} not found; skipping."
        else
            echo "ERROR: Failure inspecting cronjob/${cj_name}: ${query_out}" >&2
            return 1
        fi
    fi
}

update_optional_cronjob "${CRON_SCHEDULER}" "scheduler"
update_optional_cronjob "${CRON_BACKUP}" "db-backup"

echo "Waiting for rollout of deployment/${DEPLOY_API}..."
kubectl -n "${NAMESPACE}" rollout status "deployment/${DEPLOY_API}" --timeout=180s

echo "Waiting for rollout of deployment/${DEPLOY_WORKER}..."
kubectl -n "${NAMESPACE}" rollout status "deployment/${DEPLOY_WORKER}" --timeout=180s

# ------------------------------------------------------------------------------
# 8. MULTI-REPLICA APPLICATION & DOCUMENTATION CACHE WARMING
# ------------------------------------------------------------------------------
echo ""
echo ">>> Configuring application and documentation caches across all active replicas <<<"
ACTIVE_PODS=()
while IFS= read -r pod_name; do
    [ -n "${pod_name}" ] && ACTIVE_PODS+=("${pod_name}")
done < <(kubectl -n "${NAMESPACE}" get pods -l "app.kubernetes.io/name=${DEPLOY_API}" --field-selector=status.phase=Running -o jsonpath='{range .items[?(!.metadata.deletionTimestamp)]}{.metadata.name}{"\n"}{end}')

if [ ${#ACTIVE_PODS[@]} -eq 0 ]; then
    echo "WARNING: No running pods found for deployment/${DEPLOY_API}."
else
    WARMED_COUNT=0
    for pod in "${ACTIVE_PODS[@]}"; do
        # Verify pod is still present and not terminating
        if ! kubectl -n "${NAMESPACE}" get pod "${pod}" >/dev/null 2>&1; then
            echo "Notice: Pod ${pod} is no longer running, skipping cache warming."
            continue
        fi

        is_terminating=$(kubectl -n "${NAMESPACE}" get pod "${pod}" -o jsonpath='{.metadata.deletionTimestamp}' 2>/dev/null || true)
        if [ -n "${is_terminating}" ]; then
            echo "Notice: Pod ${pod} is terminating, skipping cache warming."
            continue
        fi

        echo "Configuring caches on pod ${pod}..."
        if ! kubectl -n "${NAMESPACE}" exec "${pod}" -c "${CONTAINER_API}" -- php artisan optimize; then
            echo "Warning: Could not optimize cache on pod ${pod} (it may have terminated or restarted)."
            continue
        fi

        # Check if scramble:cache command is registered in this service
        if kubectl -n "${NAMESPACE}" exec "${pod}" -c "${CONTAINER_API}" -- php artisan list 2>/dev/null | grep -q "scramble:cache"; then
            echo "Warming OpenAPI documentation cache on pod ${pod} (scramble:cache)..."
            kubectl -n "${NAMESPACE}" exec "${pod}" -c "${CONTAINER_API}" -- php artisan scramble:cache 2>&1 || true
        fi

        WARMED_COUNT=$((WARMED_COUNT + 1))
    done
    echo "✔ Multi-replica cache warming complete across ${WARMED_COUNT} active pod(s)."
fi

# ------------------------------------------------------------------------------
# 9. DEPLOYMENT SUMMARY
# ------------------------------------------------------------------------------
echo ""
echo "=========================================================================="
echo "✔ Deployment Successful!"
echo "  Service   : ${SERVICE}"
echo "  Commit    : ${COMMIT_SHA}"
echo "  Image     : ${IMAGE_TAG}"
echo "  Deployments: deployment/${DEPLOY_API}, deployment/${DEPLOY_WORKER}"
echo "=========================================================================="
