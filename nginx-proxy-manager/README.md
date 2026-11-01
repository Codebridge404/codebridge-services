# 🌐 Nginx Proxy Manager (NPM) — CodeBridges Platform

Nginx Proxy Manager provides a web-based management interface for managing reverse proxies, free automated Let's Encrypt SSL/TLS certificates, custom domains, and access lists across the CodeBridges multi-service architecture.

---

## 🚀 Quick Start

### 1. Start Nginx Proxy Manager
When spinning up the CodeBridges cluster via the root `docker-compose.yml`, Nginx Proxy Manager starts automatically:

```bash
# In codebridge-services root:
docker compose up -d nginx-proxy-manager
```

### 2. Access the Admin Dashboard
Open your browser and navigate to:
- **URL:** [http://localhost:81](http://localhost:81)
- **Default Email:** `admin@example.com`
- **Default Password:** `changeme`

*(You will immediately be prompted to change the default email and password upon first login).*

---

## 🔌 Port Mapping

| Host Port | Container Port | Protocol | Purpose |
| :---: | :---: | :---: | :--- |
| **`80`** | `80` | HTTP | Inbound HTTP traffic (auto-redirected to HTTPS) |
| **`81`** | `81` | HTTP | Admin Web Management UI |
| **`443`** | `443` | HTTPS | Inbound encrypted TLS/SSL traffic (Let's Encrypt) |

---

## 🛠️ Routing Configuration Guide (Proxy Hosts)

Because Nginx Proxy Manager shares the Docker bridge network (`codebridge-network`), it can route directly to internal container hostnames:

### A. API Gateway (Central Microservices Entrypoint)
To route your API domain (e.g. `api.yourdomain.com`):
1. Go to **Hosts** $\rightarrow$ **Proxy Hosts** $\rightarrow$ **Add Proxy Host**.
2. **Domain Names:** `api.yourdomain.com`
3. **Scheme:** `http`
4. **Forward Hostname / IP:** `codebridge-api-gateway`
5. **Forward Port:** `80`
6. Check:
   - ☑️ **Cache Assets**
   - ☑️ **Block Common Exploits**
   - ☑️ **Websockets Support**
7. Go to **SSL** tab:
   - **SSL Certificate:** *Request a new SSL Certificate with Let's Encrypt*
   - Check: ☑️ **Force SSL**, ☑️ **HTTP/2 Support**, ☑️ **HSTS Enabled**
8. Click **Save**.

### B. MinIO S3 Object Storage
To route your media storage domain (e.g. `storage.yourdomain.com`):
- **Domain Names:** `storage.yourdomain.com`
- **Scheme:** `http`
- **Forward Hostname / IP:** `codebridge-minio`
- **Forward Port:** `9000`

### C. Frontend Web Client (Next.js)
To route your main web application (e.g. `app.yourdomain.com`):
- **Domain Names:** `app.yourdomain.com`
- **Scheme:** `http`
- **Forward Hostname / IP:** `host.docker.internal` *(or container name `codebridgesfront`)*
- **Forward Port:** `3000`
- Check: ☑️ **Websockets Support** *(required for Next.js HMR)*

---

## 📁 Storage & Persistence

Runtime state is persisted in local volume directories (ignored by git):
- `data/`: SQLite database, application settings, access lists, and Nginx configurations.
- `letsencrypt/`: Automated Let's Encrypt SSL certificates, renewals, and private keys.
