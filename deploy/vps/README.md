# CodeBridge Staging VPS & CI/CD Operations Guide

This documentation specifies the architecture, setup, and deployment procedures for the CodeBridge staging environment running on **K3s**.

---

## 1. Architecture Overview

- **Staging Branch**: `demo`
- **Staging Runtime**: **K3s only** (Namespace: `codebridge-staging`)
- **Staging VPS Repository Path**: `/opt/codebridge-services/codebridge-services`
- **Local Registry**: `127.0.0.1:5000` (Docker container `registry` persistent storage)
- **Deployment Isolation**:
  - **Auth-only change**: Tests `auth-service`, builds and pushes Auth image (`127.0.0.1:5000/codebridge-auth-service:<sha>`), runs Auth database migrations via Kubernetes Job, updates `deployment/auth-service`, `deployment/auth-horizon`, and associated CronJobs (`auth-scheduler`, `auth-db-backup`).
  - **Operations-only change**: Tests `operations-service`, builds and pushes Operations image (`127.0.0.1:5000/codebridge-operations-service:<sha>`), runs Operations migrations via Kubernetes Job, updates `deployment/operations-service`, `deployment/operations-horizon`, and associated CronJobs (`operations-scheduler`, `operations-db-backup`).
  - **Both changed**: Both workflows execute independently, testing and rolling out their respective services.
  - **Shared routing & infrastructure**: Changes to `gateway/`, `k8s/`, `env/`, `docker-compose*` do not trigger service-only deployments. They are deployed via the explicit full infrastructure workflow.
  - **Untouched Resources**: Service-only updates never restart or touch Gateway, the other API service, MySQL, Redis, MinIO, or phpMyAdmin.

---

## 2. GitHub Actions Secrets

Configure the following secrets in GitHub Repository Settings (`Settings` &rarr; `Secrets and variables` &rarr; `Actions`):

| Secret Name | Description | Example / Default |
| :--- | :--- | :--- |
| `STAGING_VPS_HOST` | VPS IP address or domain | `194.233.xx.xx` or `staging.codebridge.cloud` |
| `STAGING_VPS_USER` | Deployment user on VPS | `deploy` |
| `STAGING_VPS_SSH_KEY` | Dedicated SSH private key for GitHub Actions | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
| `STAGING_VPS_PORT` | SSH port | `22` |
| `STAGING_DEPLOY_PATH` | Tracked repository path on the VPS | `/opt/codebridge-services/codebridge-services` |
| `STAGING_VPS_FINGERPRINT` | *(Optional)* SSH host key fingerprint for host verification | `SHA256:4K...` (from `ssh-keygen -lf /etc/ssh/...`) |

> **Host Verification Notice**: Do not disable host key verification. Provide `STAGING_VPS_FINGERPRINT` to pin and verify the server's public key.

---

## 3. One-Time VPS Setup

### Step 1: Run the Setup Script
Log in to your staging VPS and execute the non-destructive inspector:

```bash
cd /opt/codebridge-services/codebridge-services
bash deploy/vps/setup.sh
```

*(For a brand new empty VPS only, pass the `--fresh-install` flag to install package dependencies: `bash deploy/vps/setup.sh --fresh-install`).*

The setup script:
1. Verifies required dependencies: `git`, `docker`, `kubectl`/`k3s`, `bash`, `flock`, `curl`, `jq`.
2. Verifies or creates the `deploy` user and configures `~/.ssh/authorized_keys`.
3. Adds `deploy` to the `docker` group. *(Security note: Docker socket access is root-equivalent)*.
4. Generates a secure `~/.kube/config` with `600` permissions (never cluster-admin world-readable).
5. Ensures the persistent local Docker registry is running on `127.0.0.1:5000`.
6. Checks K3s `registries.yaml` for containerd HTTP registry access.
7. Verifies access to the `codebridge-staging` namespace.

### Step 2: Configure K3s Registry Mirror (Containerd)
To allow K3s containerd to pull images from `127.0.0.1:5000` without TLS certificates, verify `/etc/rancher/k3s/registries.yaml`:

```yaml
mirrors:
  "127.0.0.1:5000":
    endpoint:
      - "http://127.0.0.1:5000"
```

> **Maintenance Window Notice**: If modifying `/etc/rancher/k3s/registries.yaml`, restart K3s during a scheduled maintenance window (`systemctl restart k3s`). Do not restart K3s during active deployments.
>
> **Single-Node Loopback Notice**: `127.0.0.1:5000` binds to loopback and assumes a single-node K3s staging cluster. For multi-node setups, configure an externally addressable private registry.

---

## 4. Workflows & Trigger Matrix

All workflows reside in `.github/workflows/`:

