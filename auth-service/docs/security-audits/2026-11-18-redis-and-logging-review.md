# Redis cache and logging review — 18 November 2026

Scope: the current local `auth-service` worktree, including the earlier passkey fixes. This review checked Redis configuration, selected cache consumption/invalidation paths, and persistence of `ApiRequestLog`, `AuditLog`, and `SecurityEvent`. The original five findings remain fixed locally. A repeat review confirmed two additional logging gaps, documented below. Environment files and existing application records were not changed.

## Repeat review — two additional findings remain open

The repeat review checked the worktree based on `250e1e2`. All earlier acceptance checks still pass. This pass extends coverage to public-endpoint actor attribution and malformed UTF-8 form input. Application implementation files were not changed during this repeat review.

### 6. Medium: public requests can be logged as another user and tenant

Locations: `app/Http/Middleware/LogRequestActivity.php:110–141` and `app/Http/Controllers/Meeting/MeetingIssueController.php:90`.

When there is no authenticated actor, the activity logger treats a successful response's `data.id` as a user ID. That field can identify a business record rather than a user. If it happens to match an existing user, the logger loads that user and derives the tenant from the account. The public issue controller independently substitutes the first user in the database as `reported_by` for an anonymous reporter.

Confirmed reproduction: create user A in workspace A and a room in workspace B; send a public issue report using a valid application key and no bearer token. When the new issue ID matches user A's ID, the issue belongs to B, but both the API request and mutation audit are attributed to user A and workspace A. The probe explicitly verifies that the Sanctum guard has no authenticated user.

Impact: actor identity and tenant ownership in forensic records become unreliable, and an issue payload can be stored under an unrelated workspace's logs. This does not authenticate the caller as user A or issue a token for that account. The generic logger's misattribution depends on the ID collision; the controller's first-user reporter substitution does not.

Recommended fix: remove generic `data.id` actor inference and propagate authenticated actor context explicitly for login/registration flows. Keep resource ownership separate from actor identity. Anonymous reporting should use a nullable actor or an explicitly identified, tenant-scoped kiosk principal rather than the first account in the database. Cover non-user response IDs and cross-tenant public reporting in acceptance tests.

### 7. Medium: malformed UTF-8 input can prevent log persistence

Locations: `app/Support/LogRedactor.php:91`, `app/Http/Middleware/LogRequestActivity.php:239`, and the JSON-cast payloads in `ApiRequestLog` and `AuditLog`.

The recursive redactor preserves invalid UTF-8 bytes in ordinary strings. Eloquent cannot JSON-encode the resulting payload, so the insert throws `JsonEncodingException`. The logging helpers catch it; the original HTTP response still proceeds.

Confirmed reproductions use form submissions, equivalent to a percent-encoded `%FF` byte in an extra field; they do not rely on accepting malformed JSON:

- An invalid login returns HTTP 422 but has no `ApiRequestLog` row for its request ID. The probe captures the JSON-encoding exception class from the safe warning.
- A valid authenticated reauthentication returns HTTP 200, but neither its API request record nor its automatic mutation audit is saved when the same extra field is present.

Impact: a caller can cause request/audit database records to be missing without changing the endpoint's normal outcome. Server warnings and activity messages still exist; the probe does not establish that all traces are erased, nor does it demonstrate an authentication bypass.

Recommended fix: normalize invalid UTF-8 safely before serializing log payloads, or replace offending values with a diagnostic marker. Ensure payload-encoding failures still save a minimal record with request ID, authenticated context, path, and status. Add form-input and nested-value regressions for both rejected and successful requests.

### Repeat-review verification

| Check | Result |
| --- | --- |
| Full existing suite, with temporary S3 region/bucket | 494 passed, 2 skipped; 3,222 assertions |
| Existing isolated Redis acceptance suite | 21 passed; 234 assertions |
| Additional observational probes | 3 passed; 14 assertions confirming findings 6–7, rather than accepting them as secure behavior |
| Dependency audit | No advisories or abandoned dependencies |
| Redis runtime | `CACHE_STORE=redis`, PING successful; cache DB 1 and revocation DB 2 |
| Redis eviction snapshot | `maxmemory=0`, `maxmemory_policy=noeviction`, `evicted_keys=0` |
| Current SQL record-count snapshot | API requests: 15; audits: 6; security events: 3 |
| New probe formatting and syntax | Passed |

The additional probes use in-memory SQLite and array cache, contain synthetic data only, and sit outside default test discovery. The separate existing Redis harness uses its isolated temporary namespace as documented below. No production endpoint was exercised, and no application record or cache was cleared.

Reproduce the new observations from the service directory:

```sh
php artisan test docs/security-audits/RedisLoggingFollowupReviewTest.php --compact
```

Findings 6–7 are confirmed and remain unfixed in this repeat review.

## Original remediation status — five findings fixed locally

