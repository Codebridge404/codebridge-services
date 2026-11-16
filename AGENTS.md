# Service Architecture & Quality Assurance Rules

## Strict Git Push Policy: NO AUTOMATIC PUSH TO GITHUB
- **NEVER push code to GitHub automatically** (`git push`, publishing remote branches, or opening PRs).
- All changes, implementations, and tests must remain **STRICTLY LOCAL** on the user's machine.
- Pushing to GitHub is ONLY permitted when the user gives an explicit, direct command in their prompt (e.g., *"push to github now"*).

---

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

---

## Mandatory Database Seeding Rule on Data Reset
- **ALWAYS re-seed after deleting or wiping data**: Whenever database data is reset, wiped, refreshed (`migrate:fresh`, `db:wipe`, or when manual/test cleanup deletes demo records), the application database must ALWAYS be immediately re-seeded using `php artisan db:seed`.
- **Preserve Essential Demo Data**: The seeders must always restore:
  - **Super Admin**: `codebridge2026@gmail.com` / `Code@2026`
  - **Demo Organization**: `Sunny Cafe Phnom Penh` (`admin@sunnycafe.kh` / `Password123!`)
  - **Outlets & Cashier**: Toul Kork main branch with cashier account and POS PIN `1234`
  - **Staff Invitation**: `invited.barista@sunnycafe.kh` with active token `demo_invite_token_sunny_cafe_2026`
  - **Subscription Plans**: Free, Pro / Business Runner, Enterprise
  - **Payment Provider Accounts**: NBC Bakong KHQR, Stripe, ABA PayWay
  - **Meeting Management (MMS)**: 4 Boardrooms, active meetings, AV materials, and Telegram bot configuration

---

## SmartPOS — AI Development Instructions (v2.0)

### 1. Goal and Scope
Build a clear, maintainable, modular Laravel backend for SmartPOS. Use Laravel's existing directory conventions and group related HTTP classes by feature. Start simple and introduce additional layers only when needed.

### 2. Inspect Before Editing
- Read applicable AGENTS.md files and project documentation.
- Inspect `composer.json`, installed Laravel/PHP versions, routes, controllers, requests, resources, models, migrations, and existing tests.
- Identify the service and feature affected by the user's task.
- Inspect existing API contracts and examples before changing code.
- Reuse existing response helpers, authentication, and naming conventions.
- Treat the actual repository and approved requirements as authoritative.
- Report missing context honestly. Do not claim to inspect unavailable code.
- Respect explicit read-only boundaries and unrelated user changes.

### 3. Organization Rule: Feature-Grouped Folders
Group files by feature WITHIN Laravel's normal folders:

#### In `codebridge-services/auth-service/`:
```text
app/Http/Controllers/
  Auth/ (AuthController, TwoFactorController, PasskeyController, SessionController)
  Organization/ (TenantController, OutletController, SubscriptionController)
  Meeting/ (MeetingController, MeetingRoomController, MeetingLogisticsController, MeetingMinutesController, MeetingActionItemController, MeetingTelegramController)
  Crm/ (CustomerController, LeadController, DealController)
  Security/ (SecurityEventController, ApiRequestLogController, AuditLogController)

app/Http/Requests/
  Auth/LoginRequest.php
  Organization/StoreOutletRequest.php
  Meeting/StoreMeetingRequest.php

app/Http/Resources/
  Auth/UserResource.php
  Organization/OutletResource.php
  Meeting/MeetingResource.php

app/Models/ (User, Tenant, Outlet, Meeting, SecurityEvent)
app/Services/ (PasskeyService, TwoFactorService) [when domain logic spans multiple steps]
app/Policies/ (MeetingPolicy, SecurityEventPolicy)
```

#### In `codebridge-services/operations-service/`:
```text
app/Http/Controllers/
  Pos/ (PosController, PosSessionController)
  Product/ (ProductController, CategoryController, ModifierController)
  Order/ (OrderController, OrderItemController)
  Payment/ (PaymentController, KhqrPaymentController)
  Inventory/ (InventoryController, StockMovementController)

app/Http/Requests/
  Pos/CheckoutRequest.php
  Product/StoreProductRequest.php

app/Http/Resources/
  Pos/CartResource.php
  Product/ProductResource.php

app/Models/ (Product, Order, InventoryItem)
app/Services/ (OrderProcessingService, BakongSettlementService)
```

### 4. Class Responsibilities
- **Form Requests**: Validate input, authorize actions, and provide validated input arrays to controllers.
- **Controllers**: Thin controllers that receive validated requests, invoke actions/services, and return standard `{status, data, message, request_id}` envelopes.
- **Services (Optional)**: Coordinate complex business operations. Accept explicit arrays/arguments, not HTTP requests.
- **Models**: Represent owned database records, casts, relationships, and scopes. Guard mass assignment and hide sensitive secrets.
- **API Resources**: Define public response fields, prevent N+1 queries on relationships, and preserve response envelopes.
- **Policies / Middleware**: Enforce authorization and tenant boundaries.

### 5. Naming and Namespaces
- Use PascalCase for classes and feature folders (e.g., `App\Http\Controllers\Meeting\MeetingRoomController`).
- Match class name and file name exactly.
- Prefer standard REST actions: `index`, `store`, `show`, `update`, `destroy` and explicit actions (`login`, `logout`, `checkIn`, `endEarly`, `validateToken`).

### 6. Routes and Refactoring Integrity
- Preserve URLs, HTTP methods, middleware, route names, status codes, and response shapes during refactoring.
- Keep `routes/api.php` and `routes/api/*.php` organized and import controllers with complete namespaces.
- Do not add duplicate `/api` prefixes.
- Update tests and API doc annotations whenever moving classes.

### 7. Microservice Boundaries
- `auth-service`: Authentication, JWT, multi-tenancy, RBAC, users, sessions, meetings, outlets, CRM.
- `operations-service`: Core POS operations, catalog, orders, sales, payments, inventory.
- Never directly query or write across service database boundaries.
- Use stable external identifiers (UUIDs) across service boundaries.

### 8. Data and POS Behavior
- Keep sale totals, discounts, taxes, and stock quantities validated on the server.
- Follow existing currency precision rules (`DECIMAL(14, 2)`).
- Use local database transactions (`DB::transaction()`) for multi-step mutations.
- Keep stock operations auditable and idempotent.

### 9. Security Expectations
- Always scope tenant and business queries using authenticated context (`tenant_id`).
- Hash passwords and POS PINs securely.
- Never log or return passwords, PINs, tokens, recovery codes, or provider secrets.
- Enforce rate limits and parameterized queries to mitigate SQL injection.

### 10. Verification & Quality Gate
- Inspect route registration (`php artisan route:list`) after moving controllers.
- Run formatters/linters (`./vendor/bin/pint --test`).
- Run existing feature, unit, and regression tests (`php artisan test`).
- Ensure `composer audit` reports 0 vulnerabilities.

### 11. Communication Preference
- Use clear, simple English.
- When the user asks a question in English, provide a brief, polite correction when helpful, keeping grammar feedback secondary to completing the task.
