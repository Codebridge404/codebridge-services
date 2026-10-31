#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 3 ]]; then
  echo 'Usage (repo root): bash deploy/scripts/build.sh auth|operations|gateway ghcr.io/OWNER RELEASE' >&2
  exit 2
fi
service="$1"
registry="$2"
release="$3"
[[ "$service" == auth || "$service" == operations || "$service" == gateway ]] || exit 2
[[ "$release" =~ ^[a-z0-9][a-z0-9-]{0,29}$ ]] || { echo 'Use a lowercase release label, max 30 characters' >&2; exit 2; }
platform="${BUILD_PLATFORM:-linux/amd64}"
if [[ "$service" == gateway ]]; then
  docker buildx build --platform "$platform" --file deploy/docker/gateway.Dockerfile \
    --tag "$registry/codebridge-gateway:$release" --push .
  exit
fi
[[ -f "$service-service/composer.lock" ]] || { echo 'Run from repository root; composer.lock required' >&2; exit 1; }
# Default platform for an x86 VPS when building on Apple Silicon. Override for ARM VPS.
for target in app web; do
  docker buildx build --platform "$platform" \
    --file deploy/docker/Dockerfile --target "$target" \
    --build-arg "SERVICE_DIR=$service-service" \
    --tag "$registry/codebridge-$service-$target:$release" --push .
done
