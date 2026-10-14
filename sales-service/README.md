# Commerce & Finance Microservice (`sales-service`)

The **Commerce & Finance Microservice** is the central financial and transaction engine of the CodeBridge@ Enterprise Platform. It powers multi-channel sales transactions, e-commerce order intake, cashier shifts and till float audits, NBC Bakong KHQR dynamic payments, bank accounts, income/expense ledgers, financial reconciliation, and executive revenue dashboards.

---

## 1. Overview Specifications

- **Port**: `8006`
- **Database**: `codebridge` (Unified Single Database, MySQL 8.0)
- **Primary Key Standard**: Auto-increment BigInt (`id`, `unsignedBigInteger`) & UUID idempotency
- **API Gateway Prefix**: `/api/v1/sales`, `/api/v1/online-orders`, `/api/v1/shifts`, `/api/v1/payments`, `/api/v1/expenses`, `/api/v1/income`, `/api/v1/bank-accounts`, `/api/v1/reconciliation`

---

## 2. Key Responsibilities

1. **Transaction & Invoicing Engine**: Create completed sale records (`POST /api/v1/sales`) with server-authoritative pricing, tax, line-item auditing, and UUID idempotency keys.
2. **Multi-Channel Ordering & Carts**: Support in-store POS checkouts, draft cart holds/recalls, and public online e-commerce ordering (`/api/v1/online-orders`).
3. **Cash Drawer Shifts & Till Auditing**: Shift lifecycle management, opening floats, mid-shift pay-in/safe drops, dual-currency bill counting (USD + KHR), X-reports, and definitive Z-reports.
4. **Digital Payments & KHQR**: Dynamic NBC Bakong KHQR generation, ABA PayWay webhooks, transaction status polling, and split-tender settlement.
5. **Financial Ledgers & Reconciliations**: Automated reconciliation engine, exception auditing, business expense tracking, revenue streams, and bank account registers.
6. **Executive Dashboard Analytics**: Revenue velocity, payment method breakdown, top-performing products, and tax reporting.

---

## 3. Database Schema

- **`sales`**: Completed sales transaction headers (`id`, `invoice_number`, `outlet_id`, `shift_id`, `cashier_id`, `subtotal`, `discount_amount`, `tax_amount`, `grand_total`, `payment_status`, `idempotency_key`, `completed_at`).
- **`sale_items`**: Individual line items (`id`, `sale_id`, `product_id`, `product_name`, `unit_price`, `quantity`, `subtotal`).
- **`held_carts`**: Held/draft carts (`id`, `customer_name`, `cart_data_json`, `held_by`, `created_at`).
- **`shifts`**: Cash drawer shift sessions, float records, counted cash, discrepancies, and status.
- **`payments`**: Payment transaction ledger across Cash, Bakong KHQR, ABA, and Cards.
- **`expenses` / `income` / `bank_accounts`**: Corporate accounting ledgers and operational financial streams.

---

## 4. API Endpoints Reference

| Method | Endpoint | Access | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/sales` | Staff | Execute idempotent checkout and record sale invoice. |
| `GET` | `/api/v1/sales` | Protected | List historical sales records with date & outlet filters. |
| `GET` | `/api/v1/sales/{id}` | Protected | Fetch sale invoice details & line items. |
| `POST` | `/api/v1/online-orders` | Public | Ingest online customer e-commerce order. |
| `POST` | `/api/v1/payments/khqr/generate` | Staff | Generate dynamic NBC Bakong KHQR payload. |
| `GET` | `/api/v1/shifts/active` | Staff | Check active drawer shift for terminal. |
| `POST` | `/api/v1/shifts/open` | Staff | Open new cash drawer shift with float. |
| `GET` | `/api/v1/expenses` | Protected | Retrieve business expenses log. |
| `POST` | `/api/v1/expenses` | Protected | Record operational expense ledger entry. |
| `GET` | `/api/v1/bank-accounts` | Protected | Retrieve registered company bank accounts. |
| `GET` | `/api/v1/admin/dashboard/summary` | Protected | Executive sales and financial summary. |

---

## 5. Development Commands

Run database migrations inside Docker:
```bash
docker exec codebridge-sales-service php artisan migrate --force
```
