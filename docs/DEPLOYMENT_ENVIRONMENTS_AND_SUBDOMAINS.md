# CodeBridges — Multi-Environment Docker & Subdomains Architecture

This guide details the multi-environment Docker topology, Cloudflare DNS & SSL/TLS configuration, Nginx Proxy Manager (NPM) proxy host rules, and Laravel microservices routing for **Development**, **Staging**, and **Production**.

---

## 🏗️ Architecture & Request Flow

```text
[ Browser / POS Client ]
           │
           ▼ (HTTPS / TLS 1.3)
   [ Cloudflare CDN & WAF ]
           │
           ▼ (Full Strict TLS)
[ Nginx Proxy Manager (NPM) ] ── (Ports 80 / 443)
   │               │              │
   │ (API / Docs)  │ (Images)     │ (Events / WebSockets)
   ▼               ▼              ▼
[ Nginx Gateway ] [ MinIO S3 ]   [ Laravel Reverb ]
   │ (FastCGI)       (:9000)        (:8080)
   ▼
[ Laravel PHP-FPM ] (:9000)
```

---

## 🌐 Subdomain Manifest (`servicefixit.me`)

### A. Production Environment
| Subdomain | Target Upstream | NPM Host Settings |
| :--- | :--- | :--- |
| **`codebridge.servicefixit.me`** | Next.js Frontend (`codebridgesfront:3000`) | Websockets Support: ON |
| **`codebridge-api.servicefixit.me`** | API Gateway (`codebridge-prod-gateway:80`) | Block Common Exploits: ON, Cache Assets: OFF |
| **`codebridge-docs.servicefixit.me`** | API Gateway (`codebridge-prod-gateway:80`) | Cache Assets: ON (Rewrites `/` to `/docs`) |
| **`codebridge-cdn.servicefixit.me`** | MinIO S3 (`codebridge-prod-minio:9000`) | Cache Assets: ON, Max Body: 64M |
| **`codebridge-events.servicefixit.me`** | Laravel Reverb (`auth-horizon:8080` / `ops:8080`) | **Websockets Support: ON** |

### B. Staging Environment
| Subdomain | Target Upstream | NPM Host Settings |
| :--- | :--- | :--- |
| **`staging.codebridge.servicefixit.me`** | Staging Frontend (`codebridgesfront:3000`) | Websockets Support: ON |
| **`staging-codebridge-api.servicefixit.me`** | Staging Gateway (`codebridge-staging-gateway:80`) | Block Common Exploits: ON |
| **`staging-codebridge-docs.servicefixit.me`** | Staging Gateway (`codebridge-staging-gateway:80`) | Rewrites `/` to `/docs` |
| **`staging-codebridge-cdn.servicefixit.me`** | Staging MinIO (`codebridge-staging-minio:9000`) | Max Body: 64M |
| **`staging-codebridge-events.servicefixit.me`** | Staging Reverb (`auth-horizon:8080`) | **Websockets Support: ON** |

---

## 📁 Environment Files & Directory Structure

```text
codebridge-services/
├── docker-compose.dev.yml            # Local development with dev ports exposed
├── docker-compose.staging.yml        # Isolated staging environment with staging DBs/storage
├── docker-compose.prod.yml           # Hardened production stack with zero exposed DB ports
└── env/
    ├── dev/
    │   ├── auth.env                  # Local dev credentials
    │   └── operations.env
    ├── staging/
    │   ├── compose.env               # Staging compose interpolation variables
    │   ├── auth.env                  # Staging auth service variables
    │   └── operations.env            # Staging operations service variables
    └── production/
        ├── compose.env               # Production compose interpolation variables
        ├── auth.env                  # Production auth service variables
        └── operations.env            # Production operations service variables
```

---

## 🚀 Execution Commands

### 1. Development (Localhost)
```bash
docker compose -p codebridge-dev -f docker-compose.dev.yml up -d --build
```
- Gateway: [http://localhost:8080](http://localhost:8080)
- Nginx Proxy Manager: [http://localhost:81](http://localhost:81)
- MinIO Web Console: [http://localhost:9001](http://localhost:9001)
- Mailpit Webmail: [http://localhost:8025](http://localhost:8025)

### 2. Staging Environment
```bash
docker compose -p codebridge-staging --env-file env/staging/compose.env -f docker-compose.staging.yml up -d
```
- Validate configuration beforehand:
  ```bash
  docker compose -p codebridge-staging --env-file env/staging/compose.env -f docker-compose.staging.yml config --quiet
  ```

### 3. Production Environment
```bash
docker compose -p codebridge-prod --env-file env/production/compose.env -f docker-compose.prod.yml up -d
```
- Validate configuration beforehand:
  ```bash
  docker compose -p codebridge-prod --env-file env/production/compose.env -f docker-compose.prod.yml config --quiet
  ```

---

## 🔒 Security & Trusted Proxy Hardening

1. **Cloudflare Real IP Ingestion**:
   [`gateway/nginx.conf`](../gateway/nginx.conf) is pre-configured with `set_real_ip_from` for all Cloudflare IP subnets and internal Docker ranges (`10.0.0.0/8`, `172.16.0.0/12`). Client IPs are passed transparently to Laravel via `X-Forwarded-For`.
2. **Private Data Services**:
   In both Staging and Production, MySQL, Redis, and MinIO ports are **not published to the host**. They communicate exclusively over the internal encrypted bridge networks.
3. **Immutable SSL**:
   Cloudflare SSL/TLS should be set to **Full (strict)**. Nginx Proxy Manager terminates SSL certificates issued by Let's Encrypt and forwards plain HTTP internally over the Docker network.

