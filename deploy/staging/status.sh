#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Staging Health & Status Inspector
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.staging.yml"
COMPOSE_ENV="${ROOT_DIR}/env/staging/compose.env"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

compose() {
    docker compose -p codebridge-staging --env-file "${COMPOSE_ENV}" -f "${COMPOSE_FILE}" "$@"
}

echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${BLUE}${BOLD}             CodeBridges Staging Stack Health & Status                        ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"

echo -e "${YELLOW}▶ Running Staging Containers:${NC}"
compose ps

echo -e "\n${YELLOW}▶ Container Memory & CPU Resource Usage:${NC}"
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}" \
    $(docker ps -q --filter "name=codebridge-staging") 2>/dev/null || echo "No staging containers running."

echo -e "\n${YELLOW}▶ Direct Component Health Checks:${NC}"

# Check Auth Service FPM
if docker exec codebridge-staging-auth-service php -r 'echo "OK";' >/dev/null 2>&1; then
    echo -e "  Auth Service (PHP-FPM):        ${GREEN}✔ HEALTHY${NC}"
else
    echo -e "  Auth Service (PHP-FPM):        ${RED}✘ UNHEALTHY${NC}"
fi

# Check Operations Service FPM
if docker exec codebridge-staging-operations-service php -r 'echo "OK";' >/dev/null 2>&1; then
    echo -e "  Operations Service (PHP-FPM):  ${GREEN}✔ HEALTHY${NC}"
else
    echo -e "  Operations Service (PHP-FPM):  ${RED}✘ UNHEALTHY${NC}"
fi

# Check Gateway Health route
if docker exec codebridge-staging-gateway wget -q -O /dev/null http://127.0.0.1/api/v1/health >/dev/null 2>&1 || \
   docker exec codebridge-staging-gateway curl -s -f http://127.0.0.1/api/v1/health >/dev/null 2>&1; then
    echo -e "  API Gateway Route:             ${GREEN}✔ HEALTHY (/api/v1/health responded 200 OK)${NC}"
else
    echo -e "  API Gateway Route:             ${RED}✘ UNHEALTHY${NC}"
fi

# Check MinIO
if docker exec codebridge-staging-minio curl -s -f http://localhost:9000/minio/health/live >/dev/null 2>&1; then
    echo -e "  MinIO S3 Object Storage:       ${GREEN}✔ HEALTHY${NC}"
else
    echo -e "  MinIO S3 Object Storage:       ${RED}✘ UNHEALTHY${NC}"
fi

# Check Databases
AUTH_PASS="$(grep '^AUTH_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"
OPS_PASS="$(grep '^OPERATIONS_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"

if docker exec codebridge-staging-auth-mysql mysqladmin ping -h 127.0.0.1 -u root -p"${AUTH_PASS}" --silent >/dev/null 2>&1; then
    echo -e "  Auth MySQL Database:           ${GREEN}✔ HEALTHY${NC}"
else
    echo -e "  Auth MySQL Database:           ${RED}✘ UNHEALTHY${NC}"
fi

if docker exec codebridge-staging-operations-mysql mysqladmin ping -h 127.0.0.1 -u root -p"${OPS_PASS}" --silent >/dev/null 2>&1; then
    echo -e "  Operations MySQL Database:     ${GREEN}✔ HEALTHY${NC}"
else
    echo -e "  Operations MySQL Database:     ${RED}✘ UNHEALTHY${NC}"
fi

echo -e "\n${BLUE}==============================================================================${NC}\n"

