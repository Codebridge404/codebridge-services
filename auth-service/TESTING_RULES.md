# CodeBridges Enterprise Testing & Pre-Push Quality Assurance Rules

This document establishes the mandatory quality assurance gates and testing rules that must be verified before any code is committed or pushed to GitHub.

---

## Part 1: Required Testing Levels

| Level | Description | Scope in CodeBridges |
| :--- | :--- | :--- |
| **Unit Testing** | Test individual methods, classes, and isolated pure logic. | Custom validation rules, token encoders, helper functions, DTOs, data transformers. |
| **Feature Testing** | Test Laravel features and multi-step business logic workflows. | Registration, login with 2FA, tenant onboarding, subscription quota checks, organization hierarchy. |
| **API Testing** | Test endpoints, HTTP methods, headers, request contracts, responses, and status codes. | API responses (200, 201, 204, 400, 401, 403, 404, 422, 429), JSON envelope structures, and pagination headers. |
| **Integration Testing** | Test interaction with databases, caches, queues, and object storage. | MySQL transactions, Redis locks, session revoking, job dispatching via Horizon, MinIO S3 avatar uploads. |
| **End-to-End (E2E)** | Test complete user flows from end to end. | User registration $\rightarrow$ Email verification $\rightarrow$ Login $\rightarrow$ 2FA enrollment $\rightarrow$ Workspace switch $\rightarrow$ API key generation $\rightarrow$ Logout. |
| **Security Testing** | Test authentication security, authorization controls, and exploit resistance. | SQL injection, XSS sanitization, JWT signature tampering, IDOR prevention, rate limiting, and strict multi-tenant isolation. |
| **Performance / Load**| Test response time, throughput, and concurrent request behavior. | API p95 response time $< 200\text{ms}$, Redis caching of tenant configurations, connection pooling. |
| **Regression Testing**| Ensure previously resolved bugs and historical features do not break. | Dedicated regression suites (`ReviewRegressionTest.php`) verifying fixed P1 security and deployment defects. |
| **Acceptance (UAT)** | Verify that business requirements and ERD specifications are honored. | Tenant resource limits, cashier PIN verification, receipt configurations, store credit ledgers. |

---

## Part 2: The 9 Mandatory Pre-Push Verification Rules

Every code change must satisfy all 9 rules before pushing to GitHub:

### 1. Functional Testing
- Every API endpoint must perform its documented business task correctly.
- Return payload must conform to enterprise JSON schema conventions (`status`, `data`, `message`, `timestamp`).

### 2. Validation Testing
- Reject missing required parameters with HTTP 422 Unprocessable Content.
- Reject invalid email formats, short passwords, alphabetic POS pins, and malformed UUIDs.
- Validation errors must return structured error arrays (`errors: { field: ["error"] }`).

### 3. Authentication Testing
- Login requires valid credentials; reject invalid credentials with HTTP 401.
- Access token must carry `token_use=access`; reject refresh tokens on standard endpoints.
- 2FA-enabled accounts must return a challenge token and strictly deny full access tokens until TOTP is verified.
- Logout must revoke the active token and invalidate all sessions when requested.

### 4. Authorization Testing
- Endpoints must enforce role-based access control (RBAC).
- Standard users cannot access `/api/v1/super-admin` or other tenant resources (HTTP 403 Forbidden).
- Expired or suspended tenant memberships must be denied workspace data access.

### 5. Security Testing
- **SQL Injection**: Input with `' OR 1=1 --` must never bypass authentication or alter database queries.
- **XSS**: Script tags and HTML payloads must be stripped or safely encoded.
- **Token Tampering**: Tokens signed with an invalid secret or expired timestamp must return HTTP 401.
- **Tenant Isolation**: Cross-tenant IDOR attacks (modifying another tenant's outlet, role, or user) must return 403/404.
- **Credential Redaction**: Passwords, secrets, and API keys must be scrubbed from audit logs.
- **Dependency Audit**: `composer audit` must report **0 security vulnerabilities**.

### 6. Integration Testing
- MySQL database transactions must maintain ACID consistency across migrations.
- Redis cache, rate limiting counters, and session revocations must work seamlessly.
- MinIO S3 avatar uploads must convert images to WebP format.

### 7. Performance & Latency Testing
- Authenticated endpoints must respond with optimal query efficiency (avoid $N+1$ query issues via eager loading).
- Heavy read endpoints must leverage Redis caching.

### 8. Load & Stress Stability
- Rate limiters must activate under high concurrency (e.g. 5 req/sec on login endpoints) returning HTTP 429 Too Many Requests.
- Database connection pools must not exhaust under parallel connection spikes.

### 9. Regression Testing
- All previously reported issues must have an accompanying automated test that passes.
- Code changes must never break existing passing tests.

---

## Part 3: Automated Execution Before Git Push

Before running `git push`, execute the automated pre-push checker:

```bash
cd codebridge-services/auth-service
./check_rules.sh
```

Or via composer:
```bash
composer check-rules
```

If any check fails, **the push is blocked immediately**. Fix the reported failure and re-run.

---

## Part 4: Optional Git Pre-Push Hook Setup

To automate this gate every time `git push` is executed from your terminal:

```bash
# Link the rule checker as git pre-push hook
ln -sf ../../codebridge-services/auth-service/check_rules.sh .git/hooks/pre-push
chmod +x .git/hooks/pre-push
```
