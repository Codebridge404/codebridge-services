# Deploy CodeBridge to one Docker-equipped VPS with K3s

This deployment is additive to the existing Compose setup. It builds production images for Auth, Operations and the existing API gateway. It does not provision a VPS, replace data containers, import uploads, migrate a database or switch live traffic automatically. Follow the stages below on the target VPS after checking its actual configuration.

## Architecture and what changes

| Component | Deployment |
| --- | --- |
| Public HTTPS API | One host, Traefik → gateway → original FastCGI routes |
| Auth / Operations | Two web Pods each, with PHP-FPM and an Nginx health-check sidecar |
| Gateway routing | Existing `gateway/services/*.conf` and `/v1` aliases preserved |
| Auth / Operations worker | One `queue:work` replica when explicitly activated |
| Scheduler | One CronJob per service, initially suspended |
| Database migrations | One explicit Job per service and release; no automatic seeding |
| Files | Separate local-path PVC per service for `storage/app` |
| MySQL / Redis / MinIO | Existing Docker/external services, with private Pod-reachable endpoints |

Docker builds images. K3s uses bundled containerd by default; Docker Engine remains installed. Test coexistence, firewall forwarding and overlapping subnets on the actual VPS. Two replicas on one VPS help rolling updates but cannot survive loss of that VPS. This is not blue-green deployment or multi-node HA.

The `deploy/docker/gateway.Dockerfile` derives its configuration from the repository's gateway at build time. It changes only non-root runtime paths, the listen port, Docker-only DNS, trusted proxy configuration and the forwarded HTTPS parameter. The existing route modules, API rate limits and docs mappings remain. Those existing controls are not a complete application security audit.

## 1. Inspect the VPS without changing it

From this repository on the VPS:

```bash
bash deploy/scripts/preflight.sh
```

Use sudo only if your account needs it to read Docker status. This prints OS, CPU architecture, memory, disk, listening ports and Docker subnets; it does not print container environments or passwords. Confirm whether the app/data services are already running, and which process owns 80/443. Check Docker/VPN subnets against K3s defaults: Pods `10.42.0.0/16`, Services `10.43.0.0/16`.

Plan enough RAM/CPU for both databases, Redis, the cluster and an extra rolling-update Pod. Requests/limits in the renderer are starting values, not a capacity promise. Inspect memory use and adjust PHP-FPM `pm.max_children` for your real request workload. Existing KDS SSE streams last up to 300 seconds; the FPM request timeout allows that window. Clients must reconnect when a rolling update ends an old stream.

The supplied new-cluster configuration disables Traefik and ServiceLB initially, so installation does not claim existing public ports. It does not alter the current Docker services or firewall. Keep SSH restricted to your admin IP/VPN; keep 6443, 10250, databases, Redis and admin consoles private. Review Docker's iptables rules as well as your provider/host firewall. Never blindly flush firewall rules or run `docker compose down -v`.

## 2. Set release configuration and credentials

Run from repository root:

```bash
cp deploy/config.example.json deploy/config.json
cp deploy/secrets/auth.env.example deploy/secrets/auth.env
cp deploy/secrets/operations.env.example deploy/secrets/operations.env
chmod 600 deploy/secrets/*.env
```

Edit `config.json`: your API host, registry image names, unique release tag, storage size and actual Traefik source CIDRs. Keep `activate_background: false` for initial tests. Both services are required by the existing gateway. Use lowercase image repositories and matching image versions. Rendered files/config and credential files are gitignored.

For Secrets use literal `KEY=value` lines, without shell quotes or `export`. Never source these files. Retain each service's existing APP_KEY and the existing shared JWT_SECRET. Generating a new APP_KEY for an existing application can make encrypted data unreadable; changing JWT_SECRET invalidates current tokens. Fresh application only: generate a separate APP_KEY for each service using `openssl rand -base64 32`, prefixed with `base64:`, and one shared JWT_SECRET using `openssl rand -hex 32`.

Set DB_HOST/port/database/app-user/password and Redis endpoint/password. Operations also has an existing `auth_db` connection; configure its `AUTH_DB_*` credentials with the permissions the application needs. No root DB account should be used for routine application traffic. Add other real settings needed by enabled features: SMTP, CORS/Sanctum domains, MinIO/S3, service URLs, etc. Example files are not an exhaustive application environment inventory.

The ConfigMap provides `AUTH_SERVICE_URL=http://auth-web/api/v1`, an HTTP endpoint for Operations' AuthServiceClient. It never sends HTTP to PHP-FPM port 9000. Secret entries override ConfigMap entries. Preserve existing Redis DBs, queue names and prefixes before migrating live jobs/sessions; changing prefixes silently selects a new queue/cache. The example retains DB 0/1 for each service's separate Redis instance. For a shared Redis instance, allocate distinct DBs/prefixes as appropriate.

