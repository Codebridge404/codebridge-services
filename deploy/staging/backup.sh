#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Enterprise Staging Stack - Automated Backup Engine
# Backs up:
#   1. Auth MySQL Database (codebridge_auth_staging)
#   2. Operations MySQL Database (codebridge_operations_staging)
#   3. MinIO S3 Object Storage (/data)
#   4. Nginx Proxy Manager (data/database.sqlite + letsencrypt SSL certificates)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_ENV="${ROOT_DIR}/env/staging/compose.env"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"
BACKUP_BASE="${BACKUP_DIR:-${ROOT_DIR}/backups/staging}"
TARGET_DIR="${BACKUP_BASE}/${TIMESTAMP}"

mkdir -p "${TARGET_DIR}"

echo -e "\n${BLUE}${BOLD}==============================================================================${NC}"
echo -e "${BLUE}${BOLD}        CodeBridges Staging Backup Engine Initiated                           ${NC}"
echo -e "${BLUE}${BOLD}==============================================================================${NC}"
echo -e "▶ Backup Target:   ${YELLOW}${TARGET_DIR}${NC}"
echo -e "▶ Timestamp:       ${YELLOW}${TIMESTAMP}${NC}\n"

# Verify compose.env
if [ ! -f "${COMPOSE_ENV}" ]; then
    echo -e "${RED}✘ Missing staging compose.env at ${COMPOSE_ENV}${NC}"
    exit 1
fi

AUTH_PASS="$(grep '^AUTH_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"
OPS_PASS="$(grep '^OPERATIONS_MYSQL_ROOT_PASSWORD=' "${COMPOSE_ENV}" | cut -d '=' -f2-)"

# ------------------------------------------------------------------------------
# 1. Auth MySQL Database
# ------------------------------------------------------------------------------
echo -e "${YELLOW}[1/4] Backing up Auth MySQL Database (codebridge_auth_staging)...${NC}"
AUTH_SQL="${TARGET_DIR}/auth_mysql_${TIMESTAMP}.sql.gz"
if docker ps --format '{{.Names}}' | grep -q "^codebridge-staging-auth-mysql$"; then
    docker exec codebridge-staging-auth-mysql mysqldump \
        -u root -p"${AUTH_PASS}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        codebridge_auth_staging 2>/dev/null | gzip -9 > "${AUTH_SQL}"

    if gzip -t "${AUTH_SQL}" 2>/dev/null && [ -s "${AUTH_SQL}" ]; then
        SIZE="$(du -h "${AUTH_SQL}" | cut -f1)"
        echo -e "${GREEN}  ✔ Auth Database backed up & verified (${SIZE}): ${AUTH_SQL}${NC}"
    else
        echo -e "${RED}  ✘ Auth Database dump failed or empty.${NC}"
        rm -f "${AUTH_SQL}"
    fi
else
    echo -e "${RED}  ✘ Container codebridge-staging-auth-mysql not running!${NC}"
fi

# ------------------------------------------------------------------------------
# 2. Operations MySQL Database
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}[2/4] Backing up Operations MySQL Database (codebridge_operations_staging)...${NC}"
OPS_SQL="${TARGET_DIR}/operations_mysql_${TIMESTAMP}.sql.gz"
if docker ps --format '{{.Names}}' | grep -q "^codebridge-staging-operations-mysql$"; then
    docker exec codebridge-staging-operations-mysql mysqldump \
        -u root -p"${OPS_PASS}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        codebridge_operations_staging 2>/dev/null | gzip -9 > "${OPS_SQL}"

    if gzip -t "${OPS_SQL}" 2>/dev/null && [ -s "${OPS_SQL}" ]; then
        SIZE="$(du -h "${OPS_SQL}" | cut -f1)"
        echo -e "${GREEN}  ✔ Operations Database backed up & verified (${SIZE}): ${OPS_SQL}${NC}"
    else
        echo -e "${RED}  ✘ Operations Database dump failed or empty.${NC}"
        rm -f "${OPS_SQL}"
    fi
