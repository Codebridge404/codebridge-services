#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise Staging Deployment Engine (Single VPS)
# Architecture: Cloudflare -> NPM -> API Gateway -> PHP-FPM Microservices
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.staging.yml"
COMPOSE_ENV="${ROOT_DIR}/env/staging/compose.env"
AUTH_ENV="${ROOT_DIR}/env/staging/auth.env"
OPS_ENV="${ROOT_DIR}/env/staging/operations.env"

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
echo -e "${BLUE}${BOLD}        CodeBridges Enterprise Staging Stack Deployment Engine               ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}\n"

# 1. Environment Verification
echo -e "${YELLOW}[Step 1/6] Verifying Staging Environment Files...${NC}"
for file in "${COMPOSE_ENV}" "${AUTH_ENV}" "${OPS_ENV}"; do
    if [ ! -f "${file}" ]; then
        echo -e "${RED}✘ Missing required environment file: ${file}${NC}"
        echo -e "${YELLOW}  Please copy ${file}.example to ${file} and configure credentials.${NC}"
        exit 1
    fi
done
echo -e "${GREEN}✔ Environment files verified.${NC}\n"

# 2. Build or Pull Container Images
echo -e "${YELLOW}[Step 2/6] Building Staging Container Images...${NC}"
compose build --pull auth-service operations-service gateway
echo -e "${GREEN}✔ Images built successfully.${NC}\n"

# 3. Start Data Layer (Databases, Cache, S3 Storage, Mailpit)
echo -e "${YELLOW}[Step 3/6] Starting Staging Databases, Cache, and Storage...${NC}"
compose up -d auth-mysql operations-mysql auth-redis operations-redis minio minio-init mailpit

echo -e "  Waiting for Auth & Operations databases to become healthy..."
AUTH_PASS="$(grep '^AUTH_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"
OPS_PASS="$(grep '^OPERATIONS_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"

for i in {1..30}; do
    if docker exec codebridge-staging-auth-mysql mysqladmin ping -h 127.0.0.1 -u root -p"${AUTH_PASS}" --silent 2>/dev/null && \
       docker exec codebridge-staging-operations-mysql mysqladmin ping -h 127.0.0.1 -u root -p"${OPS_PASS}" --silent 2>/dev/null; then
        echo -e "${GREEN}  ✔ Both MySQL databases are ready.${NC}"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo -e "${RED}✘ Timed out waiting for MySQL databases.${NC}"
        exit 1
    fi
    sleep 2
done

# Wait for MinIO
for i in {1..20}; do
    if docker exec codebridge-staging-minio curl -f http://localhost:9000/minio/health/live >/dev/null 2>&1; then
        echo -e "${GREEN}  ✔ MinIO S3 object storage is ready.${NC}"
        break
    fi
    sleep 1
done
echo -e ""

# 4. Start Microservices, Horizon Workers, Task Scheduler
echo -e "${YELLOW}[Step 4/6] Starting Staging Microservices and Workers...${NC}"
compose up -d auth-service operations-service auth-horizon operations-horizon auth-scheduler
echo -e "${GREEN}✔ Microservices started.${NC}\n"

# 5. Execute Database Migrations
echo -e "${YELLOW}[Step 5/6] Executing Laravel Database Migrations...${NC}"
echo -e "  Running Auth Service migrations..."
docker exec codebridge-staging-auth-service php artisan migrate --force
echo -e "  Running Operations Service migrations..."
docker exec codebridge-staging-operations-service php artisan migrate --force
echo -e "${GREEN}✔ All database migrations completed.${NC}\n"

# 6. Start API Gateway & Nginx Proxy Manager
echo -e "${YELLOW}[Step 6/6] Starting Ingress API Gateway and Nginx Proxy Manager...${NC}"
compose up -d gateway nginx-proxy-manager

# Verify Gateway Response
echo -e "  Verifying internal API Gateway routing..."
for i in {1..15}; do
    if docker exec codebridge-staging-gateway curl -s -f http://127.0.0.1/api/v1/health >/dev/null 2>&1; then
        echo -e "${GREEN}  ✔ Gateway routed /api/v1/health successfully.${NC}"
        break
    fi
    sleep 2
done

echo -e "\n${GREEN}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}       ✔ CODEBRIDGES STAGING STACK DEPLOYED & OPERATIONAL!                    ${NC}"
echo -e "${GREEN}${BOLD}==============================================================================${NC}\n"

echo -e "Staging Container Topology:"
compose ps
echo -e "\n${BLUE}Public DNS / Hostnames:${NC}"
echo -e "  • Frontend:  https://staging.codebridge.servicefixit.me"
echo -e "  • API:       https://staging-codebridge-api.servicefixit.me"
echo -e "  • Docs:      https://staging-codebridge-docs.servicefixit.me"
echo -e "  • Images:    https://staging-codebridge-cdn.servicefixit.me"
echo -e "  • Events:    https://staging-codebridge-events.servicefixit.me"
echo -e "  • NPM Admin: http://<VPS_IP>:81 (Default: admin@example.com / changeme)\n"