`REDIS_QUEUE_RETRY_AFTER=120` exceeds worker `--timeout=60`. Review job-specific timeouts and queue names. Workers consume `default`; change `--queue` if your code uses more queues. Preserve existing Redis persistence/backups for durable business jobs.

## 3. Establish private data connectivity

`localhost` inside an ordinary Pod means that Pod, not the VPS. Docker Compose service names are not automatically Kubernetes DNS names. This repository's Auth MySQL/Redis ports are bound to host loopback; those bindings cannot be used directly by Pods. Operations' current Compose file also contains published ports/default credentials that require review before production.

For data remaining on Docker, configure stable private endpoints reachable from the K3s Pod network. A typical arrangement publishes the data service only on the VPS's actual private interface, with access limited to verified cluster traffic sources. Match Docker's published host port in the Secret (for example 3307/3308), not necessarily the container's internal 3306. Determine whether traffic is SNATed to the node address before setting firewall rules.

Do not bind a database to a public interface to work around connectivity. Do not hardcode ephemeral Docker container IPs. A VPS with no private interface needs an explicitly designed private network/bridge or another private data endpoint. Keep volume names and existing credentials unchanged while arranging connectivity; changing Compose env does not reset existing MySQL users. This kit does not guess or rewrite live Compose networking.

If only Docker is installed and no data services exist yet, provision the two MySQL databases/users, Redis instances and any required object storage privately first. Do not start the development Compose stack with its defaults as a substitute for that configuration.

## 4. Build and publish images

Authenticate to your registry using its normal secure login flow, then on the build machine:

```bash
bash deploy/scripts/build.sh auth ghcr.io/YOUR_ORG r20261031-1
bash deploy/scripts/build.sh operations ghcr.io/YOUR_ORG r20261031-1
bash deploy/scripts/build.sh gateway ghcr.io/YOUR_ORG r20261031-1
```

Use the actual lowercase organization. The default target is `linux/amd64` for an x86 VPS, including when building on an Apple Silicon Mac. Set `BUILD_PLATFORM=linux/arm64` for an ARM VPS. Match all five image references in config.json. Use immutable tags; pin registry digests and approved base-image digests for repeatable production releases. The Dockerfiles exclude `.env`, credentials, local vendor/data and compiled caches; Composer installs from lockfiles with `--no-dev` and checks PHP extension requirements. No migrations run during image build or Pod startup. These are API images; add a frontend build stage if serving Vite/Blade assets is required.

Alternatively, after the workflow is merged into the default branch, manually run **Publish VPS images (manual)** in GitHub Actions for the reviewed commit. It publishes amd64 images to `ghcr.io/<lowercase-owner>/codebridge-*` with `sha-<first-12-commit-characters>` tags. Organization package permissions may need to allow the workflow's GITHUB_TOKEN to publish. This workflow does not SSH to or deploy on the VPS. PR image-build checks should pass first.

## 5. Install K3s on a new cluster only

Skip this installation if K3s/Kubernetes already exists. Inspect and merge existing settings rather than overwriting a cluster configuration. For a new cluster, run each step separately:

```bash
sudo install -d -m 755 /etc/rancher/k3s
# Continue only if the following check succeeds:
sudo test ! -e /etc/rancher/k3s/config.yaml
sudo install -m 600 deploy/k3s/config.example.yaml /etc/rancher/k3s/config.yaml
curl -fsSL https://get.k3s.io -o /tmp/install-k3s.sh
less /tmp/install-k3s.sh
# After reviewing the installer, install the current stable channel:
sudo env INSTALL_K3S_CHANNEL=stable sh /tmp/install-k3s.sh
sudo k3s --version
sudo k3s kubectl get nodes
```

Record the selected version and use an explicitly reviewed `INSTALL_K3S_VERSION` for reproducible installations/upgrades. Do not uninstall Docker or overwrite its daemon/containerd configuration.

Keep kubeconfig separate from any existing cluster config:

```bash
mkdir -p "$HOME/.kube"
chmod 700 "$HOME/.kube"
sudo cp -n /etc/rancher/k3s/k3s.yaml "$HOME/.kube/codebridge.yaml"
sudo chown "$(id -u):$(id -g)" "$HOME/.kube/codebridge.yaml"
chmod 600 "$HOME/.kube/codebridge.yaml"
export KUBECONFIG="$HOME/.kube/codebridge.yaml"
kubectl config current-context
# Use the exact value above, and verify this context points at the intended VPS:
export DEPLOY_CONTEXT='default'
kubectl --context "$DEPLOY_CONTEXT" get nodes
```

