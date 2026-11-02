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

