# Blue/Green Zero-Downtime Deployment Guide (VPS)

This guide explains how to deploy **CodeBridges Auth & Identity Service** to a VPS (DigitalOcean, AWS EC2, Hetzner, Linode, Ubuntu/Debian) with zero downtime using Docker Compose Blue/Green switching, covering both **Demo** and **Production** profiles.

---

## Architecture Overview

```
                          [ Incoming HTTP / HTTPS Traffic ]
                                          │
                                          ▼
                         [ codebridge-auth-proxy (Nginx) ]
                                          │
                         Reads: active-upstream.conf
                                  │               │
                     (Active: BLUE)               (Active: GREEN)
                                  ▼               ▼
                      auth-service-blue       auth-service-green
                      auth-horizon-blue       auth-horizon-green
                                  │               │
                                  └───┬───────┬───┘
                                      │       │
                                      ▼       ▼
                                [ auth-mysql ] [ auth-redis ]
                                [ auth-minio ]
```

### Components
1. **Shared Stateful Tier (Persistent Across Deployments)**:
   - `auth-mysql`: Dedicated MySQL 8.4 database (`codebridge_auth`).
   - `auth-redis`: Dedicated Redis 8 instance for cache, sessions, and queue.
   - `minio` + `minio-init`: Dedicated S3 object storage for avatars and media.
2. **Dual Application Slots**:
   - **Blue**: `auth-service-blue` + `auth-horizon-blue`
   - **Green**: `auth-service-green` + `auth-horizon-green`
3. **Nginx Dynamic Gateway**:
   - `auth-proxy`: Listens on HTTP `:80` (or `:8080`), dynamically includes `/etc/nginx/conf.d/upstream.conf`.

---

## 1. Quick Setup: Demo Environment

The **Demo** environment includes **Mailpit** (SMTP web UI) and **phpMyAdmin** for inspecting state.

### Step 1: Copy Demo Environment
```bash
cd codebridge-services/auth-service
cp deploy/bluegreen/env.demo .env
```

### Step 2: Launch Demo with Blue Slot
```bash
docker compose -f docker-compose.bluegreen.yml --profile demo up -d auth-mysql auth-redis minio minio-init mailpit auth-phpmyadmin auth-service-blue auth-horizon-blue auth-proxy
```

### Step 3: Access Endpoints
- **API & Docs**: `http://<your-vps-ip>:8080/docs/api`
- **Mailpit Web UI**: `http://<your-vps-ip>:8025`
- **phpMyAdmin**: `http://<your-vps-ip>:8081`
- **MinIO Console**: `http://<your-vps-ip>:9001`

---

## 2. Production Setup (VPS)

Production disables phpMyAdmin/Mailpit, binds internal databases strictly to `127.0.0.1`, and serves on port 80/443.

### Step 1: Copy Production Environment
```bash
cd codebridge-services/auth-service
cp deploy/bluegreen/env.production .env
```

### Step 2: Configure Production Secrets in `.env`
Edit `.env` and fill in:
```env
APP_KEY=base64:...              # Generate via: php artisan key:generate --show
JWT_SECRET=...                  # Random 64-character secret
DB_PASSWORD=...                 # Strong database password
DB_ROOT_PASSWORD=...            # Strong MySQL root password
REDIS_PASSWORD=...              # Strong Redis password
MINIO_ROOT_PASSWORD=...         # Strong MinIO password
```

### Step 3: Initial Deployment
Run the initial deploy script:
```bash
./deploy/bluegreen/deploy.sh
```

---

## 3. How Blue/Green Zero-Downtime Deployment Works

When you want to deploy a new release or git update on your VPS:

```bash
git pull origin feature/auth-service-requests-resources-architecture
./deploy/bluegreen/deploy.sh
```

### Automated Deployment Sequence:
1. **Detects Active Color**: Checks `.active_color` (e.g. `blue`). Sets target to `green`.
2. **Builds Target**: Builds `auth-service-green` and `auth-horizon-green`.
3. **Starts Target**: Boots `auth-service-green`.
4. **Health Check**: Polls until PHP-FPM inside `auth-service-green` responds successfully.
5. **Database Migrations**: Runs `php artisan migrate --force` inside `auth-service-green`.
6. **Traffic Swap**: Copies `upstream-green.conf` to `active-upstream.conf`.
7. **Nginx Reload**: Runs `nginx -s reload` (0 dropped connections).
8. **Graceful Drain**: Stops `auth-service-blue`.
9. **State Saved**: Updates `.active_color` to `green`.

---

## 4. Emergency Instant Rollback

If unexpected bugs occur after a deployment, rollback takes **under 2 seconds**:

```bash
./deploy/bluegreen/rollback.sh
```

This immediately boots the previous color, swaps Nginx upstream back, reloads Nginx, and pauses the faulty container.

