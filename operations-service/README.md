# Operations Microservice (`operations-service`)

The **Operations Microservice** is the unified commerce, supply chain, and store operations engine of the CodeBridge@ Multi-Tenant Enterprise Platform. It combines catalog management, multi-warehouse stock ledgers, cashier POS terminals, till shifts, NBC Bakong KHQR payments, and financial ledgers into a single, high-performance service.

---

## 1. Overview Specifications

- **Port**: `8003`
- **Database**: `codebridge_operations` (Dedicated Microservice DB, MySQL 8.0)
- **Primary Key Standard**: Auto-increment BigInt (`id`, `unsignedBigInteger`) & UUID idempotency
- **Modular Monolith**: Products, Inventory, Sales & POS, and Finance all live inside this service sharing `codebridge_operations` database
- **API Gateway Routes**: 
  - Catalog & Stock: `/api/v1/products`, `/api/v1/categories`, `/api/v1/brands`, `/api/v1/inventory`, `/api/v1/purchases`, `/api/v1/purchase-orders`
  - Sales & POS: `/api/v1/sales`, `/api/v1/carts`, `/api/v1/online-orders`, `/api/v1/shifts`, `/api/v1/kds`, `/api/v1/tables`
  - Payments & Finance: `/api/v1/payments`, `/api/v1/reconciliation`, `/api/v1/expenses`, `/api/v1/income`, `/api/v1/bank-accounts`
- **Inter-service Communication**:
  - Auth DB connection (`auth_db` -> `codebridge_auth`) for Sanctum token authentication
  - Inter-service REST API client (`AuthServiceClient` -> `http://auth-service:8000/api/v1`)
  - Event dispatching for cross-domain notifications

---

## 2. Key Responsibilities

1. **Product Catalog & Pricing**: Manage SKUs, barcodes, categories, brands, variants, discounts, and coupons.
2. **Multi-Warehouse Stock Logistics**: Real-time snapshot balances (`inventory_balances`), immutable movement ledger (`inventory_movements`), warehouse transfers, and purchase orders.
3. **Cashier POS Ring Terminal**: Fast item barcode resolution, cart holds/recalls, server-authoritative checkout calculations, and receipts.
4. **Shift & Till Auditing**: Cash drawer shifts, starting floats, mid-shift pay-in/safe drops, dual-currency bill counting (USD + KHR), X-reports, and Z-reports.
5. **Digital Payments & KHQR**: Dynamic NBC Bakong KHQR code generation, ABA PayWay webhooks, and split-tender payment settlement.
6. **Finance & Ledgers**: Operational expenses, non-sale income, bank accounts, and automated transaction reconciliation.

---

## 3. Development Commands

Run database migrations inside Docker:
```bash
docker exec codebridge-operations-service php artisan migrate --force
```
