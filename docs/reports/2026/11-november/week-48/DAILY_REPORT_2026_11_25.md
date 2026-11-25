# CodeBridge / SmartPOS Platform Security, Device Sessions & Infrastructure Report

**Date:** 2026-11-25  
**Repository:** `Codebridge404/codebridge-services` (Branch: `demo`)  
**Status:** Verified via 12-Rule Pre-Push Quality & Security Suite (`check_rules.sh` — 0 Failures)

---

## 1. Executive Summary

This report documents the security hardening, multi-device session architecture, platform maintenance governance, queue/cron monitoring, and CI/CD upgrades completed across `auth-service`, `gateway`, `k8s/staging`, and `.github/workflows/`.

---

## 2. Implemented Features & Security Remediations

### 2.1 Multi-Device & Multiple Login Sessions (`4ee6435`, `663e005`)
- **Database Migration:** `2026_11_25_000001_create_user_devices_and_link_sessions.php`
  - Added `user_devices` table (`uuid`, `user_id`, `identifier_hash`, `device_name`, `device_type`, `browser`, `platform`, `last_ip`, `last_location`, `last_seen_at`) with unique constraint `(user_id, identifier_hash)`.
  - Extended `user_sessions` with `user_device_id`, `personal_access_token_id`, `login_ip`, `browser`, `platform`, and `location`.
- **Device Identity & Session Grouping (`DeviceSessionService`):**
  - Web browsers receive a persistent `HttpOnly` `cb_device_id` cookie (256-bit random identifier); native/Flutter clients send validated `X-Device-Id`.
  - Preserves initial `login_ip` per session while updating `UserDevice.last_ip` (IPv4/IPv6) and `last_seen_at` on active requests and token refreshes.
  - `GET /api/v1/auth/sessions` groups login sessions under each recognized device, exposing `account_email`, `device_name`, `last_ip_address`, `status`, `is_current`, and nested `sessions`.
  - `DELETE /api/v1/auth/sessions/{id}` revokes all active sessions, rotating refresh tokens, JWT `sid` cache entries, and linked PATs for an owned device without affecting other devices.
  - Removed unsafe `device_name`-based PAT fallback in `AuthenticateJwt`.
- **Verification:** `DeviceSessionGroupingTest` (8 passed, 74 assertions) + `ActiveSessionsTest` + `LegacyTokenCompatibilityTest` + `UserSessionsApiTest` (32 passed, 209 assertions).

### 2.2 Platform Maintenance Mode & Scheduled Downtime API (`ba1c456`, `4bc51e6`)
- **Database Migration:** `2026_11_25_000002_create_platform_maintenance_settings_table.php` (`platform_maintenance_settings`).
- **Storage & Caching (`MaintenanceModeService`):** Database as persistent source of truth with Redis cache (`platform:maintenance:config`, 300s TTL) and automatic DB fallback. Timestamps formatted in `Asia/Phnom_Penh` (`+07:00`).
- **Endpoints:**
  - `GET /api/v1/super-admin/maintenance` — Read maintenance configuration (`role:super_admin`).
  - `PUT /api/v1/super-admin/maintenance` — Update schedule, banner message, and `block_mutations` policy with audit logging.
  - `GET /api/v1/maintenance/status` — Public banner endpoint (exempted from `X-App-Key` in NGINX Gateway, K3s `configmaps.yaml`, and `EnsureApiClient`).
- **503 Mutation Guard (`CheckMaintenanceMode`):** Blocks non-`super_admin` `POST`/`PUT`/`PATCH`/`DELETE` requests with HTTP `503 Service Unavailable` when active and `block_mutations = true`, while allowing `GET` reads, auth login/2FA/refresh/logout, and verified `super_admin` requests.
- **Verification:** `MaintenanceModeApiTest` (5 passed, 36 assertions) + live gateway `curl` verification (`200 OK`).

### 2.3 Cross-Service Queue, Cron & Failed Jobs Monitor API (`22d156d`)
- **Endpoints (`role:super_admin`):**
  - `GET /api/v1/super-admin/infrastructure/queues` — Reports pending jobs, sanitized `failed_jobs` summaries (no raw payloads or credentials), Horizon worker status (`healthy`/`degraded`/`unknown`), and scheduler heartbeat (`monitoring:auth:scheduler:last_seen`: `healthy`/`stale`/`unknown`). Queries `operations-service` via authenticated internal HTTP (`OPERATIONS_INTERNAL_QUEUES_URL`) without crossing DB boundaries, returning `unknown`/`reachable: false` when unreachable.
  - `POST /api/v1/super-admin/infrastructure/queues/{service}/retry/{job_id}` — Retries failed jobs on `auth-service` (via `queue:retry`) or proxies to `operations-service`, recording `queue.job_retry` in `audit_logs`.
- **Scheduler Heartbeat:** Added `everyMinute()` heartbeat in `auth-service/routes/console.php`.
- **Verification:** `QueueMonitorApiTest` (4 passed, 27 assertions).

### 2.4 Security Advisories, Gateway & CI/CD Updates
- **Laravel Framework Upgrade (`d211c90`):** Upgraded `auth-service` to `laravel/framework v12.74.0` (mitigating API JSON handling and wildcard validation advisories; `composer audit = 0 vulnerabilities`).
  - *Note:* `operations-service` remains on `v12.72.0` (read-only boundary) and should be upgraded in its own maintenance cycle.
- **NGINX CVE Audit:** Audited `gateway/nginx.conf`, `docker/cloud-nginx.conf`, and `bluegreen/nginx/nginx.conf` against `CVE-2026-123007`, `CVE-2026-123633`, `CVE-2026-123634`, `CVE-2026-121810`, and `CVE-2026-113425` — **all 5 confirmed Not Exposed**.
- **OWASP ZAP Staging Header Fix (`ab87a8a`):** Removed `add_header Access-Control-Allow-Origin * always;` from `location = /` in `gateway/nginx.conf` and `k8s/staging/configmaps.yaml` so server-level `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` headers are inherited on `/`. Verified with `nginx -t` and live `curl`.
- **GitHub Actions Node 24 Compatibility (`09cb1cf`):** Upgraded `actions/checkout@v4` -> `@v7` and `actions/cache@v4` -> `@v6` across all 5 workflows (`auth-service.yml`, `operations-service.yml`, `laravel.yml`, `publish-ghcr.yml`, `release.yml`).

---

## 3. Recent Commit Log (`codebridge-services` `demo`)

| Commit | Summary |
| :--- | :--- |
| `22d156d` | `feat(auth): add Cross-Service Queue, Cron & Failed Jobs Monitor API with audit logging` |
| `09cb1cf` | `ci: upgrade actions/checkout to v7 and actions/cache to v6 for Node 24 compatibility` |
| `4bc51e6` | `feat(gateway): route public maintenance status endpoint and exempt from X-App-Key` |
| `ab87a8a` | `fix(gateway): remove wildcard CORS on root location to preserve security headers` |
| `ba1c456` | `feat(auth): add Platform Maintenance Mode & Scheduled Downtime API with 503 mutation guard` |
| `663e005` | `Merge pull request #17 from Codebridge404/feat/device-session-grouping` |
| `4ee6435` | `feat(auth): group login sessions under persistent user_devices with IP history and device revocation` |
| `d211c90` | `fix(security): upgrade laravel/framework to v12.74.0 for JSON handling & wildcard validation advisories` |