1. `LogContext` preserves absent user/tenant identifiers as `null` and accepts only positive integer identifiers from authenticated context. Request-body tenant identifiers no longer control log attribution. Tenantless Sanctum requests now save API, audit, and security records correctly. Audit/security deduplication markers are set only after successful persistence so recording failures allow the central fallback.
2. The request logger now wraps attack protection, after trusted-proxy resolution. Scanner blocks produce the API request record alongside the audit/security records, with matching request and trace IDs. Empty correlation headers are regenerated and oversized values are bounded to the database column limits.
3. Audit payloads are recursively redacted by a model save hook, covering helper calls and direct Eloquent saves. Persistence diagnostics include exception class and request ID rather than raw exception messages that could contain SQL values.
4. Security metadata uses the shared sensitive-key detector, preserving its `[REDACTED]` marker. Nested grant tokens, client keys, OTPs, and recovery codes are covered on creation and subsequent model saves.
5. Ban-index reads, bans, and unbans use the same cache lock. They prune entries without an active ban key, preserve other active bans, and forget the index when it becomes empty. Pruning occurs on index reads/updates; active entries retain the existing storage format and no migration is needed.

The fixes protect newly saved records; historical rows have not been rewritten or retroactively scrubbed.

### Remediation verification

| Check | Result |
| --- | --- |
| New normal-suite regressions | 19 passed; 111 assertions |
| Real Redis acceptance run | 21 passed; 234 assertions; includes the same 19 regressions plus Redis TTL/lock and authentication-cache checks |
| Existing focused logging/security/model checks | 32 passed; 260 assertions |
| Full suite with temporary S3 test configuration | 494 passed, 2 skipped; 3,222 assertions |
| `composer audit --locked --format=json` | Exit 0; no advisories or abandoned dependencies |
| Pint on changed PHP files | Passed |
| Whole-service Pint | 339 other files retain pre-existing style issues |
| `git diff --check` | Passed |

The first full run without S3 test settings reported two telemetry failures. Diagnostic runs located missing region/bucket configuration in the unchanged `InfrastructureTelemetryService` while constructing its S3 adapter. The successful full run supplied test-only values; `.env` and telemetry implementation files were preserved:

```sh
AWS_DEFAULT_REGION=us-east-1 AWS_BUCKET=codebridge-test php artisan test --compact
php artisan test tests/Feature/Security/RedisAndLoggingRemediationTest.php --compact
```

## Original runtime snapshot

| Check | Observed result |
| --- | --- |
| Actual auth container | PHP 8.4.27; phpredis extension installed |
| Effective cache store | `redis` |
| Redis connection | Successful PING through the configured cache connection |
| Cache Redis DB | 1 |
| Shared revocation Redis DB | 2 |
| `api_request_logs` | Table exists; 12 records at the read-only snapshot |
| `audit_logs` | Table exists; 5 records at the read-only snapshot |
| `security_events` | Table exists; 2 records at the read-only snapshot |
| Host PHP CLI | PHP 8.5.4 without the Redis extension; use the container for these Redis checks |

The three models persist records in the application's SQL database, not Redis. Redis stores cache/authentication state. Existing rows prove that some recording paths work; they do not prove every request is recorded.

## Working behavior verified

- Real Redis cache put/get/forget, positive TTLs, and lock acquisition/release.
- Single-use passkey reauthentication grants and 2FA challenges, including removal of the consumed grant and its lookup indices.
- Real Redis outlet and meeting settings cache invalidation and separation of tenant cache keys.
- Successful password login writes a redacted `ApiRequestLog` and an explicit `auth.login` audit with matching request/trace IDs.
- Invalid application keys produce both an API request record and a security event.
- Reauthentication HTTP 429 responses for an account with a workspace produce API request and security records.
- Existing outlet update, terminal-setting update, meeting-setting update/reset, and session-revocation tests pass. Those existing tests use array cache; the additional audit separately exercises Redis.

## Original findings (before remediation)

### 1. Medium: nullable tenant context causes silent loss of records

Locations: `app/Http/Middleware/LogRequestActivity.php:94`, `app/Models/Security/SecurityEvent.php:204`, and `app/Http/Middleware/AuthenticateJwt.php:331`.

For a valid Sanctum account without a workspace, authentication sets an `auth_tenant_id` request attribute to `null`. The logging fallback checks whether the attribute exists and casts its value to an integer, producing `0`. No tenant with ID 0 exists, so foreign-key checks reject the inserts. The recording helpers catch the exceptions and allow the original response to continue.

Reproductions with real Redis and SQLite foreign keys enabled:

- Five failed reauthentication requests still return HTTP 422 and the next returns HTTP 429, but `ApiRequestLog` records are absent. The expected 429 `SecurityEvent` is also absent. Captured warnings confirm foreign-key insertion failures.
- A successful tenantless Sanctum reauthentication returns HTTP 200, but its API request record and generic mutation audit are absent.
- The same rate-limit flow for an account with a workspace saves the expected API request and security records.

