#!/usr/bin/env bash
# ==============================================================================
# CodeBridge Operations Service VPS Deployment Script
# ==============================================================================
# Usage:
#   bash operations-service/scripts/deploy.sh <commit-sha>
# ==============================================================================
set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

if [ $# -lt 1 ]; then
    echo "ERROR: Missing commit SHA argument." >&2
    echo "Usage: $0 <commit-sha>" >&2
    exit 1
fi

COMMIT_SHA="$1"
exec bash "${ROOT_DIR}/deploy/vps/k3s-service-deploy.sh" "operations" "${COMMIT_SHA}"
