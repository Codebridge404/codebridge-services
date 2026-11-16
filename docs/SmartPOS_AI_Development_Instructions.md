# SMARTPOS — AI DEVELOPMENT INSTRUCTIONS
Version: 2.0 | Date: 2026-11-16 | Project: CodeBridge SmartPOS Multi-Service Platform

---

## HOW TO USE THIS FILE
Give this file to your coding AI assistant when working on any service in the CodeBridge SmartPOS workspace.
Example requests:
* *"Follow SmartPOS_AI_Development_Instructions.md. Organize the controllers, requests, and resources in auth-service by feature. Preserve existing API behavior and run the relevant tests."*
* *"Follow SmartPOS_AI_Development_Instructions.md. Implement the new inventory stock adjustment feature in operations-service following the feature folder convention."*

These are implementation instructions and architectural standards. They do not authorize building unimplemented speculative features. Always implement only the feature or refactor requested in the active task.

---

## 1. GOAL AND SCOPE
Build a clean, maintainable, modular Laravel backend for CodeBridge SmartPOS.
* Use Laravel's established directory conventions and group related HTTP classes **by feature**.
* Start simple; introduce service layers or helper classes only when business logic demands them.
* Respect microservice boundaries: `auth-service` and `operations-service` are distinct services with separate databases.
* Keep all implementations strictly local on the developer workstation until an explicit git push command is given.

---

## 2. INSPECT BEFORE EDITING
Before modifying or creating any code:
1. **Read Workspace Rules**: Review `AGENTS.md` in both the workspace root and `codebridge-services/AGENTS.md`.
2. **Inspect Existing Environment**:
   * Inspect `composer.json` and the installed PHP (8.4) and Laravel (12) versions.
   * Inspect existing routes (`routes/api.php` and `routes/api/*.php`), controllers, requests, resources, models, and migrations.
3. **Verify API Contracts**: Check existing endpoints, request payloads, response envelopes, and test fixtures before altering signatures.
4. **Identify Service Ownership**: Determine whether the requested feature belongs in `auth-service` or `operations-service`.
5. **Acknowledge Service Boundaries & Read-Only Flags**:
   * If a service is designated read-only in `AGENTS.md`, do not edit it unless the user explicitly grants write permission.
6. **Report Missing Context**: Report missing dependencies or environment variables honestly; never fabricate test passes.

---

## 3. FEATURE-GROUPED ORGANIZATION RULE
Group files **by feature** within Laravel's standard architectural folders.
Do not dump all controllers into a single flat `Controllers/` directory.

### 3.1 Service 1: `codebridge-services/auth-service/`
```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php
│   │   ├── Auth/
│   │   │   ├── AuthController.php
│   │   │   ├── TwoFactorController.php
│   │   │   ├── PasskeyController.php
│   │   │   └── SessionController.php
│   │   ├── Organization/
│   │   │   ├── TenantController.php
│   │   │   ├── OutletController.php
│   │   │   └── SubscriptionController.php
│   │   ├── Meeting/
│   │   │   ├── MeetingController.php
│   │   │   ├── MeetingRoomController.php
│   │   │   ├── MeetingLogisticsController.php
│   │   │   ├── MeetingMinutesController.php
│   │   │   ├── MeetingActionItemController.php
│   │   │   └── MeetingTelegramController.php
│   │   ├── Crm/
│   │   │   ├── CustomerController.php
│   │   │   ├── LeadController.php
│   │   │   └── DealController.php
│   │   └── Security/
│   │       ├── SecurityEventController.php
│   │       ├── ApiRequestLogController.php
│   │       └── AuditLogController.php
│   ├── Requests/
│   │   ├── Auth/LoginRequest.php
│   │   ├── Organization/StoreOutletRequest.php
│   │   └── Meeting/StoreMeetingRequest.php
│   ├── Resources/
│   │   ├── Auth/UserResource.php
│   │   ├── Organization/OutletResource.php
│   │   └── Meeting/MeetingResource.php
│   └── Middleware/
├── Models/
│   ├── User.php
│   ├── Tenant.php
│   ├── Outlet.php
│   ├── Meeting.php
│   └── SecurityEvent.php
├── Services/                      [Optional, for multi-step domain logic]
│   ├── PasskeyService.php
│   └── TwoFactorService.php
└── Policies/
    ├── MeetingPolicy.php
    └── SecurityEventPolicy.php
```