This is a record-persistence bug, not a failed Redis connection. It was reproduced for tenantless Sanctum requests; the review does not claim all JWT or tenantless requests fail.

Recommended fix: resolve only verified user/tenant context, preserve null values, and validate positive IDs before casting. Do not use an unverified request-body tenant ID to attribute forensic records. Set `_audit_logged` and `_security_event_recorded` only after a record is successfully saved, and surface recording failures through safe diagnostics.

### 2. Medium: early scanner blocks miss API request records and correlation

Locations: `bootstrap/app.php` middleware registration, `app/Http/Middleware/ProtectAttackMiddleware.php:241`, and `app/Http/Middleware/LogRequestActivity.php:61`.

Attack protection runs before the activity logger and can return HTTP 403 without invoking it. A scanner request with a supplied request ID creates `SecurityEvent` and `AuditLog` records but no corresponding `ApiRequestLog`.

Without incoming correlation headers, the security and audit writers independently generate different request and trace IDs. The probe confirms both IDs differ for the same blocked request.

Recommended fix: initialize correlation IDs and wrap request recording before middleware that can block requests, while preserving attack enforcement. Ensure blocked responses reach a common request-recording path without duplicate security events.

### 3. Medium: direct audit recording does not sanitize its payload

Location: `app/Models/Security/AuditLog.php`, `record()` payload assignment.

The HTTP activity logger sanitizes generic mutation payloads, but `AuditLog::record()` saves the caller's payload directly. A direct call with nested synthetic `password` and `grant_token` fields persists both values unchanged.

Impact depends on what individual callers pass to this helper. This probe establishes an unsafe shared recording boundary; it does not establish that a real user's password was exposed in existing records.

Recommended fix: apply the shared recursive redactor inside the audit recording boundary, and safely redact any diagnostic strings before emitting exceptions containing database values.

### 4. Medium: security metadata redaction misses token aliases

Location: `app/Models/Security/SecurityEvent.php`, `redactSensitiveData()`.

The model checks a fixed list of exact keys. The probe confirms that `password` is redacted while nested `grant_token` remains unchanged. Other aliases outside the exact list need the same review.

Recommended fix: reuse the shared sensitive-key detection for nested metadata, preserving the model's expected redaction marker where API compatibility requires it. Add tests for grant tokens, client keys, recovery codes, and nested aliases. No existing real metadata values were printed or inspected by this review.

### 5. Low: expired IP bans remain in a permanent Redis index

Locations: `app/Services/IpThreatDefenseService.php:159` and `getBannedIps()` at line 207.

Individual `sec_ip_ban:<ip>` keys expire, but `sec_banned_ips_index` is saved forever. `getBannedIps()` hides expired entries from its result without deleting them from the index. The probe creates an expired index entry with no active ban key and confirms it remains in the index, whose Redis TTL is `-1` (no expiry).

This does not keep the expired IP blocked. It retains stale index data indefinitely and can increase memory and scan cost as more distinct IPs are banned.

Recommended fix: prune expired index entries, or use an expiry-aware sorted-set index. Preserve concurrency safety when adding/removing entries. Cache values already protected by an appropriate TTL do not all require explicit `Cache::forget()` calls.

## Original audit results and test isolation

- Existing focused logging/cache/model tests: **19 passed, 120 assertions**.
- Original real Redis audit: **13 completed, 136 assertions**. Those probes confirmed the defects. They have now been converted into ordinary regressions requiring correct recording/redaction; the current real Redis harness inherits those same tests.
- The audit PHP file passes Pint and syntax checks.
- The audit uses PHPUnit's forced SQLite `:memory:` database and starts with array cache. It switches to Redis only after the base test setup, preventing the base `Cache::flush()` from reaching Redis.
- Redis tests use DB 15 and a fresh `cb-cache-log-audit-<UUID>:` prefix for each case. Revocation and lock connections are isolated under that prefix too. Teardown unlinks only that namespace and verifies it is empty. No application Redis database is flushed, no application database is reset, and no existing log records are deleted.
- Outgoing HTTP calls in the audit are faked. Live MySQL inspection was limited to table existence, counts, and latest-record timestamps; no record contents or credentials were printed.
- Earlier implementation changes and unrelated working-tree changes were preserved. All remediation edits are limited to `auth-service`.

Re-run from the host with Docker available:

```sh
docker exec -e RUN_REDIS_CACHE_AUDIT=1 codebridge-auth-service \
  php artisan test docs/security-audits/RedisCacheLoggingAuditTest.php --compact
```

The opt-in Redis harness remains outside normal test discovery because it requires real Redis. Its shared regression tests are in `tests/Feature/Security/RedisAndLoggingRemediationTest.php` and run in the default suite.

The original five findings remain fixed and covered by acceptance regressions. Findings 6–7 from the repeat review remain open. No application caches or databases were flushed or reset. No commits, pushes, or pull requests were made.
