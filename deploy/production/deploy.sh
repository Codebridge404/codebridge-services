#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise Blue-Green Deployment Engine (Production)
# Client -> Cloudflare -> NPM -> Active Slot Gateway -> Laravel PHP-FPM
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.prod.yml"
ENV_FILE="${ROOT_DIR}/env/production/compose.env"
STATE_FILE="${SCRIPT_DIR}/.active_slot"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

compose() {
    docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

echo -e "\n${BLUE}==============================================================================${NC}"
echo -e "${BLUE}        CodeBridges Enterprise Blue-Green Deployment Initiated                ${NC}"
echo -e "${BLUE}==============================================================================${NC}"

# 1. Determine Current Active & Target Inactive Slot
CURRENT_SLOT="$(cat "${STATE_FILE}" 2>/dev/null || echo "blue")"
case "${CURRENT_SLOT}" in
    blue)
        TARGET_SLOT="green"
        TARGET_COLOR="${GREEN}"
        ;;
    green)
        TARGET_SLOT="blue"
        TARGET_COLOR="${BLUE}"
        ;;
    *)
        echo -e "${RED}✘ Error: Invalid state in ${STATE_FILE}. Expected 'blue' or 'green'.${NC}" >&2
        exit 1
        ;;
esac

echo -e "▶ Active Slot:    ${YELLOW}${CURRENT_SLOT^^}${NC} (Receiving live customer traffic)"
echo -e "▶ Deploying Slot: ${TARGET_COLOR}${TARGET_SLOT^^}${NC} (Target deployment slot)\n"

# 2. Ensure Shared Persistence Data Services & NPM Are Healthy
echo -e "${YELLOW}[Step 1/7] Ensuring shared production databases, cache, and NPM are healthy...${NC}"
compose up -d auth-mysql operations-mysql auth-redis operations-redis minio minio-init nginx-proxy-manager

# Wait for MySQL databases to be ready
echo -e "  Waiting for Auth & Operations databases..."
for i in {1..30}; do
    if docker exec codebridge-prod-auth-mysql mysqladmin ping -h localhost -u root -p$(grep AUTH_MYSQL_ROOT_PASSWORD "${ENV_FILE}" | cut -d '=' -f2) --silent 2>/dev/null; then
        break
    fi
    sleep 2
done

# 3. Build Target Slot Images
echo -e "\n${YELLOW}[Step 2/7] Building production container images for slot [${TARGET_SLOT^^}]...${NC}"
compose build "gateway-${TARGET_SLOT}" "auth-service-${TARGET_SLOT}" "operations-service-${TARGET_SLOT}" "auth-horizon-${TARGET_SLOT}" "operations-horizon-${TARGET_SLOT}"

# 4. Start Target Slot Application Services (Behind its isolated internal network)
echo -e "\n${YELLOW}[Step 3/7] Starting slot [${TARGET_SLOT^^}] application services...${NC}"
compose up -d "auth-service-${TARGET_SLOT}" "operations-service-${TARGET_SLOT}" "gateway-${TARGET_SLOT}"

# 5. Deep Health Check Verification (Test PHP-FPM and Database Connectivity)
echo -e "\n${YELLOW}[Step 4/7] Performing deep application readiness verification on slot [${TARGET_SLOT^^}]...${NC}"
MAX_RETRIES=20
TARGET_GATEWAY="codebridge-prod-${TARGET_SLOT}-gateway"
READY=false

for ((i=1; i<=MAX_RETRIES; i++)); do
    echo -e "  Probe ${i}/${MAX_RETRIES} on ${TARGET_GATEWAY}..."
    
    # Query /health and /api/v1/health inside the internal proxy network via docker exec on NPM
    HTTP_CODE=$(docker exec codebridge-prod-npm curl -s -o /dev/null -w "%{http_code}" "http://${TARGET_GATEWAY}:80/api/v1/health" 2>/dev/null || echo "000")
    
    if [ "${HTTP_CODE}" = "200" ]; then
        echo -e "  ${GREEN}✔ Slot [${TARGET_SLOT^^}] health check PASSED (HTTP 200 OK)!${NC}"
        READY=true
        break
    fi
    sleep 3
done

if [ "${READY}" != "true" ]; then
    echo -e "\n${RED}✘ Deployment Aborted: Slot [${TARGET_SLOT^^}] failed deep readiness probes.${NC}" >&2
    echo -e "${RED}Traffic remains on live slot [${CURRENT_SLOT^^}]. No customer impact.${NC}" >&2
    exit 1
fi

# 6. Run Additive, Backward-Compatible Database Migrations
echo -e "\n${YELLOW}[Step 5/7] Executing backward-compatible database migrations...${NC}"
compose exec -T "auth-service-${TARGET_SLOT}" php artisan migrate --force
compose exec -T "operations-service-${TARGET_SLOT}" php artisan migrate --force

# Re-verify health after migrations
compose exec -T "auth-service-${TARGET_SLOT}" php artisan about >/dev/null

# 7. Start Target Horizon Queue Workers and Switch Scheduler
echo -e "\n${YELLOW}[Step 6/7] Transitioning background queue workers & scheduler to [${TARGET_SLOT^^}]...${NC}"
compose up -d "auth-horizon-${TARGET_SLOT}" "operations-horizon-${TARGET_SLOT}"

# Gracefully drain old workers (giving active jobs up to 120s to complete)
echo -e "  Gracefully stopping previous workers on [${CURRENT_SLOT^^}]..."
compose stop -t 120 "auth-horizon-${CURRENT_SLOT}" "operations-horizon-${CURRENT_SLOT}" || true

# Recreate scheduler attached to target
compose up -d --no-deps auth-scheduler

# 8. Record New State
echo "${TARGET_SLOT}" > "${STATE_FILE}"

echo -e "\n${GREEN}==============================================================================${NC}"
echo -e "${GREEN}✔ DEPLOYMENT SUCCESSFUL! Target Slot [${TARGET_SLOT^^}] is Ready for Traffic!     ${NC}"
echo -e "${GREEN}==============================================================================${NC}"
echo -e "\n${YELLOW}NEXT STEP: Route Traffic in Nginx Proxy Manager (NPM):${NC}"
echo -e "1. Open NPM Dashboard: ${BLUE}http://localhost:81${NC} (or your server's NPM port)"
echo -e "2. Navigate to: ${BLUE}Hosts -> Proxy Hosts${NC}"
echo -e "3. Edit host:   ${BLUE}codebridge-api.servicefixit.me${NC}"
echo -e "4. Change Forward Hostname to: ${GREEN}codebridge-prod-${TARGET_SLOT}-gateway${NC}"
echo -e "5. Save changes."
echo -e "\n${YELLOW}Rollback Note:${NC} If any issue arises, run ${BLUE}./deploy/production/rollback.sh${NC} to switch back to ${CURRENT_SLOT^^}.\n"