| Workflow File | Trigger Conditions | Jobs | Target Action |
| :--- | :--- | :--- | :--- |
| [`.github/workflows/auth-service.yml`](file:///.github/workflows/auth-service.yml) | Push to `demo` on `auth-service/**`<br>PR targeting `demo` on `auth-service/**`<br>`workflow_dispatch` on `demo` | `test`<br>`deploy` (needs: test) | Runs Auth PHP 8.4 test suite.<br>Deploys only Auth service to K3s via SSH. |
| [`.github/workflows/operations-service.yml`](file:///.github/workflows/operations-service.yml) | Push to `demo` on `operations-service/**`<br>PR targeting `demo` on `operations-service/**`<br>`workflow_dispatch` on `demo` | `test`<br>`deploy` (needs: test) | Runs Operations PHP 8.4 test suite.<br>Deploys only Operations service to K3s via SSH. |
| [`.github/workflows/deploy-staging.yml`](file:///.github/workflows/deploy-staging.yml) | Manual `workflow_dispatch` only | `deploy-k3s-full-infra` | Deploys shared K3s infrastructure, Gateway ConfigMaps, and shared routing. |
| [`.github/workflows/laravel.yml`](file:///.github/workflows/laravel.yml) | Push to `master`, `main`, feature branches | `changes`, `test-auth`, `test-operations` | General CI for developer branches (bypassed on `demo` to prevent duplicate test runs). |

### Concurrency & Queue Policy
All staging deployment workflows share the same concurrency lock:
```yaml
concurrency:
  group: staging-k3s-deploy
  cancel-in-progress: false
```
- Deployments queue cleanly in order.
- In-progress deployments are **never cancelled halfway through**, ensuring atomic rollouts.
- On the VPS, a kernel-level `flock` (`/tmp/codebridge-staging-deploy.lock`) protects against concurrent CLI deployments or overlapping SSH jobs.

---

## 5. Deployment Scripts & CLI Usage

You can deploy services on the VPS manually or via CI:

### Auth Service Deployment:
```bash
bash auth-service/scripts/deploy.sh <commit-sha>
```

### Operations Service Deployment:
```bash
bash operations-service/scripts/deploy.sh <commit-sha>
```

Both scripts delegate to the shared helper [deploy/vps/k3s-service-deploy.sh](file:///deploy/vps/k3s-service-deploy.sh) which:
1. Acquires the VPS file lock (`flock`).
2. Checks that tracked working files are clean (never uses destructive `git reset --hard` or `git clean`).
3. Checks out the exact tested Git commit SHA.
4. Validates Docker, registry, K3s, and namespace accessibility.
5. Builds and pushes `127.0.0.1:5000/codebridge-<service>-service:<sha>`.
6. Executes database migrations **once** from the new image via a dedicated Kubernetes Job (`<service>-migration-<sha>`).
7. Updates Kubernetes Deployments (`deployment/<service>-service`, `deployment/<service>-horizon`) and optional CronJobs.
8. Awaits rollout completion (`kubectl rollout status`).
9. Warms Laravel caches (`php artisan optimize`) and generates OpenAPI documentation (`php artisan scramble:cache`) across all running pod replicas.

---

## 6. Database Migration Policy

- **Single-Job Execution**: Migrations run once via a dedicated Kubernetes Job before pod rollout. Migrations are never run concurrently from multiple pod replicas.
- **Backward-Compatible Schema Changes**: All database migrations during zero-downtime rolling updates must be strictly backward-compatible with running pods:
  - Add nullable columns or columns with default values.
  - Do not drop or rename active columns in the same release as code changes.
  - Multi-phase migration: 1. Deploy additive migration &rarr; 2. Deploy application code &rarr; 3. Drop deprecated schema in a subsequent release.
- **No Automatic Database Rollback**: Schema migrations cannot be rolled back automatically if an application rollout fails. Database rollback requires deliberate administrator intervention.

---

## 7. Operational Troubleshooting

| Issue | Cause | Solution |
| :--- | :--- | :--- |
| `flock: /tmp/codebridge-staging-deploy.lock: Resource temporarily unavailable` | Another deployment or manual script is currently running | Wait for the previous deployment to complete or inspect active processes (`ps aux \| grep deploy.sh`). |
| `Local registry container 'registry' not found` | The Docker registry container is stopped or missing | Run `bash deploy/vps/setup.sh` to restart or recreate the persistent registry container. |
| `Modified tracked files detected... Deployment aborted` | Uncommitted tracked changes on VPS | Inspect changes with `git status`. Commit or discard tracked modifications. Untracked files (`.env`, secrets) are never touched. |
| `Migration job failed or timed out` | Database connection error or invalid SQL in migration | Inspect migration pod logs: `kubectl -n codebridge-staging logs job/<service>-migration-<sha>`. |
| `Optional cronjob/<name> not found; skipping` | CronJob has not been applied to staging | Expected if CronJobs are not yet defined; does not block deployment. |
