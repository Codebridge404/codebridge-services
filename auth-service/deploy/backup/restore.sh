#!/usr/bin/env bash
# ==============================================================================
# CodeBridges Auth Service - Disaster Recovery & Integrity Verification Script
# Verifies SHA-256 + gzip integrity before restoring:
#   MySQL Database (.sql.gz), MinIO Media (.tar.gz), or Docker Release Image
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

usage() {
    echo "Usage: $0 [--verify] [--allow-missing-checksum] <path_to_backup_archive.gz>"
    echo "Example: $0 --verify ./storage/daily/mysql_codebridge_auth_20261031_020000.sql.gz"
    echo "Example: $0 ./storage/daily/mysql_codebridge_auth_20261031_020000.sql.gz"
    echo "Example: $0 ./storage/daily/minio_media_20261031_020000.tar.gz"
}

VERIFY_ONLY=false
ALLOW_MISSING_CHECKSUM=false
ARCHIVE_PATH=""

for arg in "$@"; do
    case "${arg}" in
        --verify)
            VERIFY_ONLY=true
            ;;
        --allow-missing-checksum)
            ALLOW_MISSING_CHECKSUM=true
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            if [ -n "${ARCHIVE_PATH}" ]; then
                log_error "Unexpected argument: ${arg}"
                usage
                exit 1
            fi
            ARCHIVE_PATH="${arg}"
            ;;
    esac
done

if [ -z "${ARCHIVE_PATH}" ]; then
    usage
    exit 1
fi

if [ ! -f "${ARCHIVE_PATH}" ]; then
    log_error "File not found: ${ARCHIVE_PATH}"
    exit 1
fi

compute_sha256() {
    local FILE="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "${FILE}" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "${FILE}" | awk '{print $1}'
    else
        log_error "No SHA-256 tool (sha256sum/shasum) available to verify backup integrity."
        return 1
    fi
}

verify_integrity() {
    local FILE="$1"
    local CHECKSUM_FILE="${FILE}.sha256"

    if [ ! -f "${CHECKSUM_FILE}" ]; then
        local ALT_CHECKSUM_FILE="${FILE%.sql.gz.enc}.sha256"
        if [ -f "${ALT_CHECKSUM_FILE}" ]; then
            CHECKSUM_FILE="${ALT_CHECKSUM_FILE}"
        fi
    fi

    if [ -f "${CHECKSUM_FILE}" ]; then
        local EXPECTED_HASH
        local ACTUAL_HASH
        EXPECTED_HASH="$(awk 'NR==1 {print $1}' "${CHECKSUM_FILE}")"
        ACTUAL_HASH="$(compute_sha256 "${FILE}")"
        if [ -z "${EXPECTED_HASH}" ] || [ "${EXPECTED_HASH}" != "${ACTUAL_HASH}" ]; then
            log_error "SHA-256 checksum mismatch for ${FILE}! Expected ${EXPECTED_HASH}, got ${ACTUAL_HASH}."
            return 1
        fi
        log_success "SHA-256 checksum verified: ${ACTUAL_HASH}"
    elif [ "${ALLOW_MISSING_CHECKSUM}" = "true" ] && [ "${VERIFY_ONLY}" != "true" ]; then
        log_warn "Checksum sidecar (${FILE}.sha256) not found; continuing due to --allow-missing-checksum."
    else
        log_error "Missing SHA-256 sidecar (${FILE}.sha256). Refusing unverified restore."
        return 1
    fi

    if [[ "${FILE}" == *.gz ]]; then
        if ! gzip -t "${FILE}" 2>/dev/null; then
            log_error "Gzip integrity check failed for ${FILE}!"
            return 1
        fi
        log_success "Gzip stream integrity verified: ${FILE}"
    fi
}

verify_integrity "${ARCHIVE_PATH}"

if [ "${VERIFY_ONLY}" = "true" ]; then
    log_success "Verification completed cleanly (--verify mode; no data modified)."
    exit 0
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
