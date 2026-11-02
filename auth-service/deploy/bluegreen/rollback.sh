#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.bluegreen.yml"
STATE_FILE="${SCRIPT_DIR}/.active_color"
NGINX_DIR="${SCRIPT_DIR}/nginx"
source "${SCRIPT_DIR}/traffic.sh"
cd "${ROOT_DIR}"
CURRENT_COLOR="$(cat "${STATE_FILE}")"
case "${CURRENT_COLOR}" in blue) ROLLBACK_COLOR=green ;; green) ROLLBACK_COLOR=blue ;; *) echo 'Invalid active color' >&2; exit 1 ;; esac

# Do not build: retain the previous immutable slot image and its schema compatibility.
compose up -d --no-build "auth-service-${ROLLBACK_COLOR}"
wait_for_application "${ROLLBACK_COLOR}"
switch_traffic "${ROLLBACK_COLOR}" "${CURRENT_COLOR}"
compose stop -t 120 "auth-horizon-${CURRENT_COLOR}"
compose up -d --no-build "auth-horizon-${ROLLBACK_COLOR}"
AUTH_ACTIVE_COLOR="${ROLLBACK_COLOR}" compose up -d --no-deps auth-scheduler
echo "Rollback complete. Active: ${ROLLBACK_COLOR}"
