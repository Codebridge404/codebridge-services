#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Automated Backup System
# Backs up: MySQL Database, MinIO Images/Avatars, and Docker Release Images
# Supports: daily (7-day retention), weekly (4-week retention), monthly (12-month retention)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Configuration
BACKUP_TYPE="${1:-daily}"
TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"
BACKUP_ROOT="${BACKUP_DIR:-${SCRIPT_DIR}/storage}"
TARGET_DIR="${BACKUP_ROOT}/${BACKUP_TYPE}"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

mkdir -p "${TARGET_DIR}"

log_info "--------------------------------------------------------"
log_info "Starting [${BACKUP_TYPE}] backup: ${TIMESTAMP}"
log_info "Destination: ${TARGET_DIR}"
log_info "--------------------------------------------------------"

# 1. Locate Docker Containers
MYSQL_CONTAINER="$(docker ps --filter "name=auth-mysql" --format "{{.Names}}" | head -n 1 || true)"
MINIO_CONTAINER="$(docker ps --filter "name=minio" --format "{{.Names}}" | head -n 1 || true)"

# ------------------------------------------------------------------------------
# 2. Database Backup (MySQL 8.4)
# ------------------------------------------------------------------------------
if [ -n "${MYSQL_CONTAINER}" ]; then
    log_info "Backing up MySQL database from [${MYSQL_CONTAINER}]..."
    SQL_FILE="${TARGET_DIR}/mysql_codebridge_auth_${TIMESTAMP}.sql.gz"
    
    # Read DB credentials from .env or container environment
    DB_USER="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_USER || echo "codebridge_auth")"
    DB_PASS="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_PASSWORD || echo "")"
    DB_NAME="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_DATABASE || echo "codebridge_auth")"

    if [ -n "${DB_PASS}" ]; then
        docker exec "${MYSQL_CONTAINER}" mysqldump \
            --user="${DB_USER}" \
            --password="${DB_PASS}" \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --no-tablespaces \
            "${DB_NAME}" 2>/dev/null | gzip -9 > "${SQL_FILE}"
    else
        docker exec "${MYSQL_CONTAINER}" mysqldump \
            --user=root \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --no-tablespaces \
            "${DB_NAME}" 2>/dev/null | gzip -9 > "${SQL_FILE}"
    fi

    # Verify integrity
    if gzip -t "${SQL_FILE}" 2>/dev/null; then
        FILE_SIZE="$(du -h "${SQL_FILE}" | cut -f1)"
        log_success "MySQL database backup created & verified (${FILE_SIZE}): ${SQL_FILE}"
    else
        log_error "MySQL backup integrity check failed!"
        rm -f "${SQL_FILE}"
    fi
else
    log_warn "MySQL container not found. Skipping database backup."
fi

# ------------------------------------------------------------------------------
# 3. Media & Uploaded Images Backup (MinIO S3 Avatars)
# ------------------------------------------------------------------------------
if [ -n "${MINIO_CONTAINER}" ]; then
    log_info "Backing up MinIO S3 uploaded images/avatars from [${MINIO_CONTAINER}]..."
    MEDIA_FILE="${TARGET_DIR}/minio_media_${TIMESTAMP}.tar.gz"

    # Stream container data directory as tar archive via Docker daemon and gzip
    docker cp "${MINIO_CONTAINER}:/data" - 2>/dev/null | gzip -9 > "${MEDIA_FILE}" || true

    if gzip -t "${MEDIA_FILE}" 2>/dev/null && [ -s "${MEDIA_FILE}" ]; then
        FILE_SIZE="$(du -h "${MEDIA_FILE}" | cut -f1)"
        log_success "MinIO images backup created & verified (${FILE_SIZE}): ${MEDIA_FILE}"
    else
        log_warn "MinIO archive empty or failed. Removing partial file."
        rm -f "${MEDIA_FILE}"
    fi
else
    log_warn "MinIO container not found. Skipping image storage backup."
fi

# ------------------------------------------------------------------------------
# 4. Docker Release Image Snapshot
# ------------------------------------------------------------------------------
ACTIVE_COLOR="blue"
if [ -f "${ROOT_DIR}/deploy/bluegreen/.active_color" ]; then
    ACTIVE_COLOR="$(cat "${ROOT_DIR}/deploy/bluegreen/.active_color")"
fi

IMAGE_NAME="codebridge-auth-service:${ACTIVE_COLOR}"
if ! docker images --format "{{.Repository}}:{{.Tag}}" | grep -q "^${IMAGE_NAME}$"; then
    if docker images --format "{{.Repository}}:{{.Tag}}" | grep -q "^codebridge-auth-service:latest$"; then
        IMAGE_NAME="codebridge-auth-service:latest"
    fi
fi

if docker images --format "{{.Repository}}:{{.Tag}}" | grep -q "^${IMAGE_NAME}$"; then
    log_info "Snapshotting active release Docker image [${IMAGE_NAME}]..."
    IMAGE_FILE="${TARGET_DIR}/docker_image_$(echo "${IMAGE_NAME}" | tr ':/-' '_')_${TIMESTAMP}.tar.gz"
    
    docker save "${IMAGE_NAME}" | gzip -9 > "${IMAGE_FILE}"
    if gzip -t "${IMAGE_FILE}" 2>/dev/null; then
        FILE_SIZE="$(du -h "${IMAGE_FILE}" | cut -f1)"
        log_success "Docker image snapshot created & verified (${FILE_SIZE}): ${IMAGE_FILE}"
    fi
else
    log_info "Release image [${IMAGE_NAME}] not found locally. Skipping image snapshot."
fi

# ------------------------------------------------------------------------------
# 5. Backup Retention & Rotation (Grandfather-Father-Son Policy)
# ------------------------------------------------------------------------------
log_info "Applying retention cleanup for [${BACKUP_TYPE}] backups..."
case "${BACKUP_TYPE}" in
    daily)
        # Keep last 7 days
        find "${TARGET_DIR}" -type f -mtime +7 -name "*.gz" -delete
        log_success "Retention enforced: Daily backups older than 7 days pruned."
        ;;
    weekly)
        # Keep last 4 weeks (28 days)
        find "${TARGET_DIR}" -type f -mtime +28 -name "*.gz" -delete
        log_success "Retention enforced: Weekly backups older than 28 days pruned."
        ;;
    monthly)
        # Keep last 12 months (365 days)
        find "${TARGET_DIR}" -type f -mtime +365 -name "*.gz" -delete
        log_success "Retention enforced: Monthly backups older than 365 days pruned."
        ;;
esac

log_info "--------------------------------------------------------"
log_success "Backup process finished successfully!"
log_info "To check status at any time, run: ./status.sh"
log_info "--------------------------------------------------------"
