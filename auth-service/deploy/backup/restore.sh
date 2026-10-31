#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Disaster Recovery Restore Script
# Restores: MySQL Database (.sql.gz) or MinIO Media (.tar.gz)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

if [ $# -lt 1 ]; then
    echo "Usage: $0 <path_to_backup_archive.gz>"
    echo "Example: $0 ./storage/daily/mysql_codebridge_auth_20261031_020000.sql.gz"
    echo "Example: $0 ./storage/daily/minio_media_20261031_020000.tar.gz"
    exit 1
fi

ARCHIVE_PATH="$1"

if [ ! -f "${ARCHIVE_PATH}" ]; then
    log_error "File not found: ${ARCHIVE_PATH}"
    exit 1
fi

log_warn "--------------------------------------------------------"
log_warn "WARNING: Initiating Restore from: ${ARCHIVE_PATH}"
log_warn "This will overwrite existing data with the backup contents!"
log_warn "--------------------------------------------------------"

# 1. Database Restore
if [[ "${ARCHIVE_PATH}" == *"mysql"* ]]; then
    MYSQL_CONTAINER="$(docker ps --filter "name=auth-mysql" --format "{{.Names}}" | head -n 1 || true)"
    if [ -z "${MYSQL_CONTAINER}" ]; then
        log_error "MySQL container is not running. Cannot restore."
        exit 1
    fi

    DB_USER="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_USER || echo "codebridge_auth")"
    DB_PASS="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_PASSWORD || echo "")"
    DB_NAME="$(docker exec "${MYSQL_CONTAINER}" printenv MYSQL_DATABASE || echo "codebridge_auth")"

    log_info "Restoring MySQL database [${DB_NAME}] on [${MYSQL_CONTAINER}]..."
    if [ -n "${DB_PASS}" ]; then
        gunzip -c "${ARCHIVE_PATH}" | docker exec -i "${MYSQL_CONTAINER}" mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}"
    else
        gunzip -c "${ARCHIVE_PATH}" | docker exec -i "${MYSQL_CONTAINER}" mysql -uroot "${DB_NAME}"
    fi
    log_success "MySQL database successfully restored from ${ARCHIVE_PATH}!"

# 2. MinIO S3 Media Restore
elif [[ "${ARCHIVE_PATH}" == *"minio"* || "${ARCHIVE_PATH}" == *"media"* ]]; then
    MINIO_CONTAINER="$(docker ps --filter "name=minio" --format "{{.Names}}" | head -n 1 || true)"
    if [ -z "${MINIO_CONTAINER}" ]; then
        log_error "MinIO container is not running. Cannot restore."
        exit 1
    fi

    log_info "Restoring MinIO images/media on [${MINIO_CONTAINER}]..."
    gunzip -c "${ARCHIVE_PATH}" | docker cp - "${MINIO_CONTAINER}:/"
    log_success "MinIO uploaded media successfully restored from ${ARCHIVE_PATH}!"

# 3. Docker Release Image Restore
elif [[ "${ARCHIVE_PATH}" == *"docker_image"* ]]; then
    log_info "Restoring Docker release image from archive..."
    gunzip -c "${ARCHIVE_PATH}" | docker load
    log_success "Docker image successfully restored from ${ARCHIVE_PATH}!"

else
    log_error "Unknown archive type. Filename must contain 'mysql', 'minio/media', or 'docker_image'."
    exit 1
fi
