#!/usr/bin/env bash
# Explicit stages; no SSH, installation, migrations, or publishing on git push.
set -euo pipefail
stage="${1:?Usage: deploy.sh validate|base|migrate|app|expose CONTEXT [--database-backup-reviewed]}"
context="${2:?Pass the exact kubectl context for the target VPS}"
root="$(cd "$(dirname "$0")/.." && pwd)"
python3 "$root/scripts/render.py"
mapfile -t settings < <(python3 - "$root/config.json" <<'PY'
import json, sys
c=json.load(open(sys.argv[1]))
print(c['namespace'])
print(c['release'])
PY
)
namespace="${settings[0]}"
release="${settings[1]}"
k=(kubectl --context "$context" -n "$namespace")
case "$stage" in
  validate)
    for file in 20-migrations 30-workloads 40-ingress; do
      "${k[@]}" apply --dry-run=server -f "$root/rendered/$file.yaml"
    done
    ;;
  base)
    "${k[@]}" apply -f "$root/rendered/00-namespace.yaml"
    "${k[@]}" apply -f "$root/rendered/10-base.yaml"
    ;;
  migrate)
    [[ "${3:-}" == --database-backup-reviewed ]] || {
      echo 'Review pending migrations and a restorable backup; then pass --database-backup-reviewed.' >&2
      exit 2
    }
    "${k[@]}" apply -f "$root/rendered/20-migrations.yaml"
    for service in auth operations; do
      "${k[@]}" wait --for=condition=complete "job/$service-migrate-$release" --timeout=600s
    done
    ;;
  app)
    for service in auth operations; do
      # Do not start this release against an unverified migration result.
      "${k[@]}" wait --for=condition=complete "job/$service-migrate-$release" --timeout=1s
    done
    "${k[@]}" apply -f "$root/rendered/30-workloads.yaml"
    for deployment in auth-web operations-web gateway auth-worker operations-worker; do
      "${k[@]}" rollout status "deployment/$deployment" --timeout=600s
    done
    ;;
  expose)
    "${k[@]}" get secret gateway-tls >/dev/null
    kubectl --context "$context" get ingressclass traefik >/dev/null
    for deployment in auth-web operations-web gateway; do
      "${k[@]}" rollout status "deployment/$deployment" --timeout=60s
    done
    "${k[@]}" apply -f "$root/rendered/40-ingress.yaml"
    ;;
  *) echo 'Unknown stage' >&2; exit 2 ;;
esac