Kubeconfig grants cluster-admin access: never commit/share it. Run kubectl over SSH on the VPS instead of exposing port 6443 publicly.

## 6. Render, create base resources and Secrets

```bash
python3 deploy/scripts/render.py
bash deploy/scripts/deploy.sh base "$DEPLOY_CONTEXT"
bash deploy/scripts/apply-secrets.sh auth "$DEPLOY_CONTEXT"
bash deploy/scripts/apply-secrets.sh operations "$DEPLOY_CONTEXT"
```

Commands assume namespace `codebridge`. If changed, pass it as the third argument to apply-secrets.sh and update manual `kubectl -n` commands. The renderer rejects example host/image placeholders unless `--allow-placeholders` is explicitly used for preview/CI. Never apply example output.

For private images, create the pull Secret using a private Docker config file containing usable registry auth, not a macOS credential-helper reference:

```bash
kubectl --context "$DEPLOY_CONTEXT" -n codebridge create secret generic registry-credentials \
  --type=kubernetes.io/dockerconfigjson \
  --from-file=.dockerconfigjson=/SECURE/PATH/registry-config.json
```

Use read-only pull credentials. For public images, set `image_pull_secret` to an empty string. K3s cannot automatically reuse Docker Engine's login or image cache.

```bash
bash deploy/scripts/deploy.sh validate "$DEPLOY_CONTEXT"
```

This performs server-side dry-run validation; it does not prove that image pulls, DNS or database credentials work. PVCs may remain Pending until the first Pod consumes them (WaitForFirstConsumer).

## 7. Review migrations, then start application Pods

Take a verified, restorable database backup and review pending migrations before the following command. Existing data is not copied or seeded by this kit. For rolling releases, pending schema changes must be compatible with both old and new application code (expand/contract).

```bash
bash deploy/scripts/deploy.sh migrate "$DEPLOY_CONTEXT" --database-backup-reviewed
bash deploy/scripts/deploy.sh app "$DEPLOY_CONTEXT"
kubectl --context "$DEPLOY_CONTEXT" -n codebridge get pods,pvc,svc,cronjobs
```

The migration stage waits for both Jobs and the app stage requires those successful Jobs. If a migration fails, inspect its logs and actual schema before retrying. Jobs have no automatic retry. A failed migration can leave partial schema changes. Do not use migrate:fresh or reset tables. Job names are unique per release and Kubernetes Job specs are immutable. Completed Job evidence is retained for 24 hours; do the rollout within that window. Later re-deployment should use a new reviewed release/job name.

Workers have zero replicas and CronJobs are suspended while activate_background is false. Test the gateway privately first:

```bash
kubectl --context "$DEPLOY_CONTEXT" -n codebridge port-forward service/gateway 8088:80
# Another VPS terminal:
curl --fail http://127.0.0.1:8088/health
# Test existing API paths and invalid/valid authentication as appropriate.
```

For an individual Laravel readiness endpoint, port-forward `service/auth-web 8089:80`, then request `http://127.0.0.1:8089/up`. `/health` checks the gateway, `/up` checks Laravel boot; neither proves all business flows work. Liveness checks avoid depending on the database or a busy FPM worker pool.

For existing uploads, freeze writes or perform a final delta copy, then copy the correct service's old `storage/app` contents to its PVC via a running PHP container (`kubectl cp ... -c app`). Preserve ownership/read permissions and verify existing avatar/product URLs before switching traffic. Local-path PVCs survive Pod replacement but do not provide off-server backups or multi-node shared storage. MinIO endpoints/settings also need updating if their old Docker DNS name is not reachable.

## 8. Enable HTTPS only when ready to switch traffic

Initially Traefik/ServiceLB are disabled to avoid conflicts with Docker. Decide which component will own public 80/443. At a planned cutover, free those ports without stopping data services, remove the two entries from the new K3s config's `disable` list, then `sudo systemctl restart k3s`. This briefly restarts the control plane; it is not a promise of zero downtime. Wait for `kubectl -n kube-system rollout status deployment/traefik` and `kubectl get ingressclass traefik`.

Configure DNS for the API host and obtain a certificate/full chain/private key covering it:

```bash
kubectl --context "$DEPLOY_CONTEXT" -n codebridge create secret tls gateway-tls \
  --cert=/SECURE/PATH/fullchain.pem --key=/SECURE/PATH/private.key
bash deploy/scripts/deploy.sh expose "$DEPLOY_CONTEXT"
curl --fail https://YOUR_API_HOST/health
```

Replace all example paths/hostnames. Certificate issuance/renewal is not automated here. If using Cloudflare, use Full (strict); Origin CA certificates work for Cloudflare-to-origin, not publicly trusted direct browser access. The Ingress is HTTPS-only; add an intentional HTTP redirect if needed.

