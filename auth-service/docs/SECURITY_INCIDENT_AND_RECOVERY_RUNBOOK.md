# CodeBridges Auth Service — Security Monitoring, Incident Response & Disaster Recovery Runbook

This runbook defines operational procedures for correlating requests, monitoring redacted logs and Telegram security alerts, containing active incidents, verifying backups, and executing emergency release or database recovery in `codebridge-services/auth-service`.

---

## 1. Logging, Redaction & Request Correlation Architecture

### Correlation IDs
- [`LogRequestActivity::handle`](../app/Http/Middleware/LogRequestActivity.php) assigns or propagates `X-Request-ID` (UUID) and `X-Trace-ID` (32-character trace identifier) on every HTTP request and echoes both headers on the HTTP response.
- Both IDs are attached to:
  - Application logs (`single` / `daily` / `stderr`)
  - Structured JSON stderr telemetry via [`TelemetryEmitter`](../app/Support/TelemetryEmitter.php)
  - Database request logs in `api_request_logs` (queryable via `GET /api/v1/request-logs?search=<request_id>`)
  - Mutation audit logs in `audit_logs`
  - Security forensic records in `security_events`

### Secret & PII Redaction
- [`LogRedactor`](../app/Support/LogRedactor.php) recursively masks passwords, PINs (`pin_code`, `pin`), bearer tokens, JWT access/refresh tokens, OTP/2FA secrets, recovery codes, API keys (`X-App-Key`), Telegram bot tokens, webhook secrets, and payment card fields (`card_number`, `cvv`), while preserving non-sensitive identifiers such as `company_code` and `currency_code`.
- Monolog channels (`single`, `daily` via `RedactSensitiveLogTap` and `stderr` via `RedactSensitiveLogProcessor` in [`config/logging.php`](../config/logging.php)) redact sensitive context keys and embedded URL tokens/credentials before log emission.
- Exception responses for API routes in [`bootstrap/app.php`](../bootstrap/app.php) mask raw SQL queries, SQLSTATE details, file paths, and stack traces behind structured error envelopes containing `request_id`.

---

## 2. Automated Threat Detection & Real-Time Alerts

### Perimeter Shields (`ProtectAttackMiddleware`)
[`ProtectAttackMiddleware::handle`](../app/Http/Middleware/ProtectAttackMiddleware.php) runs globally before route dispatch and blocks:
1. Active auto-banned or manually banned attacker IPs (`IP_AUTO_BANNED`, HTTP `403`).
2. Vulnerability scanner user-agents (`sqlmap`, `nikto`, `masscan`, `wpscan`, `gobuster`, `dirbuster`, `acunetix`, `havij`, `zgrab`, `nmap` -> `ATTACK_DETECTED`, HTTP `403`).
3. Poison null-byte URIs (`INVALID_URI`, HTTP `400`).
4. Path traversal / LFI (`PATH_TRAVERSAL_DETECTED`, HTTP `403`).
5. Shell / command injection (`COMMAND_INJECTION_DETECTED`, HTTP `403`).
6. SQL injection payloads (`SQL_INJECTION_DETECTED`, HTTP `403`).

### IP Auto-Ban & Telegram Dispatch
- Controlled by [`config/security.php`](../config/security.php) (`SECURITY_AUTO_BAN_ENABLED`, `SECURITY_MAX_OFFENSES`, `SECURITY_OFFENSE_WINDOW_MINUTES`, `SECURITY_BAN_MINUTES`, `SECURITY_IP_WHITELIST`) and runtime settings in [`IpThreatDefenseService`](../app/Services/IpThreatDefenseService.php).
- Blocked attacks trigger [`ProtectAttackMiddleware::dispatchTelegramSecurityAlert`](../app/Http/Middleware/ProtectAttackMiddleware.php) with HTML-escaped metadata and anti-flood caching (`10s` per threat type + IP) plus persistent `SecurityEvent` and `AuditLog` records.
- Telegram gateway health and delivery diagnostics are exposed to `super_admin`, `organization`, and tenant `admin` roles at:
  - `GET /api/v1/telegram-alerts/status`
  - `GET /api/v1/telegram-alerts/history`
  - `POST /api/v1/telegram-alerts/send-test`

---

## 3. Incident Response Triage & Containment Playbook

### Step 1: Triage (First 5 Minutes)
1. Take the `X-Request-ID`, `attacker IP`, or `tenant_id` from the Telegram alert or user report.
2. Query recent incidents and forensic details:
   ```bash
   curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
     "https://<auth-host>/api/v1/security-events?search=<REQUEST_ID_OR_IP>" | jq .
   curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
     "https://<auth-host>/api/v1/security-events/stats" | jq .
   ```
