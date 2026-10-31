#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Backup Status & Integrity Health Checker
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_ROOT="${BACKUP_DIR:-${SCRIPT_DIR}/storage}"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}==============================================================================${NC}"
echo -e "${BLUE}           CodeBridges Backup Health & Status Overview                        ${NC}"
echo -e "${BLUE}==============================================================================${NC}"

check_tier() {
    local TIER="$1"
    local DIR="${BACKUP_ROOT}/${TIER}"

    local UPPER_TIER
    UPPER_TIER="$(echo "${TIER}" | tr '[:lower:]' '[:upper:]')"
    echo -e "\n${YELLOW}▶ Tier: [${UPPER_TIER}]${NC} (${DIR})"
    
    if [ ! -d "${DIR}" ] || [ -z "$(ls -A "${DIR}" 2>/dev/null)" ]; then
        echo -e "  ${RED}No backups found in this tier.${NC}"
        return
    fi

    local COUNT
    COUNT="$(find "${DIR}" -type f -name "*.gz" | wc -l | tr -d ' ')"
    local TOTAL_SIZE
    TOTAL_SIZE="$(du -sh "${DIR}" 2>/dev/null | cut -f1)"
    
    echo -e "  Total Files : ${GREEN}${COUNT}${NC} | Total Size: ${GREEN}${TOTAL_SIZE}${NC}"
    
    # Check individual components
    for COMPONENT in "mysql:Database (MySQL)" "minio:Media Storage (MinIO)" "docker_image:Docker Release Image"; do
        local PREFIX="${COMPONENT%%:*}"
        local LABEL="${COMPONENT##*:}"
        local LATEST
        LATEST="$(ls -t "${DIR}"/${PREFIX}*.gz 2>/dev/null | head -n 1 || true)"
        if [ -n "${LATEST}" ]; then
            local LATEST_DATE
            LATEST_DATE="$(stat -f "%Sm" "${LATEST}" 2>/dev/null || stat -c "%y" "${LATEST}" 2>/dev/null || echo "Unknown")"
            local LATEST_SIZE
            LATEST_SIZE="$(du -h "${LATEST}" | cut -f1)"
            local STATUS="${GREEN}PASS (Valid)${NC}"
            if ! gzip -t "${LATEST}" 2>/dev/null; then
                STATUS="${RED}FAIL (Corrupted Archive)${NC}"
            fi
            echo -e "    * [${LABEL}]: $(basename "${LATEST}") (${LATEST_SIZE}, ${LATEST_DATE}) -> ${STATUS}"
        else
            echo -e "    * [${LABEL}]: ${YELLOW}None recorded${NC}"
        fi
    done
}

check_tier "daily"
check_tier "weekly"
check_tier "monthly"

echo -e "\n${YELLOW}▶ Storage Disk Space:${NC}"
df -h "${BACKUP_ROOT}" 2>/dev/null || df -h .

echo -e "\n${BLUE}==============================================================================${NC}"
