#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Instant Rollback Script
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.bluegreen.yml"
STATE_FILE="${SCRIPT_DIR}/.active_color"
NGINX_DIR="${SCRIPT_DIR}/nginx"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

cd "${ROOT_DIR}"

if [ ! -f "${STATE_FILE}" ]; then
    log_error "State file not found. Cannot determine active color."
    exit 1
fi

CURRENT_COLOR="$(cat "${STATE_FILE}")"

if [ "${CURRENT_COLOR}" == "blue" ]; then
    ROLLBACK_COLOR="green"
else
    ROLLBACK_COLOR="blue"
fi

log_warn "Initiating INSTANT ROLLBACK from [${CURRENT_COLOR}] to [${ROLLBACK_COLOR}]..."

# 1. Start the rollback target if not running
log_info "Ensuring [auth-service-${ROLLBACK_COLOR}] is running..."
docker compose -f "${COMPOSE_FILE}" up -d "auth-service-${ROLLBACK_COLOR}" "auth-horizon-${ROLLBACK_COLOR}"

# 2. Verify rollback target application readiness
docker compose -f "${COMPOSE_FILE}" exec -T "auth-service-${ROLLBACK_COLOR}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create("/api/v1/health", "GET"));
exit($response->getStatusCode() === 200 ? 0 : 1);
'

# 3. Swap upstream & stream configs
log_info "Swapping Nginx upstream & Gateway stream back to [${ROLLBACK_COLOR}]..."
cp "${NGINX_DIR}/upstream-${ROLLBACK_COLOR}.conf" "${NGINX_DIR}/active-upstream.conf"
cp "${NGINX_DIR}/stream-${ROLLBACK_COLOR}.conf" "${NGINX_DIR}/active-stream.conf"

# 4. Gracefully reload Nginx
log_info "Reloading Nginx..."
docker compose -f "${COMPOSE_FILE}" exec -T auth-proxy nginx -s reload

# 5. Save state
echo "${ROLLBACK_COLOR}" > "${STATE_FILE}"

# 6. Stop previous faulty container
log_info "Stopping faulty [auth-service-${CURRENT_COLOR}]..."
docker compose -f "${COMPOSE_FILE}" stop "auth-service-${CURRENT_COLOR}" "auth-horizon-${CURRENT_COLOR}" || true

log_success "Rollback successful! Active environment is now: [${ROLLBACK_COLOR}]"