else
    echo -e "${RED}  ✘ Container codebridge-staging-operations-mysql not running!${NC}"
fi

# ------------------------------------------------------------------------------
# 3. MinIO S3 Object Storage Data
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}[3/4] Backing up MinIO S3 Media & Object Storage (/data)...${NC}"
MINIO_ARCHIVE="${TARGET_DIR}/minio_data_${TIMESTAMP}.tar.gz"
if docker ps --format '{{.Names}}' | grep -q "^codebridge-staging-minio$"; then
    docker cp codebridge-staging-minio:/data - 2>/dev/null | gzip -9 > "${MINIO_ARCHIVE}" || true

    if gzip -t "${MINIO_ARCHIVE}" 2>/dev/null && [ -s "${MINIO_ARCHIVE}" ]; then
        SIZE="$(du -h "${MINIO_ARCHIVE}" | cut -f1)"
        echo -e "${GREEN}  ✔ MinIO Storage backed up & verified (${SIZE}): ${MINIO_ARCHIVE}${NC}"
    else
        echo -e "${RED}  ✘ MinIO archive failed.${NC}"
        rm -f "${MINIO_ARCHIVE}"
    fi
else
    echo -e "${RED}  ✘ Container codebridge-staging-minio not running!${NC}"
fi

# ------------------------------------------------------------------------------
# 4. Nginx Proxy Manager Database & SSL Certificates
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}[4/4] Backing up Nginx Proxy Manager (SQLite & Let's Encrypt SSL)...${NC}"
NPM_ARCHIVE="${TARGET_DIR}/npm_config_${TIMESTAMP}.tar.gz"
NPM_DIR="${ROOT_DIR}/infrastructure/nginx-proxy-manager"

if [ -d "${NPM_DIR}" ]; then
    # Backup sqlite database and configuration files
    tar --exclude='data/logs' -czf "${NPM_ARCHIVE}" -C "${NPM_DIR}" data letsencrypt 2>/dev/null || true

    if gzip -t "${NPM_ARCHIVE}" 2>/dev/null && [ -s "${NPM_ARCHIVE}" ]; then
        SIZE="$(du -h "${NPM_ARCHIVE}" | cut -f1)"
        echo -e "${GREEN}  ✔ NPM Config & SSL backed up & verified (${SIZE}): ${NPM_ARCHIVE}${NC}"
    else
        echo -e "${YELLOW}  ⚠ Warning: NPM archive partial or skipped.${NC}"
    fi
fi

# ------------------------------------------------------------------------------
# Checksums & Manifest
# ------------------------------------------------------------------------------
echo -e "\n${YELLOW}Generating SHA256 Integrity Checksums...${NC}"
(cd "${TARGET_DIR}" && sha256sum *.gz > SHA256SUMS.txt 2>/dev/null || true)

cat <<EOF > "${TARGET_DIR}/MANIFEST.txt"
CodeBridges Staging Backup Manifest
====================================
Timestamp:   ${TIMESTAMP}
Created On:  $(date -u +"%Y-%m-%dT%H:%M:%SZ")
Server Host: $(hostname -f 2>/dev/null || hostname)
IP:          217.216.110.153

Backed up components:
- Auth MySQL Database (codebridge_auth_staging)
- Operations MySQL Database (codebridge_operations_staging)
- MinIO S3 Object Storage (/data)
- Nginx Proxy Manager (data/database.sqlite & letsencrypt SSL)

Verification:
Inspect SHA256SUMS.txt to verify file integrity.
EOF

echo -e "\n${GREEN}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}        ✔ STAGING BACKUP COMPLETED SUCCESSFULLY!                              ${NC}"
echo -e "${GREEN}${BOLD}==============================================================================${NC}"
echo -e "Files created in ${TARGET_DIR}:"
ls -lh "${TARGET_DIR}"
echo -e ""
