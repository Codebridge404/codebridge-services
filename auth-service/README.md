# Auth Microservice (`auth-service`)

The **Auth Microservice** is responsible for user identity management, Laravel Sanctum token authentication, Role-Based Access Control (RBAC), user profile retrieval, and supervisor PIN verification.

---

## 1. Overview Specifications

- **Port**: `8001`
- **Database**: `codebridge_auth` (Dedicated Microservice DB, MySQL 8.0)
- **Primary Key Standard**: Auto-increment BigInt (`id`, `unsignedBigInteger`)
- **API Gateway Prefix**: `/api/v1/auth`, `/api/v1/me`, `/api/v1/users`, `/api/v1/roles`

---

## 2. Key Responsibilities

1. **Token Authentication**: Issue and revoke Laravel Sanctum bearer tokens (`PersonalAccessToken`).
2. **User Identity & Profiles**: Authenticate credentials (`email`, `password`) and return user profile objects.
3. **Role-Based Access Control (RBAC)**: Manage user roles (`super_admin`, `admin`, `outlet_manager`, `supervisor`, `cashier`, `inventory_clerk`, `accountant`) and capabilities.
4. **Supervisor Security Verification**: Verify 4-digit supervisor PINs for high-risk POS operations (sales voiding, custom discounts, shift overrides).

---

## 3. Database Schema (`codebridge_auth`)

- **`users`**: Stores user credentials, email, password hash, role, supervisor PIN hash, and status.
- **`roles`**: Defines system roles and permission capability lists.
- **`personal_access_tokens`**: Sanctum API access tokens keyed by string UUID (`tokenable_id`).
- **`outlets`**: Store locations and outlet reference definitions.

---

## 4. API Endpoints Reference

| Method | Endpoint | Access | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/auth/login` | Public | Authenticate credentials and return Sanctum Bearer Token. |
| `POST` | `/api/v1/auth/logout` | Protected | Revoke active Sanctum token. |
| `GET` | `/api/v1/me` | Protected | Retrieve current authenticated user profile & permissions. |
| `GET` | `/api/v1/users` | Admin | List system users with role filter and search. |
| `POST` | `/api/v1/users` | Admin | Create new staff user account with assigned role & PIN. |
| `PUT` | `/api/v1/users/{id}` | Admin | Update user details, role, or outlet assignment. |
| `POST` | `/api/v1/auth/verify-pin` | Supervisor | Verify supervisor 4-digit PIN for sensitive POS actions. |

---

## 5. Development Commands

Run database migrations inside Docker:
```bash
docker exec codebridge-auth-service php artisan migrate --force
```