3. Correlate full request latency, status distribution, and actor context via `GET /api/v1/request-logs?search=<REQUEST_ID_OR_IP>`.

### Step 2: Contain Source IP & Adjust Auto-Ban Sensitivity
1. Inspect active IP bans: `GET /api/v1/security-events/banned-ips`
2. Manually ban an offending IP immediately:
   ```bash
   curl -s -X POST "https://<auth-host>/api/v1/security-events/ban-ip" \
     -H "Authorization: Bearer $ADMIN_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"ip":"203.0.113.45","duration_minutes":1440,"reason":"Active exploit attempt - incident INC-XXXX"}'
   ```
3. To reverse a false-positive ban: `POST /api/v1/security-events/unban-ip` with `{"ip":"..."}`.
4. Tighten auto-ban thresholds temporarily via `PUT /api/v1/security-events/settings`.

### Step 3: Compromised Account / Session Containment
1. Revoke all active sessions, rotating refresh tokens, and JWTs for the affected user:
   - Self-service / reauthenticated endpoint: `POST /api/v1/auth/logout-all-devices` (see [`routes/api/sessions.php`](../routes/api/sessions.php)).
   - Programmatic / emergency artisan tinker invocation using [`JwtService::revokeAllUserTokens`](../app/Services/JwtService.php):
     ```bash
     php artisan tinker --execute="app(\App\Services\JwtService::class)->revokeAllUserTokens(<USER_ID>);"
     ```
   - This increments `session_version:{user_id}` and writes `revoked_user_sessions:{user_id}` across both local cache and the shared cross-service Redis revocation store.
2. Require password reset and 2FA re-enrollment (`POST /api/v1/auth/2fa/setup` and recovery-code regeneration) before reactivating privileged accounts.

### Step 4: Credential & Signing Secret Rotation
1. **API Client / Webhook / Telegram Bot Secrets**: Update provider credentials via authenticated settings endpoints (`NotificationProviderController`) and delete compromised tokens in Stripe / Telegram BotFather.
2. **JWT Signing Key (`JWT_SECRET`)**: Replace `JWT_SECRET` in Kubernetes `secret.yaml` (never committed; templated from [`k8s/secret.example.yaml`](../k8s/secret.example.yaml)) or VPS `.env`, then redeploy pods/containers cleanly so all previously signed JWTs become invalid immediately.
3. **Database / Redis / MinIO Credentials**: Rotate `DB_PASSWORD`, `REDIS_PASSWORD`, and `MINIO_ROOT_PASSWORD`, restart stateful/app workloads, and confirm `/api/v1/health` returns HTTP `200` (`checks.database = true`, `checks.cache = true`).

---

## 4. Backup Verification & Disaster Recovery Procedures

### Encrypted Database Backups (Application-Level)
- Scheduled daily at `02:15` in [`routes/console.php`](../routes/console.php) via [`DatabaseBackupCommand`](../app/Console/Commands/DatabaseBackupCommand.php):
  ```bash
  php artisan backup:database-encrypted --destination=minio --retention=30
  ```
- Compresses SQL (`gzip -9`), encrypts with `AES-256-CBC` + HMAC (`Crypt::encrypt`), and writes a `.sha256` manifest to local storage and MinIO/S3.

### Infrastructure Snapshots (`deploy/backup/`)
- Create daily/weekly/monthly snapshots (MySQL + MinIO media + Docker image) with `.sha256` manifests:
  ```bash
  ./deploy/backup/backup.sh daily
  ./deploy/backup/status.sh
  ```
- Verify archive SHA-256 and gzip integrity without touching live services before any maintenance or restore:
  ```bash
  ./deploy/backup/restore.sh --verify ./deploy/backup/storage/daily/mysql_codebridge_auth_<TIMESTAMP>.sql.gz
  ```
- Execute full database or MinIO restore (refuses restore automatically if `.sha256` verification fails):
  ```bash
  ./deploy/backup/restore.sh ./deploy/backup/storage/daily/mysql_codebridge_auth_<TIMESTAMP>.sql.gz
  ```

### Emergency Application Release Rollback (Blue/Green VPS)
- Roll back immediately to the previously tested immutable container slot without rebuilding images:
  ```bash
  ./deploy/bluegreen/rollback.sh
  ```
- Verify `/api/v1/health` returns `200` (`healthy`) and monitor error rates via `GET /api/v1/request-logs?status_code=5xx`.

