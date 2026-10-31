# Service Architecture & Quality Assurance Rules

## Allowed Services for Code Updates
All services in `codebridge-services/` are **ALLOWED (Read & Write)** for code updates, features, tests, and configuration:
- **`auth-service/`**: Authentication, JWT, Multi-Tenancy, RBAC, CRM, Outlets, Meetings, Store Credit.
- **`operations-service/`**: Core operations, inventory, POS transactions, order processing, reporting.
- **`gateway/`**: API Gateway, Nginx reverse proxy, WAF protection, rate limiting, routing.
- **`../codebridgesfront/`**: Frontend applications (admin dashboard, merchant portal, cashier POS client).
- **`../pos-docs/`**: API documentation, architecture diagrams, and technical specifications.

---

## Mandatory Quality Assurance & Testing Rules (All Services)
Before committing or pushing code to GitHub for any service, all 9 testing and security rules must be satisfied:

1. **Unit Testing**: Test methods, classes, and domain models in isolation.
2. **Feature Testing**: Test service features and business workflows.
3. **API Testing**: Validate endpoints, request payloads, response envelopes, and HTTP status codes.
4. **Integration Testing**: Verify correct integration with MySQL, Redis cache/sessions, Horizon queues, and MinIO S3 media.
5. **End-to-End (E2E) Testing**: Validate end-to-end user flows across gateway, auth, and operational domains.
6. **Security Testing**: Enforce JWT validation, 2FA reauthentication, strict tenant isolation, SQL injection prevention, XSS mitigation, audit redaction, and `composer audit = 0 vulnerabilities`.
7. **Performance Testing**: Ensure response times remain optimal (< 200ms) with eager loading and Redis caching.
8. **Load & Stress Testing**: Ensure rate limiting (HTTP 429) triggers gracefully without service crashes under high traffic.
9. **Regression Testing**: Ensure all existing features and historical security/deployment defect fixes remain passing.