### 3.2 Service 2: `codebridge-services/operations-service/`
```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php
│   │   ├── Pos/
│   │   │   ├── PosController.php
│   │   │   └── PosSessionController.php
│   │   ├── Product/
│   │   │   ├── ProductController.php
│   │   │   ├── CategoryController.php
│   │   │   └── ModifierController.php
│   │   ├── Order/
│   │   │   ├── OrderController.php
│   │   │   └── OrderItemController.php
│   │   ├── Payment/
│   │   │   ├── PaymentController.php
│   │   │   └── KhqrPaymentController.php
│   │   └── Inventory/
│   │       ├── InventoryController.php
│   │       └── StockMovementController.php
│   ├── Requests/
│   │   ├── Pos/CheckoutRequest.php
│   │   └── Product/StoreProductRequest.php
│   └── Resources/
│       ├── Pos/CartResource.php
│       └── Product/ProductResource.php
├── Models/
│   ├── Product.php
│   ├── Order.php
│   └── InventoryItem.php
└── Services/                      [Optional, for multi-step domain logic]
    ├── OrderProcessingService.php
    └── BakongSettlementService.php
```

---

## 4. CLASS RESPONSIBILITIES & STANDARDS

### Form Requests
* **Validation**: Define strict rules for all input parameters (`required`, `string`, `integer`, `exists`, etc.).
* **Sanitization**: Never allow raw, unvalidated `$request->all()` into models or queries.
* **Authorization**: Validate that the caller has access to the target tenant/outlet within the request:
  ```php
  public function authorize(): bool
  {
      return $this->user() !== null;
  }
  ```

### Controllers
* **Thin & Focused**: Controllers receive validated requests, call the domain model or service, and return JSON.
* **Unified Response Envelope**: Every endpoint must return the standard envelope:
  ```php
  return response()->json([
      'status' => 'success',
      'data' => new MeetingResource($meeting),
      'message' => 'Meeting scheduled successfully.',
      'request_id' => $request->header('X-Request-ID'),
  ], 201);
  ```
* Avoid raw multi-table transactions or heavy logic in controllers; delegate to services when steps exceed 15–20 lines.

### API Resources
* Explicitly transform Eloquent models into JSON arrays.
* Prevent N+1 queries by using `$this->whenLoaded('relation')`.
* Redact sensitive credentials (passwords, PINs, tokens) using `LogRedactor` or explicit omission.

### Models
* Protect against mass assignment using `$fillable`.
* Hide sensitive properties via `$hidden = ['password', 'two_factor_secret', 'pin_code']`.
* Define explicit casts for dates, booleans, arrays, and JSON fields.
* Implement local query scopes (e.g. `scopeForTenant($query, $tenantId)`).

### Services (Optional)
* Use services when an operation spans multiple tables or external APIs (e.g. Passkey Ed25519 verification, 2FA challenge consumption, KHQR payment settlement).
* Accept explicit types or arrays (`array $validatedData`), not raw `Request` objects.

---

## 5. NAMING AND NAMESPACE CONVENTIONS
* **Case**: Use strict PascalCase for class names and feature folders (e.g., `App\Http\Controllers\Meeting\MeetingRoomController`).
* **File Match**: Class name must match the filename exactly (e.g., `StoreMeetingRequest.php` defines `class StoreMeetingRequest`).
* **REST Actions**: Prefer standard action names: `index`, `store`, `show`, `update`, `destroy`.
* **Explicit Actions**: For non-CRUD endpoints, use descriptive action names (`login`, `logout`, `checkIn`, `endEarly`, `validateToken`, `exportIcs`).
* **No Vague Names**: Avoid `HelperController`, `GeneralService`, or `CommonUtil`.

---

## 6. ROUTES & REFACTORING INTEGRITY
* **Route Separation**: Keep route declarations in `routes/api/*.php` (e.g. `meetings.php`, `auth.php`, `pos.php`) and import them in `routes/api.php`.
* **Complete Imports**: Always import controllers with their full namespace:
  ```php
  use App\Http\Controllers\Meeting\MeetingController;
  ```
