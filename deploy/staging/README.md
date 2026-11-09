# CodeBridges Staging Deployment Guide (Single VPS)

This guide documents the setup, testing, and deployment of the **CodeBridges Staging Stack** on a single Virtual Private Server (VPS).

---

## 🏛️ Staging Architecture Overview

```mermaid
flowchart TD
    Client["Client / Tester"] --> CF["Cloudflare DNS & SSL (Full Strict)"]
    CF --> NPM["Nginx Proxy Manager (:80, :443)<br/>codebridge-staging-npm"]
    
    subgraph Staging Network (codebridge-staging-network)
        NPM -->|staging-codebridge-api...| GW["API Gateway (:80)<br/>codebridge-staging-gateway"]
        NPM -->|staging-codebridge-cdn...| S3["MinIO S3 (:9000)<br/>codebridge-staging-minio"]
        
        GW -->|/api/v1/auth| Auth["Auth Service (:9000)<br/>codebridge-staging-auth-service"]
        GW -->|/api/v1/operations| Ops["Operations Service (:9000)<br/>codebridge-staging-operations-service"]
        
        Auth --> AuthDB[("MySQL: auth_staging<br/>codebridge-staging-auth-mysql")]
        Auth --> AuthRedis[("Redis: auth_staging<br/>codebridge-staging-auth-redis")]
        
        Ops --> OpsDB[("MySQL: ops_staging<br/>codebridge-staging-operations-mysql")]
        Ops --> OpsRedis[("Redis: ops_staging<br/>codebridge-staging-operations-redis")]
        
        AuthWorker["Horizon Worker<br/>codebridge-staging-auth-horizon"] --> AuthRedis
        OpsWorker["Horizon Worker<br/>codebridge-staging-operations-horizon"] --> OpsRedis
        Scheduler["Scheduler Worker<br/>codebridge-staging-auth-scheduler"] --> AuthDB
    end
```

---

## 🚀 VPS Prerequisites

1. **VPS Operating System**: Ubuntu 22.04 / 24.04 LTS or Debian 12.
2. **Docker Engine**: Docker 24+ and Docker Compose v2.20+.
3. **Firewall / Open Ports**:
   - `80/tcp` (HTTP traffic from Cloudflare)
   - `443/tcp` (HTTPS traffic from Cloudflare)
   - `81/tcp` (NPM Admin web UI — restrict to your IP via UFW)
   - `22/tcp` (SSH management)
4. **Cloudflare DNS Records** (Pointing to your VPS IPv4):
   - `staging.codebridge.servicefixit.me` $\rightarrow$ `A <VPS_IP>` (Proxied ☁️)
   - `staging-codebridge-api.servicefixit.me` $\rightarrow$ `A <VPS_IP>` (Proxied ☁️)
   - `staging-codebridge-docs.servicefixit.me` $\rightarrow$ `A <VPS_IP>` (Proxied ☁️)
   - `staging-codebridge-cdn.servicefixit.me` $\rightarrow$ `A <VPS_IP>` (Proxied ☁️)
   - `staging-codebridge-events.servicefixit.me` $\rightarrow$ `A <VPS_IP>` (Proxied ☁️)

---

## 📦 Deployment Steps on VPS

### Step 1: Clone Repository & Checkout Branch
```bash
git clone https://github.com/Codebridge404/codebridge-services.git
cd codebridge-services
git checkout demo
```

### Step 2: Configure Staging Environment Files
```bash
cp env/staging/compose.env.example env/staging/compose.env
cp env/staging/auth.env.example env/staging/auth.env
cp env/staging/operations.env.example env/staging/operations.env
```
*Review and set strong secrets in `env/staging/*.env`.*

### Step 3: Run the Automated Deployment Engine
```bash
chmod +x deploy/staging/deploy.sh deploy/staging/status.sh
./deploy/staging/deploy.sh
```

### Step 4: Configure Nginx Proxy Manager (First Time Setup)
1. Navigate to `http://<VPS_IP>:81` in your browser.
2. Log in with initial credentials:
   - **Email:** `admin@example.com`
   - **Password:** `changeme`
3. Update admin profile and password immediately.
4. Add **Proxy Hosts** connected to `codebridge-staging-network`:
   - **API Host**:
     - Domain: `staging-codebridge-api.servicefixit.me`
     - Forward Hostname: `codebridge-staging-gateway`
     - Forward Port: `80`
     - SSL: Cloudflare Origin Certificate or Let's Encrypt, HTTP/2 Support, Force SSL.
   - **CDN / S3 Host**:
     - Domain: `staging-codebridge-cdn.servicefixit.me`
     - Forward Hostname: `codebridge-staging-minio`
     - Forward Port: `9000`
   - **Events / WebSockets Host**:
     - Domain: `staging-codebridge-events.servicefixit.me`
     - Forward Hostname: `codebridge-staging-auth-service`
     - Forward Port: `8080`
     - Enable: **WebSockets Support** (Checked)

---

## 🔍 Health Inspection & Maintenance

Inspect the live staging health and resource usage anytime:
```bash
./deploy/staging/status.sh
```

## Public K3s stack and deployment troubleshooting

This VPS also runs K3s with Traefik. Public ports 80/443 currently route to
the `codebridge-staging` Kubernetes namespace. A successful Compose deployment
alone does not update the public API or docs. Check both stacks before assuming
that a successful image build means the public endpoint has changed.

For an existing, configured K3s stack, run from the repository root:

```bash
KUBECONFIG=/home/deploy/.kube/config bash deploy/staging/update-k3s.sh
```

This builds and pushes revision-tagged images to the existing local registry,
updates both APIs, Horizon workers and the scheduler, runs migrations and clears
Laravel caches. It generates the gateway ConfigMap from `gateway/nginx.conf`
and `gateway/services/`, then restarts the gateway to refresh its ConfigMap
subPath mounts. Existing Kubernetes secrets and data services are preserved.
This command changes the serving staging environment.

The workflow must choose a **readable** kubeconfig. Testing only `-f` selects
`/etc/rancher/k3s/k3s.yaml` even when the deploy user cannot read it. Kubernetes
access, migration and rollout failures must fail the workflow rather than be
hidden by `|| true`.

Verify the running images and the public docs after deployment:

```bash
kubectl --kubeconfig /home/deploy/.kube/config -n codebridge-staging get deployments
curl --fail https://staging-codebridge-api.servicefixit.me/api/v1/health
curl --fail -L https://staging-codebridge-docs.servicefixit.me/docs/api | head -n 20
curl --fail https://staging-codebridge-docs.servicefixit.me/docs/api.json
curl --fail https://staging-codebridge-docs.servicefixit.me/docs/operations/api.json
```

The auth OpenAPI document (`/docs/api.json`) should include the `appKeyAuth`
security scheme using the `X-App-Key` header, and `/docs` should redirect
directly to `/docs/api` without an intermediate `docs.html` gateway page.

