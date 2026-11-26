# CodeBridge / SmartPOS API Completion Report — IP Rules, WAF, & Module Entitlements

**Date:** 2026-11-26  
**Repository:** `Codebridge404/codebridge-services` (Branch: `demo`)  
**Status:** Verified via 12-Rule Pre-Push Quality & Security Suite (`check_rules.sh` — 0 Failures)

---

## 1. Executive Summary

This report documents the APIs and cross-cutting security/entitlement middleware completed on **2026-11-26** in `auth-service`, covering:
1. **Platform IP Allowlist, Rate-Limit & WAF Rules API** (`GET`/`POST`/`DELETE /api/v1/super-admin/security/ip-rules`) with Redis-cached enforcement in `ProtectAttackMiddleware` and `RateLimitAuthMiddleware`.
2. **Cross-Service Module Entitlement & Global Kill-Switch Middleware** (`CheckModuleAccess`, `ModuleEntitlementService`, and `GET /api/v1/auth/authorize-context` exposure), returning `503 MODULE_DISABLED` on global kill-switch and `403 MODULE_NOT_SUBSCRIBED` when a tenant lacks module access.
3. **Full Observability, Auditing, Security Events & Queued Alerting** across `ApiRequestLog`, `AuditLog`, `SecurityEvent`, and `SendPlatformAlertNotificationJob` (`NotificationProvider`).

> **Microservice Boundary Note:** Per workspace rules, all code changes were implemented in `auth-service` (`operations-service` is read-only). `auth-service` exposes `enabled_modules` and `disabled_modules` via `/api/v1/auth/authorize-context` for downstream `operations-service` consumption.

---

## 2. Completed APIs & Middleware

### 2.1 Platform IP Allowlist, Rate-Limit & WAF Rules API (`dca2051`)
- **Endpoints (`role:super_admin`, `routes/api/super_admin.php`):**
  - `GET /api/v1/super-admin/security/ip-rules` — List active and historical platform IP/CIDR rules (`IpRuleController@index`).
  - `POST /api/v1/super-admin/security/ip-rules` — Create an allowlist or blocklist IP/CIDR rule with `StoreIpRuleRequest` validation (`IpRuleController@store`).
  - `DELETE /api/v1/super-admin/security/ip-rules/{id}` — Remove an IP/CIDR rule and invalidate the Redis rule cache (`IpRuleController@destroy`).
- **Database & Model:**
  - Migration: `2026_11_26_000001_create_platform_ip_rules_table.php` (`platform_ip_rules`).
  - Model: `App\Models\Security\PlatformIpRule`.
- **Redis Middleware Enforcement (`IpRuleService`):**
  - `ProtectAttackMiddleware` evaluates Redis-cached IP/CIDR allowlist and blocklist rules to block abusive IPs/CIDRs and allowlist enterprise office IPs.
  - `RateLimitAuthMiddleware` respects allowlisted enterprise IPs and enforces rate limits for non-allowlisted traffic.
- **Dependency Security:**
  - Upgraded `symfony/serializer` in `auth-service/composer.lock` (`composer audit = 0 vulnerabilities`).
- **Feature Tests:** `tests/Feature/IpRuleApiTest.php` (included in `check_rules.sh` full suite).

---

### 2.2 Module Entitlement & Global Kill-Switch Middleware (`d48e767`)
- **Middleware (`CheckModuleAccess`, registered as `module` alias in `bootstrap/app.php`):**
  - Uses Redis-cached `ModuleEntitlementService` to evaluate global module availability and per-tenant subscriptions.
  - Returns **HTTP `503`** (`code: MODULE_DISABLED`) when a module's global kill-switch is active (`is_active = false`), including for subscribed tenants and `super_admin`.
  - Returns **HTTP `403`** (`code: MODULE_NOT_SUBSCRIBED`) when a tenant lacks an active subscription/entitlement for a non-core module.
- **Controller & Context Integration:**
  - `PlatformModuleController` (`PUT /api/v1/super-admin/platform-modules/{id}`) invalidates global module cache (`ModuleEntitlementService::invalidateGlobalCache()`).
  - `TenantModuleController` (`PUT /api/v1/tenants/modules`) invalidates per-tenant module cache (`ModuleEntitlementService::invalidateTenantCache()`).
  - `AuthController::authorizeContext()` (`GET /api/v1/auth/authorize-context`) exposes `enabled_modules` and `disabled_modules` for downstream `operations-service` integration.

---

### 2.3 Observability, Auditing, Security Events & Queued Alerts (`d8b21ab`)
- **`ApiRequestLog` (`LogRequestActivity`):**
  - Records every `200`, `403`, and `503` response into `api_request_logs` with `request_id`, `trace_id`, `duration_ms`, `tenant_id`, `user_id`, `ip_address`, and `status_code` without duplicate rows from `CheckModuleAccess`.
- **`SecurityEvent` (`CheckModuleAccess::recordDenialSecurityEvent()`):**
  - Records `403 MODULE_NOT_SUBSCRIBED` denials (`event_type: module_access_denied`, `rule_code: SEC_MODULE_NOT_SUBSCRIBED`, `severity: low`).
  - Escalates severity to `medium` and queues a throttled (10-minute window) `SendPlatformAlertNotificationJob` when repeated `403` denials reach `>= 5` within 5 minutes.
  - Leaves `503 MODULE_DISABLED` out of `security_events` to prevent alert/event flooding during maintenance.
- **`AuditLog` & Queued `NotificationProvider` (`SendPlatformAlertNotificationJob`):**
  - `PlatformModuleController::update()` records `module.global_status_changed` (`old_value`, `new_value`, `reason`, `request_id`, `trace_id`) inside `DB::transaction()` and queues `SendPlatformAlertNotificationJob` after commit (queue errors are logged without rolling back module updates).
  - `TenantModuleController::update()` records `tenant.module_entitlements_changed` (`old_value`, `new_value`, `request_id`, `trace_id`) inside `DB::transaction()`.
  - `SendPlatformAlertNotificationJob` (`ShouldQueue`, `$tries = 3`, `$backoff = [10, 30, 60]`) dispatches via `IpThreatDefenseService::resolveSecurityAlertProvider()` with `LogRedactor::sanitizeTelegramSecrets()`.
- **Verification:** `ModuleEntitlementMiddlewareTest` (**6 passed, 34 assertions**).

---

## 3. Verification Summary

| Check | Result |
| :--- | :--- |
| `./vendor/bin/pint` | **PASS** (0 style issues) |
| `php artisan test --filter ModuleEntitlementMiddlewareTest` | **6 passed (34 assertions)** |
| `bash ./check_rules.sh` (Rules 1–12, including `composer audit`) | **ALL PASSED (0 Failures)** |

---

## 4. Commit Log (`codebridge-services` `demo`)

| Commit | Summary |
| :--- | :--- |
| `d8b21ab` | `feat(auth-service): record module access events in AuditLog, SecurityEvent, ApiRequestLog, and queued alerts` |
| `d48e767` | `feat(auth): add Redis-cached CheckModuleAccess middleware, kill-switch & authorize-context entitlements` |
| `dca2051` | `feat(auth): add Platform IP Allowlist, Rate-Limit & WAF Rules API and upgrade symfony/serializer` |
