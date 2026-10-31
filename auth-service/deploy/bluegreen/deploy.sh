#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.bluegreen.yml"
STATE_FILE="${SCRIPT_DIR}/.active_color"
NGINX_DIR="${SCRIPT_DIR}/nginx"
source "${SCRIPT_DIR}/traffic.sh"
cd "${ROOT_DIR}"

CURRENT_COLOR="$(cat "${STATE_FILE}" 2>/dev/null || echo blue)"
case "${CURRENT_COLOR}" in blue) TARGET_COLOR=green ;; green) TARGET_COLOR=blue ;; *) echo 'Invalid active color' >&2; exit 1 ;; esac

echo "Deploying ${TARGET_COLOR}; current slot is ${CURRENT_COLOR}."
compose up -d auth-mysql auth-redis minio minio-init
compose build "auth-service-${TARGET_COLOR}" "auth-horizon-${TARGET_COLOR}"
compose up -d "auth-service-${TARGET_COLOR}"
if ! wait_for_application "${TARGET_COLOR}"; then
    echo 'Application readiness failed; traffic has not changed.' >&2
    exit 1
fi
# Migrations must be additive and compatible with the current live slot.
compose exec -T "auth-service-${TARGET_COLOR}" php artisan migrate --force
wait_for_application "${TARGET_COLOR}"
# Start the proxy without bringing up its default blue dependency.
compose up -d --no-deps auth-proxy
switch_traffic "${TARGET_COLOR}" "${CURRENT_COLOR}"
# Run exactly one scheduler and one release's queue workers after schema migration.
compose stop -t 120 "auth-horizon-${CURRENT_COLOR}"
compose up -d "auth-horizon-${TARGET_COLOR}"
AUTH_ACTIVE_COLOR="${TARGET_COLOR}" compose up -d --no-deps auth-scheduler

echo "Deployment complete. Active: ${TARGET_COLOR}. Rollback: ./deploy/bluegreen/rollback.sh"