Gateway NetworkPolicy allows ingress from Traefik Pods in kube-system and restricts FPM ingress to gateway Pods. Ensure those labels match your cluster and the K3s network-policy controller remains enabled. `trusted_proxy_cidrs` must match actual Traefik source addresses, never 0.0.0.0/0. Nginx derives REMOTE_ADDR from that trusted proxy chain so the existing IP rate limits work. For Cloudflare, configure Traefik to trust forwarded headers only from Cloudflare's actual current source ranges and restrict direct-origin access as appropriate. Test client-IP handling; do not blindly trust headers from arbitrary clients. Local port-forward diagnostics do not represent real public client-IP behavior.

Before cutover, verify login, refresh/logout, protected API paths, tenant authorization, uploads, email, payment callbacks, docs authorization and KDS streams. Preserve existing APP_URL/verification-link expectations and allowed frontend origins. Do not expose production traffic merely because Pods are Running.

## 9. Switch background processing and future releases

After testing, stop/disable old workers and schedulers for these services at the planned cutover (without deleting containers/volumes you need for rollback). Set activate_background=true in config.json, render again, and run the app stage within the migration evidence window. Verify a queued job and a scheduled task. Never let two schedulers unintentionally dispatch the same business action.

Use idempotent jobs and Laravel onOneServer/withoutOverlapping where appropriate. CronJob concurrencyPolicy Forbid prevents overlap for that CronJob; it does not guarantee exactly-once execution. Long schedule:run execution may skip subsequent minutes; dispatch long work to queues. CronJob.timeZone does not override Laravel's configured schedule timezone. Current Auth config/app.php hardcodes UTC; preserve UTC for stored timestamps and use explicit business schedule timezones where needed, or deliberately change it to read APP_TIMEZONE after reviewing application behavior.

For later releases: publish a new tag, update image fields and release, render, apply base/Secrets if changed, review/run migrations, then app/expose and smoke-test. Version annotations roll Pods when a new release changes runtime config. Do not reuse immutable release tags or run old migration Jobs as a shortcut. ConfigMap/Secret environment changes are not picked up by already-running Pods. `queue:restart` is unnecessary for an image rollout because Kubernetes replaces workers.

Rollback requires the previous images and matching config/secrets, compatible schema, upload continuity and worker coordination. Re-render the previous known-good non-secret config and apply the base/workload manifests after reviewing compatibility; stop current background consumers before reactivating old ones. Do not automatically run migrate:rollback. Rolling back only a Deployment does not roll back its ConfigMap, Secret, CronJob, PVC or database. Preserve the old Docker stack until validation and a rollback window are complete.

## Diagnostics and scope

```bash
kubectl --context "$DEPLOY_CONTEXT" -n codebridge get events --sort-by=.lastTimestamp
kubectl --context "$DEPLOY_CONTEXT" -n codebridge logs deployment/auth-web -c app --tail=100
kubectl --context "$DEPLOY_CONTEXT" -n codebridge logs deployment/gateway --tail=100
kubectl --context "$DEPLOY_CONTEXT" -n codebridge logs job/auth-migrate-r20261031-1
kubectl --context "$DEPLOY_CONTEXT" -n codebridge top pods
```

Change the example Job name to the configured release. ImagePullBackOff: inspect tags/auth; Pending: PVC/storage/capacity; CrashLoop: logs/env/permissions; failed /up: Laravel boot/DB dependencies; failed migration: private DB route/user/schema. `top` requires working metrics-server.

This kit does not include Prometheus/Grafana/Loki, automated backups, database relocation, cert-manager, Terraform, HPA or blue-green switching. Logs go to stderr. Keep off-VPS backups of databases/uploads and the K3s datastore/configuration/token; do not delete PVCs/namespaces to restart an app.

Local verification: deployment regression tests, shell syntax and manifest/reference checks. Docker/PHP/kubectl and a VPS connection were unavailable in the authoring environment, so image builds, Laravel tests, cluster dry-runs, connectivity and live rollout were not executed there. The added PR workflow builds images and validates Nginx; it still cannot substitute for staging on your VPS.

Official references: [K3s quick start](https://docs.k3s.io/quick-start), [network requirements](https://docs.k3s.io/installation/requirements), [ingress/ServiceLB](https://docs.k3s.io/networking/networking-services), [K3s runtimes](https://docs.k3s.io/advanced), [Laravel deployment](https://laravel.com/docs/12.x/deployment), [queues](https://laravel.com/docs/12.x/queues), [Kubernetes CronJobs](https://kubernetes.io/docs/concepts/workloads/controllers/cron-jobs/).
