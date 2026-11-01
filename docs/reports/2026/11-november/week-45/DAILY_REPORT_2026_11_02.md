# Daily Engineering Progress Report — November 2, 2026

**Project:** CodeBridges Enterprise Multi-Service Platform  
**Active Services:** `gateway`, `auth-service`, `operations-service`, `minio`, `infrastructure/nginx-proxy-manager`  
**Git Branch:** `demo`  
**Deployment Infrastructure:** Kubernetes (K3s v1.36.5) & Docker Engine 28.0 (Ubuntu 24.04 VPS: `217.216.110.153`)  
**Security Status:** Hardened WAF Active · 0 Vulnerabilities · Strict Let's Encrypt TLS Verified  

---

## 1. Executive Summary

Today's engineering initiatives completed the transition of the CodeBridges Staging infrastructure from standalone Docker Compose to a resilient, production-ready **Kubernetes (K3s)** architecture, coupled with security audits, SSL certificate fixes, and Nginx Proxy Manager (NPM) modernization:

1. **Kubernetes (K3s) Staging Stack Rollout**: Deployed the complete staging workload under namespace `codebridge-staging` using `./k8s/staging/deploy.sh`. Configured MySQL 8.4 StatefulSets (`auth-mysql-0`, `operations-mysql-0`), Redis 8 Deployments, MinIO Object Storage, Horizon Queue Workers, Laravel Scheduler CronJobs, Nginx Gateway, and Traefik Ingress.
2. **Local Image Distribution Pipeline**: Established a local Docker registry on `127.0.0.1:5000` enabling unprivileged K3s containerd runtimes to consume custom microservice images (`codebridge-auth-service:staging`, `codebridge-operations-service:staging`, `codebridge-gateway:staging`, and MinIO) without external rate-limiting or authentication scope failures.
3. **Database Data Preservation & Restoration**: Restored all customer, tenant, auth, and inventory database tables from previous Docker Compose backup snapshots into Kubernetes StatefulSets, followed by zero-error verification via Kubernetes migration jobs.
4. **Resolution of Browser SSL Warnings (`net::ERR_CERT_AUTHORITY_INVALID`)**: Replaced temporary self-signed ingress certificates with official, trusted **Let's Encrypt** certificates extracted from NPM and bound into Kubernetes TLS secrets (`staging-docs-tls`, `staging-api-tls`, `staging-cdn-tls`).
5. **MinIO S3 CDN & Console Re-Routing**: Fixed public access to `https://staging-codebridge-cdn.servicefixit.me/` by redirecting the ingress backend to MinIO Web Console port `9001` (eliminating raw S3 API `403 AccessDenied` errors on browser navigation).
6. **Nginx Proxy Manager Modernization (`v2.16.0`)**: Updated the NPM service definition to official release `jc21/nginx-proxy-manager:2.16.0`. Started the container and verified the admin dashboard at `http://217.216.110.153:81/nginx/proxy`.
7. **Git Security & Credential Hygiene**: Updated [`.gitignore`](file:///.gitignore) to exclude `secrets.yaml` and `**/secrets.yaml`, safely untracking `k8s/staging/secrets.yaml` from the git index while preserving on-disk operational configurations.
8. **Hardened Web Application Firewall (WAF) Layer**: Injected automated attack blockers directly into the Kubernetes Gateway Nginx ConfigMap, successfully rejecting automated scanners (sqlmap, nikto, gobuster), GraphQL introspection probes (Mass ATO prevention), and PHP2Shell session upload exploit signatures with HTTP 403.

---

## 2. Platform Architecture & Traffic Routing

```mermaid
flowchart TD
    User["Public Client / Web Browser"] --> CF["DNS / Cloudflare Layer (servicefixit.me)"]
    
    subgraph Host Network (217.216.110.153)
        CF -->|HTTP :80 / HTTPS :443| Ingress["Traefik K3s Ingress Controller<br/>Let's Encrypt SSL Termination"]
        CF -->|Direct HTTP :81| NPM["Nginx Proxy Manager v2.16.0 (:81)<br/>Admin GUI Dashboard"]
    end

    subgraph Kubernetes Namespace: codebridge-staging
        Ingress -->|api / health| GW["API Gateway (:80)<br/>Hardened WAF & Rate Limiting"]
        Ingress -->|docs| Docs["Interactive Stoplight Docs Hub<br/>(/docs & /docs/api.json)"]
        Ingress -->|cdn| MinIO["MinIO S3 & Web Console (:9001)<br/>Bucket Management & CDN Assets"]
        Ingress -->|npm| NPMSvc["NPM Dashboard Ingress Service (:81)"]
        
        GW -->|FastCGI :9000| Auth["Auth Microservice (PHP-FPM)"]
        GW -->|FastCGI :9000| Ops["Operations Microservice (PHP-FPM)"]
        
        Auth --> AuthDB[("auth-mysql-0<br/>MySQL 8.4")]
        Auth --> AuthRedis[("auth-redis<br/>Redis 8")]
        
        Ops --> OpsDB[("operations-mysql-0<br/>MySQL 8.4")]
        Ops --> OpsRedis[("operations-redis<br/>Redis 8")]
        Ops -.->|Cross-DB Reads| AuthDB
    end
```

---

## 3. Workload Verification & Operational Health

### A. Docker Containers (Host VPS)

| Container Name | Image | Status | Published Ports |
| :--- | :--- | :---: | :--- |
| `codebridge-nginx-proxy-manager` | `jc21/nginx-proxy-manager:2.16.0` | `Up` | `0.0.0.0:80-81->80-81/tcp, :443->443/tcp` |
| `registry` | `registry:2` | `Up` | `127.0.0.1:5000->5000/tcp` (Internal CRI distribution) |

### B. Kubernetes Pods (`codebridge-staging`)

| Pod Name | Ready | Status | Restarts | Role |
| :--- | :---: | :---: | :---: | :--- |
| `pod/auth-service-6984bd7c7b-t7j5g` | `1/1` | `Running` | 0 | Auth & Identity API (PHP-FPM) |
| `pod/operations-service-55b94f7ff6-2vb6g` | `1/1` | `Running` | 0 | Commerce & Operations API (PHP-FPM) |
| `pod/gateway-56765b97dc-9zg4s` | `1/1` | `Running` | 0 | Unified Nginx WAF Gateway |
| `pod/minio-559fc879cf-hsvlx` | `1/1` | `Running` | 0 | S3 Storage & Console Server |
| `pod/auth-horizon-66ddc9fb98-4b6p4` | `1/1` | `Running` | 0 | Auth Asynchronous Queue Worker |
| `pod/operations-horizon-84f5c7f878-s6gh7` | `1/1` | `Running` | 0 | Operations Queue Worker |
| `pod/auth-mysql-0` | `1/1` | `Running` | 0 | Dedicated Auth Database (StatefulSet) |
| `pod/operations-mysql-0` | `1/1` | `Running` | 0 | Dedicated Operations DB (StatefulSet) |
| `pod/auth-redis-6db6b67946-wzlb2` | `1/1` | `Running` | 0 | Auth Cache & Session Store |
| `pod/operations-redis-576b794478-hk9zb` | `1/1` | `Running` | 0 | Operations Cache & Lock Store |
| `pod/auth-scheduler-29892626-svtkc` | `0/1` | `Completed` | 0 | Recurring Artisan Scheduler CronJob |
| `pod/auth-migration-job-5twdc` | `0/1` | `Completed` | 0 | Zero-downtime DB Schema Validator |
| `pod/operations-migration-job-8jrbc` | `0/1` | `Completed` | 0 | Zero-downtime DB Schema Validator |
| `pod/minio-provision-buckets-bwmc7` | `0/1` | `Completed` | 0 | S3 Bucket & Policy Provisioner |

---

## 4. Security Verification Audit

A comprehensive live security test was executed against all exposed endpoints.

```text
======================================================================
🛡️ CODEBRIDGE SECURITY & WORKLOAD TEST SUITE RESULTS
======================================================================
```

| Security Vector | Test Probe Command | Response | Status | Protection Mechanism |
| :--- | :--- | :---: | :---: | :--- |
| **Malicious Scanner** | `curl -A "sqlmap/1.5#stable" https://.../health` | `HTTP/2 403` | **PASS** | WAF User-Agent signature rejection |
| **Automated Assessment** | `curl -A "nikto/2.1.6" https://.../health` | `HTTP/2 403` | **PASS** | WAF scanner blocking map |
| **GraphQL Mass ATO** | `curl https://.../graphql` | `HTTP/2 403` | **PASS** | Introspection & batch query endpoint block |
| **PHP2Shell Exploit** | `curl "https://...?PHP_SESSION_UPLOAD_PROGRESS=test"` | `HTTP/2 403` | **PASS** | Query-string session hijack blocker |
| **Sensitive File Probe** | `curl https://.../.env` | `HTTP/2 404` | **PASS** | Direct dotfile & secret extension block |
| **Backup Script Probe** | `curl https://.../backup.sh` | `HTTP/2 404` | **PASS** | Shell script download prevention |
| **CORS Access Control** | `curl -H "Origin: https://staging-codebridge-docs..."` | `HTTP/2 200` | **PASS** | Dynamic `Access-Control-Allow-Origin` |
| **Strict SSL Certificate** | `curl --head https://staging-codebridge-api...` | `HTTP/2 200` | **PASS** | Valid Let's Encrypt CA validation (no `-k`) |
| **Strict SSL Certificate** | `curl --head https://staging-codebridge-docs...` | `HTTP/2 200` | **PASS** | Valid Let's Encrypt CA validation (no `-k`) |
| **Strict SSL Certificate** | `curl --head https://staging-codebridge-cdn...` | `HTTP/2 200` | **PASS** | Valid Let's Encrypt CA validation (no `-k`) |

### Security Headers Enforced:
```http
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

---

## 5. Live Public Endpoints Reference

| Service Name | Public URL | Protocol | Authentication / Access |
| :--- | :--- | :---: | :--- |
| **API Gateway** | [https://staging-codebridge-api.servicefixit.me](https://staging-codebridge-api.servicefixit.me) | `HTTPS` | Public / Bearer Token Protected |
| **Health Check** | [https://staging-codebridge-api.servicefixit.me/api/v1/health](https://staging-codebridge-api.servicefixit.me/api/v1/health) | `HTTPS` | Public Monitoring Endpoint |
| **Documentation Hub** | [https://staging-codebridge-docs.servicefixit.me/docs](https://staging-codebridge-docs.servicefixit.me/docs) | `HTTPS` | Public Interactive OpenAPI / Scramble |
| **MinIO S3 Console** | [https://staging-codebridge-cdn.servicefixit.me/](https://staging-codebridge-cdn.servicefixit.me/) | `HTTPS` | Admin User: `codebridge_staging_admin` |
| **NPM Dashboard** | [http://217.216.110.153:81/nginx/proxy](http://217.216.110.153:81/nginx/proxy) | `HTTP` | Admin GUI Credentials |
| **NPM Subdomain (IPv4)** | [http://npm.servicefixit.me/](http://npm.servicefixit.me/) | `HTTP` | Proxying to VPS Port 81 |

> [!NOTE]
> **Action Required in Hostinger DNS:**
> An old `AAAA` (IPv6) record exists for `npm.servicefixit.me` pointing to `2a02:4780:6:1509:0:23f1:7bf2:2` (Hostinger's default shared hosting). Delete this `AAAA` record in Hostinger hPanel so browser traffic routes exclusively to the VPS `A` record (`217.216.110.153`).

---

## 6. Security Review Remediation & Vulnerability Hardening

Following a comprehensive static code security review of commit `2afb565`, all five identified vulnerabilities and high-priority risks were systematically remediated, validated, and hardened:

### 1. Critical: Multi-Tenant Boundary Enforcement & X-Tenant-Id Spoofing Mitigation
- **Vulnerability**: Client-supplied `X-Tenant-Id` previously took precedence over `$request->user()->tenant_id` in `ProductController`, allowing cross-tenant product updates and deletes. The update query also allowed `orWhereNull('tenant_id')`.
- **Remediation**:
  - Implemented [`App\Traits\ResolvesTenantContext`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/operations-service/app/Traits/ResolvesTenantContext.php) across all Operations controllers (`ProductController`, `CategoryController`, `InventoryController`, `TransferController`, `PurchaseController`, `FinanceController`, `ExchangeRateController`, `ReconciliationController`).
  - Strict Server-Side Identity: Client-supplied `X-Tenant-Id` or `?tenant_id=` is rejected with **HTTP 403 Forbidden** if it does not match the authenticated user's assigned `tenant_id` (only `super_admin` retains cross-tenant management scopes).
  - Exact Ownership Validation: Every mutation (`update`, `destroy`, `uploadImage`) executes `authorizeTenantResource()`, verifying that the resource's `tenant_id` matches the current tenant. Modification of global template records (`tenant_id = null`) by non-superadmins is strictly blocked.

### 2. High: Catalog Route Protection & Product Export Lockdown
- **Vulnerability**: `/api/v1/products/export` was unauthenticated and skipped tenant scoping when `tenant_id` was absent or set to `all`, leaking business product costs across organizations.
- **Remediation**:
  - Moved `/api/v1/products/export` into the protected `auth:sanctum` middleware group in [`routes/api/catalog.php`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/operations-service/routes/api/catalog.php).
  - Reordered routes: Declared `/products/export` before wildcard parameter route `/products/{id}` with `whereNumber('id')`, preventing route shadowing.
  - Enforced strict tenant condition on `ProductExportController::export` (only `super_admin` can export cross-tenant).
  - Data Minimization on Public Catalog: When unauthenticated visitors access public storefront products, internal accounting columns (`cost_price`) are automatically stripped.

### 3. High: Operations Distributed JWT Token Purpose & Revocation Enforcement
- **Vulnerability**: `AuthenticateJwt` accepted any signed token without checking `token_use` (allowing refresh tokens as access tokens), session revocation, or configured issuer.
- **Remediation**:
  - Token Purpose Gate: Enforced `token_use === 'access'` in [`AuthenticateJwt`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/operations-service/app/Http/Middleware/AuthenticateJwt.php). Explicitly rejects refresh tokens with `HTTP 401 INVALID_TOKEN_PURPOSE`.
  - Issuer Validation: Validates `iss === config('jwt.issuer')` in [`JwtService::decode()`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/operations-service/app/Services/JwtService.php). Rejects untrusted issuers with HTTP 401.
  - Revocation & Session Blacklist: Integrates cache checks for `revoked_jwt:{jti}`, `revoked_user_sessions:{userId}`, and `session_version:{userId}` to instantly invalidate logged-out or deactivated accounts.

### 4. High: Elimination of Hardcoded Fallback JWT Secret
- **Vulnerability**: `operations-service/config/jwt.php` contained a public fallback secret string `'codebridge_enterprise_stateless_jwt_secret_key_2026_secured'`.
- **Remediation**:
  - Removed fallback secret in [`config/jwt.php`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/operations-service/config/jwt.php) (`'secret' => env('JWT_SECRET')`).
  - Added startup guard in `JwtService::__construct`: Throws a `RuntimeException` if `JWT_SECRET` is missing or fewer than 32 characters, preventing insecure execution.

### 5. Medium: Registration Validation & Account Enumeration Mitigation
- **Vulnerability**: `RegisterRequest` lacked email format and length rules. Different error messages were returned for existing emails vs. phone numbers.
- **Remediation**:
  - Added strict email format (`email:rfc`), max length (`max:255`), and phone regex validation in [`RegisterRequest.php`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/auth-service/app/Http/Requests/Auth/RegisterRequest.php).
  - Normalized email addresses (`strtolower(trim($email))`).
  - Unified rejection response: Replaced distinct error messages with a single message: `"The provided email address or phone number is already registered."`, mitigating account enumeration attacks.

### 6. Additional Query Parameter Fix: Unified Search
- Supported both `?q=` and `?search=` query parameters across catalog endpoints with trimmed PDO parameter binding in `ProductController::index`.

---

## 7. Quality Assurance & Regression Verification

Platform testing policy suite [`test_all_policies.sh`](file:///Users/macbookpro/Projects/codebridge/test_all_policies.sh) executed with 100% success:

| Microservice | Enforced Quality Rules | Tests Executed | Assertions | Failures | Composer CVEs | Result |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Auth Service** | 10 / 10 Rules | 181 Tests | 850 Assertions | 0 | 0 | **PASS** |
| **Operations Service** | 9 / 9 Rules | 40 Tests | 116 Assertions | 0 | 0 | **PASS** |
| **Platform Total** | **19 / 19 Rules** | **221 Tests** | **966 Assertions** | **0** | **0** | **PASS** |

---

## 8. GitHub Actions CI/CD Pipeline Modernization

Updated [`.github/workflows/deploy-staging.yml`](file:///Users/macbookpro/Projects/codebridge/codebridge-services/.github/workflows/deploy-staging.yml) with robust operational controls:
- **Fast-Forward Git Synchronization**: Configured `git pull --ff-only origin demo` to prevent accidental non-linear merge commits on the staging VPS host.
- **Fail-Fast Environment Verification**: Added pre-deployment validation for required staging environment files (`compose.env`, `auth.env`, `operations.env`) before triggering container builds or schema migrations.
- **Automated K8s Secret Fixture Provisioning for CI**: Added automatic copy of `k8s/staging/secrets.example.yaml` to `k8s/staging/secrets.yaml` during CI build validation so `kubectl kustomize` passes cleanly without committing actual secrets to Git.
- **Explicit Execution Rights & Health Auditing**: Automatic permission elevation (`chmod +x deploy/staging/deploy.sh deploy/staging/status.sh`) and container health verification (`status.sh`) executed on every successful deployment.



