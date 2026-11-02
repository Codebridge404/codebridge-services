#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Production Rollback Script (Blue-Green)
# Instantly restores previous slot traffic in Nginx Proxy Manager
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.prod.yml"
ENV_FILE="${ROOT_DIR}/env/production/compose.env"
STATE_FILE="${SCRIPT_DIR}/.active_slot"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\1;33m'
RED='\033[0;31m'
NC='\033[0m'

compose() {
    docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

echo -e "\n${RED}==============================================================================${NC}"
echo -e "${RED}             CodeBridges Instant Rollback Initiated                           ${NC}"
echo -e "${RED}==============================================================================${NC}"

CURRENT_SLOT="$(cat "${STATE_FILE}" 2>/dev/null || echo "green")"
case "${CURRENT_SLOT}" in
    green)
        ROLLBACK_SLOT="blue"
        ;;
    blue)
        ROLLBACK_SLOT="green"
        ;;
    *)
        echo -e "${RED}✘ Error: Invalid state in ${STATE_FILE}.${NC}" >&2
        exit 1
        ;;
esac

echo -e "▶ Current Problematic Slot: ${RED}${CURRENT_SLOT^^}${NC}"
echo -e "▶ Restoring Previous Slot:  ${GREEN}${ROLLBACK_SLOT^^}${NC}\n"

# Verify restored slot containers are still running
echo -e "Checking container status for slot [${ROLLBACK_SLOT^^}]..."
compose up -d "auth-service-${ROLLBACK_SLOT}" "operations-service-${ROLLBACK_SLOT}" "gateway-${ROLLBACK_SLOT}" "auth-horizon-${ROLLBACK_SLOT}" "operations-horizon-${ROLLBACK_SLOT}"

# Stop problematic workers
echo -e "Stopping queue workers on [${CURRENT_SLOT^^}]..."
compose stop -t 10 "auth-horizon-${CURRENT_SLOT}" "operations-horizon-${CURRENT_SLOT}" || true

# Update active slot state
echo "${ROLLBACK_SLOT}" > "${STATE_FILE}"

echo -e "\n${GREEN}==============================================================================${NC}"
echo -e "${GREEN}✔ ROLLBACK READY: Previous Slot [${ROLLBACK_SLOT^^}] Restored!${NC}"
echo -e "${GREEN}==============================================================================${NC}"
echo -e "\n${YELLOW}INSTRUCTIONS TO RESTORE TRAFFIC IN NGINX PROXY MANAGER (NPM):${NC}"
echo -e "1. Open NPM Dashboard: ${BLUE}http://localhost:81${NC}"
echo -e "2. Edit Proxy Host:    ${BLUE}codebridge-api.servicefixit.me${NC}"
echo -e "3. Change Forward Hostname back to: ${GREEN}codebridge-prod-${ROLLBACK_SLOT}-gateway${NC}"
echo -e "4. Click Save. Public customer traffic is restored to [${ROLLBACK_SLOT^^}].\n"
