#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Production Blue-Green Status & Health Inspection
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
STATE_FILE="${SCRIPT_DIR}/.active_slot"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

ACTIVE_SLOT="$(cat "${STATE_FILE}" 2>/dev/null || echo "blue")"

echo -e "\n${BLUE}==============================================================================${NC}"
echo -e "${BLUE}        CodeBridges Production Blue-Green Status Dashboard                    ${NC}"
echo -e "${BLUE}==============================================================================${NC}"

if [ "${ACTIVE_SLOT}" = "blue" ]; then
    echo -e "ACTIVE SLOT IN NPM:  ${BLUE}● BLUE${NC} (Receives public customer traffic)"
    echo -e "INACTIVE STANDBY:    ${GREEN}○ GREEN${NC} (Available for deployment/testing)\n"
else
    echo -e "ACTIVE SLOT IN NPM:  ${GREEN}● GREEN${NC} (Receives public customer traffic)"
    echo -e "INACTIVE STANDBY:    ${BLUE}○ BLUE${NC} (Available for deployment/testing)\n"
fi

echo -e "${YELLOW}--- Container Status for Slot [BLUE] ---${NC}"
docker ps --filter "name=codebridge-prod.*blue" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" || true

echo -e "\n${YELLOW}--- Container Status for Slot [GREEN] ---${NC}"
docker ps --filter "name=codebridge-prod.*green" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" || true

echo -e "\n${YELLOW}--- Shared Data Infrastructure Status ---${NC}"
docker ps --filter "name=codebridge-prod-(auth-mysql|operations-mysql|auth-redis|operations-redis|minio|npm)" --format "table {{.Names}}\t{{.Status}}" || true

echo -e "\n${YELLOW}--- Gateway Health Probes ---${NC}"
BLUE_HEALTH=$(docker exec codebridge-prod-npm curl -s -o /dev/null -w "%{http_code}" "http://codebridge-prod-blue-gateway:80/api/v1/health" 2>/dev/null || echo "DOWN")
GREEN_HEALTH=$(docker exec codebridge-prod-npm curl -s -o /dev/null -w "%{http_code}" "http://codebridge-prod-green-gateway:80/api/v1/health" 2>/dev/null || echo "DOWN")

echo -e "  Blue Gateway Health:  [${BLUE_HEALTH}] (http://codebridge-prod-blue-gateway:80/api/v1/health)"
echo -e "  Green Gateway Health: [${GREEN_HEALTH}] (http://codebridge-prod-green-gateway:80/api/v1/health)"
echo -e "${BLUE}==============================================================================${NC}\n"