* **No Duplicate `/api` Prefixes**: Check route prefixes; never create nested `/api/api/v1/*` routes.
* **Refactoring Contract Guarantee**: Moving a controller must NEVER break:
  * Route URI paths (e.g. `/api/v1/meetings/{id}`)
  * HTTP methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`)
  * Route names (e.g. `meetings.show`)
  * Middleware attachments (`role:admin`, `auth:sanctum`)
  * Response JSON keys or status codes

---

## 7. MICROSERVICE BOUNDARIES & DATA INTEGRITY

| Capability | `codebridge-auth-service` | `codebridge-operations-service` |
| :--- | :--- | :--- |
| **Domain** | Identity, Auth, Outlets, Meetings, CRM | POS Cashier, Products, Orders, Payments, Inventory |
| **Database** | `codebridge_auth` (Port 3307) | `codebridge_operations` (Port 3308) |
| **Cache/Sessions** | Redis (DB 0 & 1) | Redis (DB 2 & 3) |
| **External Auth** | Issues JWTs & Public Keys | Verifies JWTs via shared secret / issuer |

### Critical Rules:
1. **No Cross-Database Queries**: Never execute a MySQL join or query across `codebridge_auth` and `codebridge_operations`.
2. **Stable External Identifiers**: Use UUIDs (e.g., `tenant_uuid`, `outlet_uuid`, `user_uuid`, `product_uuid`) for cross-service communication.
3. **Independent Deployment**: Each service has its own Dockerfile, CI/CD workflow, and K3s rollout script.

---

## 8. POS BEHAVIOR & TRANSACTION FINANCIAL SAFETY
* **Server-Side Calculations**: Sale totals, discounts, taxes, and change amounts must ALWAYS be calculated and validated on the backend. Never trust client-submitted math.
* **Money Precision**: Store currency in 2-decimal columns (`DECIMAL(14, 2)` or `BIGINT` cents).
* **Local Transactions**: Wrap multi-table operations in `DB::transaction()`:
  ```php
  DB::transaction(function () use ($orderData) {
      $order = Order::create(...);
      $order->items()->createMany(...);
      InventoryItem::decrementStock(...);
  });
  ```
* **Collision & Race Condition Defense**: Use pessimistic row-level locking (`->lockForUpdate()`) for room reservations, stock allocations, and wallet deductions.

---

## 9. SECURITY & FORENSIC LOGGING
* **Multi-Tenant Scoping**: Every query touching tenant data must be scoped by the authenticated user's `tenant_id`.
* **Credential Protection**:
  * Passwords must use `Hash::make()` with bcrypt or Argon2id.
  * POS PINs must be securely hashed.
  * Never return or log plain-text passwords, PINs, tokens, recovery codes, or provider secrets.
* **Tripartite Forensic Logging**:
  * Every request &rarr; `api_request_logs` (capturing latency, status, route).
  * Every successful mutation &rarr; `audit_logs` (capturing action, entity, user).
  * Every threat, 401, 403, 429 &rarr; `security_events` (capturing threat type, severity, rule code).

---

## 10. VERIFICATION & QUALITY ASSURANCE

Before concluding any implementation or refactor, execute the project's quality checks:

```bash
# 1. Check Route Registrations
php artisan route:list --path=api/v1

# 2. Run Static Analysis & Linter
./vendor/bin/pint --test

# 3. Execute Relevant Automated Tests
php artisan test --filter=Meeting
php artisan test --filter=Pos

# 4. Check for Security Vulnerabilities
composer audit
```

---

## 11. MANDATORY DATABASE SEEDING RULE
Whenever the database is reset or wiped (`migrate:fresh`, `db:wipe`), the application must ALWAYS be immediately re-seeded:
```bash
php artisan db:seed
```
The seeders must ALWAYS preserve the core demo platform accounts:
* **Super Admin**: `codebridge2026@gmail.com` / `Code@2026`
* **Demo Organization**: `Sunny Cafe Phnom Penh` (`admin@sunnycafe.kh` / `Password123!`)
* **Outlets & Cashier**: Toul Kork main branch with cashier account and POS PIN `1234`
* **Staff Invitation**: `invited.barista@sunnycafe.kh` (Token: `demo_invite_token_sunny_cafe_2026`)
* **Payment Provider Accounts**: NBC Bakong KHQR, Stripe, ABA PayWay
* **Meeting Management (MMS)**: 4 Boardrooms, active meetings, AV materials, and Telegram bot configuration

---

## 12. STRICT GIT PUSH POLICY
* **NEVER push code to GitHub automatically** (`git push`, publishing remote branches, or opening PRs).
* All changes, implementations, and tests must remain **STRICTLY LOCAL** on the machine.
* Pushing to GitHub is ONLY permitted when the user gives an explicit, direct command in their prompt (e.g., *"push to github now"*).

---

## 13. COMMUNICATION PREFERENCE
* Use clear, simple, professional English.
* When the user writes a question in English, briefly provide a corrected or polished version before answering when helpful.
* Keep grammar feedback supportive, gentle, and secondary to solving the technical task.
