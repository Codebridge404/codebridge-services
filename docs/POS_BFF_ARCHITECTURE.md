# POS Backend-for-Frontend (BFF) & Gateway Architecture

## Overview

The POS BFF layer provides screen-shaped, single-roundtrip JSON endpoints for Flutter POS terminals under `/api/v1/pos-bff/*`. It is implemented as an explicit boundary module inside `operations-service` (`PosBffController` + `PosBffService`) and routed through Nginx Gateway (`/api/v1/pos-bff`), avoiding extra container/CI sprawl while remaining cleanly extractable into a dedicated container if traffic later demands independent scaling.

| Layer | Responsibility |
| --- | --- |
| **Nginx Gateway** | Routes `/api/v1/pos-bff` -> `operations-service`, resolves `/api/v1/admin/dashboard` collision (`= /api/v1/admin/dashboard` -> `auth-service`, `/api/v1/admin/dashboard/` -> `operations-service`). |
| **POS BFF** (`operations-service`) | Aggregates POS screen state (`bootstrap`, `catalog`, `shift-summary`) and orchestrates checkout + optional dynamic KHQR generation in one call. |
| **Backend Services** (`auth-service`, `operations-service`) | Enforce RBAC, tenant isolation, inventory locks, KHQR cryptography, and shift accounting. |

## Endpoints

All `/api/v1/pos-bff/*` routes require `auth:sanctum` and an authorized POS/management role. Tenant boundaries are enforced via `ResolvesTenantContext` and `PosBffService::resolveOutlet()`.

| Flutter POS Screen | Endpoint | Reconciled Aggregations / Actions |
| --- | --- | --- |
| Terminal Startup / Branch Switcher | `GET /api/v1/pos-bff/bootstrap?outlet_id=` | Caller context, outlet & active registers, current open shift + cash summary, USD-KHR exchange rate + denomination presets, active discounts, held carts count, table & KDS counters. |
| POS Item Grid & Barcode Lookup | `GET /api/v1/pos-bff/catalog?outlet_id=&category_id=&brand_id=&search=&updated_since=&per_page=` | Active products left-joined with outlet `inventory_balances`, categories, brands, active discounts, exchange rate, and deterministic `catalog_version` hash for offline delta cache validation. |
| Cash Drawer / Shift Modal | `GET /api/v1/pos-bff/shift-summary?shift_id=` | Shift record, cash/KHQR/card payment sums, pay-in/out totals, expected cash, last 20 cash drawer movements, and active USD-KHR exchange rate. |
| Checkout & Dynamic KHQR Modal | `POST /api/v1/pos-bff/checkout` | Validates payload via `StoreCheckoutRequest`, executes `CheckoutService::finalizeSale()`, and when `tender_type=khqr` & `generate_khqr=true` reuses or creates one dynamic `payments` + `payment_attempts` Bakong EMVCo KHQR payload idempotently without duplicating payment rows, returning refreshed shift metrics. |

## Extraction Path

If mobile POS BFF traffic grows independently, `PosBffController` already depends only on `PosBffService` and can be moved into a standalone service behind `/api/v1/pos-bff` in `gateway/services/*.conf` and `k8s/staging/configmaps.yaml` without changing mobile client contracts.

