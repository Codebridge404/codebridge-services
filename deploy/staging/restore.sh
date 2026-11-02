#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise Staging Stack - Automated Restore Engine
# Restores:
#   1. Auth MySQL Database
#   2. Operations MySQL Database
#   3. MinIO S3 Object Storage Data
#   4. Nginx Proxy Manager Configurations & SSL
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_ENV="${ROOT_DIR}/env/staging/compose.env"
BACKUP_BASE="${BACKUP_DIR:-${ROOT_DIR}/backups/staging}"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

# Determine target backup directory (default to latest)
TARGET_DIR="${1:-$(find "${BACKUP_BASE}" -mindepth 1 -maxdepth 1 -type d | sort -r | head -n 1 || true)}"

if [ -z "${TARGET_DIR}" ] || [ ! -d "${TARGET_DIR}" ]; then
    echo -e "${RED}✘ Error: No backup folder found in ${BACKUP_BASE}.${NC}"
    echo -e "Usage: $0 [path/to/backup_folder]"
    exit 1
fi

echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${BLUE}${BOLD}        CodeBridges Staging Restore Engine                                    ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}"
echo -e "▶ Source Directory: ${YELLOW}${TARGET_DIR}${NC}\n"

# Verify Checksums if available
if [ -f "${TARGET_DIR}/SHA256SUMS.txt" ]; then
    echo -e "${YELLOW}Verifying archive SHA256 integrity checksums...${NC}"
    (cd "${TARGET_DIR}" && sha256sum -c SHA256SUMS.txt --quiet)
    echo -e "${GREEN}✔ All backup files passed integrity check.${NC}\n"
fi

AUTH_PASS="$(grep '^AUTH_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"
OPS_PASS="$(grep '^OPERATIONS_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"

# 1. Restore Auth DB
AUTH_SQL="$(find "${TARGET_DIR}" -name "auth_mysql_*.sql.gz" | head -n 1 || true)"
if [ -n "${AUTH_SQL}" ] && [ -f "${AUTH_SQL}" ]; then
    echo -e "${YELLOW}[1/4] Restoring Auth MySQL Database...${NC}"
    gunzip -c "${AUTH_SQL}" | docker exec -i codebridge-staging-auth-mysql mysql -u root -p"${AUTH_PASS}" codebridge_auth_staging
    echo -e "${GREEN}✔ Auth Database restored successfully.${NC}\n"
fi

# 2. Restore Operations DB
OPS_SQL="$(find "${TARGET_DIR}" -name "operations_mysql_*.sql.gz" | head -n 1 || true)"
if [ -n "${OPS_SQL}" ] && [ -f "${OPS_SQL}" ]; then
    echo -e "${YELLOW}[2/4] Restoring Operations MySQL Database...${NC}"
    gunzip -c "${OPS_SQL}" | docker exec -i codebridge-staging-operations-mysql mysql -u root -p"${OPS_PASS}" codebridge_operations_staging
    echo -e "${GREEN}✔ Operations Database restored successfully.${NC}\n"
fi

# 3. Restore MinIO Storage
MINIO_ARCHIVE="$(find "${TARGET_DIR}" -name "minio_data_*.tar.gz" | head -n 1 || true)"
if [ -n "${MINIO_ARCHIVE}" ] && [ -f "${MINIO_ARCHIVE}" ]; then
    echo -e "${YELLOW}[3/4] Restoring MinIO S3 Object Storage...${NC}"
    gunzip -c "${MINIO_ARCHIVE}" | docker cp - codebridge-staging-minio:/
    echo -e "${GREEN}✔ MinIO files restored successfully.${NC}\n"
fi

# 4. Restore NPM Configurations
NPM_ARCHIVE="$(find "${TARGET_DIR}" -name "npm_config_*.tar.gz" | head -n 1 || true)"
if [ -n "${NPM_ARCHIVE}" ] && [ -f "${NPM_ARCHIVE}" ]; then
    echo -e "${YELLOW}[4/4] Restoring Nginx Proxy Manager data...${NC}"
    tar -xzf "${NPM_ARCHIVE}" -C "${ROOT_DIR}/infrastructure/nginx-proxy-manager"
    docker restart codebridge-staging-npm >/dev/null
    echo -e "${GREEN}✔ NPM configuration restored & restarted.${NC}\n"
fi

echo -e "${GREEN}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}        ✔ RESTORE PROCESS COMPLETED SUCCESSFULLY!                             ${NC}"
echo -e "${GREEN}${BOLD}==============================================================================${NC}\n"

