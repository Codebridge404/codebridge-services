#!/usr/bin/env bash
set -euo pipefail
service="${1:?Usage: apply-secrets.sh auth|operations CONTEXT [namespace]}"
context="${2:?Pass the target kubectl context}"
namespace="${3:-codebridge}"
[[ "$service" == auth || "$service" == operations ]] || exit 2
root="$(cd "$(dirname "$0")/.." && pwd)"
file="$root/secrets/$service.env"
[[ -f "$file" ]] || { echo "Missing $file" >&2; exit 1; }
if grep -q 'REPLACE_WITH_' "$file"; then
  echo 'Fill all placeholder values before creating the Secret.' >&2
  exit 1
fi
chmod 600 "$file"
# No values appear in arguments or output. Never enable shell tracing here.
kubectl --context "$context" -n "$namespace" create secret generic "$service-env" \
  --from-env-file="$file" --dry-run=client -o yaml | kubectl --context "$context" apply -f -
