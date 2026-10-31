#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Zero-Downtime Blue/Green Deployment Script
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.bluegreen.yml"
STATE_FILE="${SCRIPT_DIR}/.active_color"
NGINX_DIR="${SCRIPT_DIR}/nginx"

# Color helpers for terminal output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

cd "${ROOT_DIR}"

# 1. Determine currently active color and idle target color
if [ -f "${STATE_FILE}" ]; then
    CURRENT_COLOR="$(cat "${STATE_FILE}")"
else
    CURRENT_COLOR="blue"
fi

if [ "${CURRENT_COLOR}" == "blue" ]; then
    TARGET_COLOR="green"
else
    TARGET_COLOR="blue"
fi

log_info "--------------------------------------------------------"
log_info "Starting Blue-Green Zero-Downtime Deployment"
log_info "Currently Active: ${CURRENT_COLOR}"
log_info "Target for Deploy: ${TARGET_COLOR}"
log_info "--------------------------------------------------------"

# 2. Ensure shared stateful dependencies are up (MySQL, Redis, MinIO)
log_info "Ensuring shared infrastructure (MySQL, Redis, MinIO) is healthy..."
docker compose -f "${COMPOSE_FILE}" up -d auth-mysql auth-redis minio minio-init

# 3. Build or update target color images
log_info "Building new target release container [auth-service-${TARGET_COLOR}]..."
docker compose -f "${COMPOSE_FILE}" build "auth-service-${TARGET_COLOR}" "auth-horizon-${TARGET_COLOR}"

# 4. Start the target color containers
log_info "Starting target containers [auth-service-${TARGET_COLOR}] and [auth-horizon-${TARGET_COLOR}]..."
docker compose -f "${COMPOSE_FILE}" up -d "auth-service-${TARGET_COLOR}" "auth-horizon-${TARGET_COLOR}"

# 5. Application-Level Health Check Verification on the new container
log_info "Verifying application readiness of [auth-service-${TARGET_COLOR}] via /api/v1/health..."
MAX_RETRIES=25
RETRY_COUNT=0
HEALTHY=false

APP_PROBE_SCRIPT='
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create("/api/v1/health", "GET");
$response = $kernel->handle($request);
$content = (string) $response->getContent();
if ($response->getStatusCode() === 200 && str_contains($content, "healthy")) {
    echo "APPLICATION_READY_200";
    exit(0);
}
echo "UNHEALTHY_" . $response->getStatusCode() . "_" . $content;
exit(1);
'

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    RETRY_COUNT=$((RETRY_COUNT + 1))
    
    # 1. Verify PHP-FPM process is running inside target container
    FPM_RUNNING=$(docker compose -f "${COMPOSE_FILE}" exec -T "auth-service-${TARGET_COLOR}" pgrep -f "php-fpm: master" 2>/dev/null || true)
    
    # 2. Probe full Laravel HTTP Kernel and database/cache connectivity via /api/v1/health
    PROBE_OUTPUT=$(docker compose -f "${COMPOSE_FILE}" exec -T "auth-service-${TARGET_COLOR}" php -r "${APP_PROBE_SCRIPT}" 2>/dev/null || true)
    
    if [ -n "${FPM_RUNNING}" ] && echo "${PROBE_OUTPUT}" | grep -q "APPLICATION_READY_200"; then
        log_success "Application health check PASSED (PHP-FPM active, HTTP /api/v1/health returned 200 healthy) on attempt ${RETRY_COUNT}/${MAX_RETRIES}."
        HEALTHY=true
        break
    fi
    
    log_warn "Waiting for application to boot and pass health probes... (Attempt ${RETRY_COUNT}/${MAX_RETRIES})"
    sleep 2
done

if [ "${HEALTHY}" != "true" ]; then
    log_error "Health check FAILED for auth-service-${TARGET_COLOR}!"
    log_error "Aborting deployment without switching traffic. Current active remains: ${CURRENT_COLOR}"
    docker compose -f "${COMPOSE_FILE}" stop "auth-service-${TARGET_COLOR}" "auth-horizon-${TARGET_COLOR}" || true
    exit 1
fi

# 6. Run pending database migrations safely on target
log_info "Executing pending database migrations via [auth-service-${TARGET_COLOR}]..."
docker compose -f "${COMPOSE_FILE}" exec -T "auth-service-${TARGET_COLOR}" php artisan migrate --force

# 7. Start Nginx proxy if not already running
if ! docker compose -f "${COMPOSE_FILE}" ps --services --filter "status=running" | grep -q "auth-proxy"; then
    log_info "Starting auth-proxy..."
    docker compose -f "${COMPOSE_FILE}" up -d auth-proxy
fi

# 8. Atomically switch Nginx upstream & Gateway stream to the target color
log_info "Atomically swapping Nginx HTTP & Gateway Stream to [${TARGET_COLOR}]..."
cp "${NGINX_DIR}/upstream-${TARGET_COLOR}.conf" "${NGINX_DIR}/active-upstream.conf"
cp "${NGINX_DIR}/stream-${TARGET_COLOR}.conf" "${NGINX_DIR}/active-stream.conf"

# 9. Gracefully reload Nginx (zero dropped requests)
log_info "Reloading Nginx proxy configuration..."
docker compose -f "${COMPOSE_FILE}" exec -T auth-proxy nginx -s reload

# 10. Record new active state
echo "${TARGET_COLOR}" > "${STATE_FILE}"
log_success "Traffic successfully switched to [${TARGET_COLOR}]!"

# 11. Gracefully stop previous idle color
log_info "Gracefully stopping previous container [auth-service-${CURRENT_COLOR}]..."
docker compose -f "${COMPOSE_FILE}" stop "auth-service-${CURRENT_COLOR}" "auth-horizon-${CURRENT_COLOR}" || true

log_info "--------------------------------------------------------"
log_success "Deployment Complete! Active: [${TARGET_COLOR}]"
log_info "To rollback instantly to [${CURRENT_COLOR}], run: ./deploy/bluegreen/rollback.sh"
log_info "--------------------------------------------------------"

