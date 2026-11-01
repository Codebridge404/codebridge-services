# Production Blue-Green Deployment & Nginx Proxy Manager (NPM) Guide

This guide details the **Production Blue-Green Deployment Architecture** for CodeBridges microservices using Nginx Proxy Manager on a VPS.

---

## 🏛️ Architecture & Network Isolation

```text
[ Cloudflare CDN & WAF (servicefixit.me) ]
                  │
                  ▼ (HTTPS / Full Strict)
    [ Nginx Proxy Manager (NPM: 80 / 443) ]
                  │
        (codebridge-prod-proxy-network)
         ┌────────┴────────┐
         ▼                 ▼
[ Blue Gateway:80 ]   [ Green Gateway:80 ]
  (Blue Network)        (Green Network)
   ├── Auth Service      ├── Auth Service
   └── Ops Service       └── Ops Service
         └────────┬────────┘
                  ▼
    (codebridge-prod-data-network)
   ├── MySQL Databases (Auth & Ops)
   ├── Redis Caches (Auth & Ops)
   └── MinIO S3 Object Storage
```

### Key Isolation Rule
- **Blue and Green application containers run on separate internal Docker networks** (`codebridge-prod-blue-network` and `codebridge-prod-green-network`).
- Inside Blue, `auth-service:9000` resolves only to Blue Auth.
- Inside Green, `auth-service:9000` resolves only to Green Auth.
- **Only the gateways join NPM's shared proxy network** (`codebridge-prod-proxy-network`). There is zero DNS conflict.
- Both slots connect to the shared production database, Redis, and MinIO instances via `codebridge-prod-data-network`.

---

## 🚀 Deployment Workflow

```text
1. Active Slot: BLUE
2. Deploy new code/image to GREEN slot
3. Run deep readiness health checks against GREEN (Laravel Kernel & DB)
4. Execute backward-compatible database migrations (additive only)
5. Switch NPM Proxy Host from Blue Gateway to Green Gateway
6. Drain and stop previous Blue queue workers
7. Keep Blue container available on standby for zero-lag rollback!
```

---

## 🛠️ CLI Automation Commands

### 1. Deploy New Version
```bash
./deploy/production/deploy.sh
```
Automatically detects the active slot, starts and builds the standby slot, runs health probes, executes database migrations, and updates state.

### 2. Check Status
```bash
./deploy/production/status.sh
```
Shows active slot, standby slot, container metrics, and gateway readiness for both slots.

### 3. Instant Rollback
```bash
./deploy/production/rollback.sh
```
Immediately restores the standby slot and instructs you on switching NPM's Forward Hostname back.

---

## 🌐 Nginx Proxy Manager (NPM) Switching Guide

### When Deploying from Blue $\rightarrow$ Green:
1. Open NPM: [http://localhost:81](http://localhost:81) (or `http://your-vps-ip:81`)
2. Edit Proxy Host: `codebridge-api.servicefixit.me`
3. Change **Forward Hostname / IP**:
   - From: `codebridge-prod-blue-gateway`
   - To:   `codebridge-prod-green-gateway`
4. Click **Save**.

### When Rolling Back from Green $\rightarrow$ Blue:
1. Edit Proxy Host: `codebridge-api.servicefixit.me`
2. Change **Forward Hostname / IP** back to: `codebridge-prod-blue-gateway`
3. Click **Save**.
