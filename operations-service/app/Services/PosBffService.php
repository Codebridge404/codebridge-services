<?php
/**
 * Author: Antigravity Team
 * Date: 2026-11-17
 * Task: BD-0160 - POS Terminal & Current Order Dynamic Organization Receipt Template Integration
 */

namespace App\Services;

use App\Support\SchemaGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated POS Backend-for-Frontend (BFF) orchestration service.
 *
 * Combines screen-shaped payloads for Flutter & Next.js POS clients while delegating
 * domain mutations and tenant rules to authoritative operations services.
 */
class PosBffService
{
    public function __construct(
        protected ExchangeRateService $exchangeRateService,
        protected CheckoutService $checkoutService,
        protected BakongService $bakongService,
    ) {}

    /**
     * Resolve tenant-authorized outlet or abort 403 on cross-tenant access.
     */
    public function resolveOutlet(?string $tenantId, ?int $requestedOutletId, ?int $userOutletId, bool $isSuperAdminAll): ?object
    {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $connections = ['mysql', 'auth_db'];

        foreach ($connections as $conn) {
            try {
                if ($conn === 'mysql' && !SchemaGuard::hasTable('outlets')) {
                    continue;
                }
                $table = $conn === 'mysql' ? DB::table('outlets') : DB::connection('auth_db')->table('outlets');

                if ($requestedOutletId !== null) {
                    $outlet = (clone $table)->where('id', $requestedOutletId)->first();
                    if ($outlet) {
                        if (!$isSuperAdminAll && $effectiveTenantId !== null && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== (string) $effectiveTenantId) {
                            abort(403, 'Unauthorized: Outlet belongs to another organization.');
                        }
                        return $outlet;
                    }
                }

                if ($userOutletId !== null) {
                    $userOutlet = (clone $table)->where('id', $userOutletId)->first();
                    if ($userOutlet) {
                        if ($isSuperAdminAll || $effectiveTenantId === null || $userOutlet->tenant_id === null || (string) $userOutlet->tenant_id === (string) $effectiveTenantId) {
                            return $userOutlet;
                        }
                    }
                }

                $firstOutlet = (clone $table)
                    ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first()
                    ?? (clone $table)
                        ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                        ->orderBy('id')
                        ->first();

                if ($firstOutlet) {
                    return $firstOutlet;
                }
            } catch (\Throwable $e) {
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Enrich outlet object with receipt branding, logos, slogans and peripheral configurations.
     */
    public function enrichOutletBranding(?object $outlet, ?string $tenantId): ?object
    {
        if (!$outlet) {
            return null;
        }

        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;

        // Always merge authoritative outlet branding & terminal_settings from auth_db
        // so updates saved in POS Settings (/pos/settings -> auth-service) sync immediately.
        try {
            $authOutlet = DB::connection('auth_db')
                ->table('outlets')
                ->where('id', $outlet->id)
                ->when($effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                ->first();
            if ($authOutlet) {
                $outlet = (object) array_merge((array) $outlet, array_filter((array) $authOutlet, fn ($v) => $v !== null && $v !== ''));
            }
        } catch (\Throwable $e) {}

        return $outlet;
    }

    /**
     * Build unified organization receipt template for POS Terminal and Current Order live slip.
     */
    public function buildReceiptTemplate(?object $outlet, ?string $tenantId): array
    {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $tenantDetails = null;

        if ($effectiveTenantId !== null) {
            try {
                if (SchemaGuard::hasTable('tenants')) {
                    $tenantDetails = DB::table('tenants')->where('id', $effectiveTenantId)->first();
                }
                if (!$tenantDetails) {
                    $tenantDetails = DB::connection('auth_db')
                        ->table('tenants')
                        ->where('id', $effectiveTenantId)
                        ->first();
                }
            } catch (\Throwable $e) {}
        }

        $terminalSettings = [];
        if (isset($outlet->terminal_settings)) {
            $terminalSettings = is_string($outlet->terminal_settings)
                ? json_decode($outlet->terminal_settings, true) ?: []
                : (is_array($outlet->terminal_settings) ? $outlet->terminal_settings : []);
        }

        $storeName = !empty($terminalSettings['outletName'])
            ? $terminalSettings['outletName']
            : ($outlet->name ?? ($tenantDetails->name ?? 'CodeBridges Store'));

        $storeAddress = !empty($terminalSettings['outletAddress'])
            ? (string) $terminalSettings['outletAddress']
            : ($outlet->address ?? ($tenantDetails->address ?? ''));

        $storePhone = !empty($terminalSettings['outletPhone'])
            ? (string) $terminalSettings['outletPhone']
            : ($outlet->phone ?? ($tenantDetails->phone ?? ''));

        $receiptHeader = array_key_exists('receiptHeader', $terminalSettings)
            ? (string) ($terminalSettings['receiptHeader'] ?? '')
            : ($outlet->receipt_header ?? '');

        $receiptFooter = array_key_exists('receiptFooter', $terminalSettings)
            ? (string) ($terminalSettings['receiptFooter'] ?? '')
            : ($outlet->receipt_footer ?? ($tenantDetails->receipt_footer ?? 'Thank you for shopping with us! Please come again.'));

        $receiptLogoUrl = array_key_exists('receiptLogoUrl', $terminalSettings)
            ? (string) ($terminalSettings['receiptLogoUrl'] ?? '')
            : ($outlet->receipt_logo_url ?? ($terminalSettings['receipt_logo_url'] ?? ($tenantDetails->logo_url ?? '')));

        $paperWidth = $terminalSettings['paper_width'] ?? ($terminalSettings['paperWidth'] ?? '80mm');
        $autoPrintReceipt = array_key_exists('autoPrintReceipt', $terminalSettings)
            ? (bool) $terminalSettings['autoPrintReceipt']
            : (isset($terminalSettings['auto_print_receipt']) ? (bool) $terminalSettings['auto_print_receipt'] : true);
        $vatTin = $terminalSettings['vat_tin'] ?? ($terminalSettings['vatTin'] ?? ($tenantDetails->tax_id ?? ''));
        $taxLabel = $terminalSettings['tax_label'] ?? ($terminalSettings['taxLabel'] ?? 'VAT');
        $receiptStartNumber = max(0, (int) ($terminalSettings['receiptStartNumber'] ?? ($terminalSettings['receipt_start_number'] ?? 0)));
        $receiptDigits = min(12, max(1, (int) ($terminalSettings['receiptDigits'] ?? ($terminalSettings['receipt_digits'] ?? 5))));

        return [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'receipt_header' => $receiptHeader,
            'receipt_footer' => $receiptFooter,
            'receipt_logo_url' => $receiptLogoUrl,
            'paper_width' => $paperWidth === '58mm' ? '58mm' : '80mm',
            'auto_print_receipt' => $autoPrintReceipt,
            'vat_tin' => $vatTin,
            'tax_label' => $taxLabel,
            'receipt_start_number' => $receiptStartNumber,
            'receipt_digits' => $receiptDigits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExchangeRatePayload(?string $tenantId): array
    {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $record = SchemaGuard::hasTable('exchange_rates')
            ? $this->exchangeRateService->getActiveRateRecord('USD', 'KHR', $effectiveTenantId)
            : null;
        $rate = (float) ($record->rate ?? ExchangeRateService::DEFAULT_USD_KHR_RATE);

        return [
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'active_rate' => $rate,
            'bid_rate' => $record?->bid_rate !== null ? (float) $record->bid_rate : null,
            'ask_rate' => $record?->ask_rate !== null ? (float) $record->ask_rate : null,
            'source' => $record->source ?? 'NBC_BASELINE',
            'effective_date' => $record?->effective_date?->toDateString() ?? Carbon::today()->toDateString(),
            'formatted' => '1 USD = ' . number_format($rate) . ' KHR',
            'presets' => [
                1 => round(1 * $rate),
                5 => round(5 * $rate),
                10 => round(10 * $rate),
                20 => round(20 * $rate),
                50 => round(50 * $rate),
                100 => round(100 * $rate),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getBootstrap(
        object $user,
        string $tenantId,
        bool $isSuperAdminAll,
        array $roles,
        ?int $requestedOutletId = null
    ): array {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $outlet = $this->resolveOutlet(
            $tenantId,
            $requestedOutletId,
            isset($user->outlet_id) && is_numeric($user->outlet_id) ? (int) $user->outlet_id : null,
            $isSuperAdminAll
        );
        $outlet = $this->enrichOutletBranding($outlet, $tenantId);
        $activeOutletId = $outlet ? (int) $outlet->id : ($requestedOutletId ?? 1);
        $receiptTemplate = $this->buildReceiptTemplate($outlet, $tenantId);

        $outlets = SchemaGuard::hasTable('outlets')
            ? DB::table('outlets')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                ->where('is_active', true)
                ->orderBy('id')
                ->get(array_filter([
                    'id',
                    'name',
                    'code',
                    'address',
                    'phone',
                    SchemaGuard::hasColumn('outlets', 'receipt_header') ? 'receipt_header' : null,
                    SchemaGuard::hasColumn('outlets', 'receipt_footer') ? 'receipt_footer' : null,
                    'is_active',
                ]))
                ->values()
                ->all()
            : [];

        $registers = SchemaGuard::hasTable('registers')
            ? DB::table('registers')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                ->where('outlet_id', $activeOutletId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'outlet_id', 'name', 'code', 'is_active'])
                ->values()
                ->all()
            : [];

        $activeShift = $this->resolveShiftSummary(
            (int) $user->id,
            $tenantId,
            $isSuperAdminAll,
            null,
            false
        );

        $discounts = SchemaGuard::hasTable('discounts')
            ? DB::table('discounts')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, function ($q) use ($effectiveTenantId) {
                    $q->where(function ($sub) use ($effectiveTenantId) {
                        $sub->where('tenant_id', $effectiveTenantId)->orWhereNull('tenant_id');
                    });
                })
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['id', 'name', 'discount_pct', 'applies_to', 'status'])
                ->values()
                ->all()
            : [];

        $scopeKey = $tenantId === 'all' ? 'global' : $tenantId;
        $heldCarts = Cache::get("pos_held_carts_tenant_{$scopeKey}_outlet_{$activeOutletId}", []);
        $heldCartsCount = is_array($heldCarts) ? count($heldCarts) : 0;

        $tablesSummary = [
            'total' => 0,
            'available' => 0,
            'occupied' => 0,
            'reserved' => 0,
            'bill_requested' => 0,
        ];
        if (SchemaGuard::hasTable('restaurant_tables')) {
            $tableRows = DB::table('restaurant_tables')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->get();

            foreach ($tableRows as $row) {
                $count = (int) $row->total;
                $tablesSummary['total'] += $count;
                if (array_key_exists((string) $row->status, $tablesSummary)) {
                    $tablesSummary[(string) $row->status] = $count;
                }
            }
        }

        $pendingKdsTickets = 0;
        if (SchemaGuard::hasTable('kds_tickets')) {
            $kdsQuery = DB::table('kds_tickets')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                ->whereIn('status', ['pending', 'preparing']);
            if (SchemaGuard::hasColumn('kds_tickets', 'outlet_id')) {
                $kdsQuery->where('outlet_id', $activeOutletId);
            }
            $pendingKdsTickets = (int) $kdsQuery->count();
        }

        return [
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name ?? 'User',
                'email' => $user->email ?? '',
                'role' => $user->role ?? ($roles[0] ?? 'cashier'),
                'roles' => array_values($roles),
                'tenant_id' => $effectiveTenantId,
                'outlet_id' => $activeOutletId,
            ],
            'tenant_id' => $tenantId,
            'outlet' => $outlet,
            'active_outlet' => $outlet,
            'receipt_template' => $receiptTemplate,
            'outlets' => $outlets,
            'registers' => $registers,
            'active_shift' => $activeShift,
            'exchange_rate' => $this->buildExchangeRatePayload($tenantId),
            'discounts' => $discounts,
            'counters' => [
                'held_carts_count' => $heldCartsCount,
                'tables' => $tablesSummary,
                'pending_kds_tickets' => $pendingKdsTickets,
            ],
            'capabilities' => [
                'offline_sync' => true,
                'khqr_dynamic' => true,
                'split_bill' => true,
                'multi_currency' => true,
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getCatalog(
        object $user,
        string $tenantId,
        bool $isSuperAdminAll,
        array $filters = []
    ): array {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $requestedOutletId = isset($filters['outlet_id']) && is_numeric($filters['outlet_id'])
            ? (int) $filters['outlet_id']
            : null;

        $outlet = $this->resolveOutlet(
            $tenantId,
            $requestedOutletId,
            isset($user->outlet_id) && is_numeric($user->outlet_id) ? (int) $user->outlet_id : null,
            $isSuperAdminAll
        );
        $activeOutletId = $outlet ? (int) $outlet->id : ($requestedOutletId ?? 1);

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 100)));
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $categoryId = isset($filters['category_id']) && is_numeric($filters['category_id']) ? (int) $filters['category_id'] : null;
        $brandId = isset($filters['brand_id']) && is_numeric($filters['brand_id']) ? (int) $filters['brand_id'] : null;
        $updatedSince = isset($filters['updated_since']) ? trim((string) $filters['updated_since']) : '';
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $hasBrands = SchemaGuard::hasTable('brands') && SchemaGuard::hasColumn('products', 'brand_id');
        $hasBalances = SchemaGuard::hasTable('inventory_balances');

        $productQuery = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->when($hasBrands, fn ($q) => $q->leftJoin('brands as b', 'p.brand_id', '=', 'b.id'))
            ->when($hasBalances, function ($q) use ($activeOutletId, $effectiveTenantId, $isSuperAdminAll) {
                $q->leftJoin('inventory_balances as ib', function ($join) use ($activeOutletId, $effectiveTenantId, $isSuperAdminAll) {
                    $join->on('ib.product_id', '=', 'p.id')
                        ->where('ib.outlet_id', '=', $activeOutletId);
                    if (!$isSuperAdminAll && $effectiveTenantId !== null) {
                        $join->where('ib.tenant_id', '=', $effectiveTenantId);
                    }
                });
            })
            ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('p.tenant_id', $effectiveTenantId))
            ->when(!$includeInactive, fn ($q) => $q->where('p.is_active', true))
            ->when($categoryId !== null, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($brandId !== null && $hasBrands, fn ($q) => $q->where('p.brand_id', $brandId))
            ->when($search !== '', function ($q) use ($search) {
                $escaped = SchemaGuard::escapeLike($search);
                $q->where(function ($sub) use ($escaped) {
                    $sub->where('p.name', 'like', "%{$escaped}%")
                        ->orWhere('p.sku', 'like', "%{$escaped}%")
                        ->orWhere('p.barcode', 'like', "%{$escaped}%");
                });
            })
            ->when($updatedSince !== '', function ($q) use ($updatedSince) {
                try {
                    $since = Carbon::parse($updatedSince);
                    $q->where('p.updated_at', '>=', $since);
                } catch (\Throwable) {
                    // Ignore invalid timestamp filter rather than failing POS render
                }
            });

        $selectColumns = [
            'p.id',
            'p.tenant_id',
            'p.category_id',
            'p.name',
            'p.sku',
            'p.barcode',
            'p.description',
            'p.selling_price',
            'p.image_url',
            'p.min_reorder_point',
            'p.is_active',
            'p.updated_at',
            'c.name as category_name',
            DB::raw($hasBrands ? 'p.brand_id as brand_id' : 'NULL as brand_id'),
            DB::raw($hasBrands ? 'b.name as brand_name' : 'NULL as brand_name'),
            DB::raw($hasBalances ? 'COALESCE(ib.on_hand, 0) as stock_on_hand' : '0 as stock_on_hand'),
            DB::raw($hasBalances ? 'COALESCE(ib.available, 0) as stock_available' : '0 as stock_available'),
        ];

        $paginator = $productQuery
            ->select($selectColumns)
            ->orderBy('p.name')
            ->paginate($perPage);

        $categories = SchemaGuard::hasTable('categories')
            ? DB::table('categories')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, function ($q) use ($effectiveTenantId) {
                    $q->where(function ($sub) use ($effectiveTenantId) {
                        $sub->where('tenant_id', $effectiveTenantId)->orWhereNull('tenant_id');
                    });
                })
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'parent_id'])
                ->values()
                ->all()
            : [];

        $brands = SchemaGuard::hasTable('brands')
            ? DB::table('brands')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, function ($q) use ($effectiveTenantId) {
                    $q->where(function ($sub) use ($effectiveTenantId) {
                        $sub->where('tenant_id', $effectiveTenantId)->orWhereNull('tenant_id');
                    });
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values()
                ->all()
            : [];

        $discounts = SchemaGuard::hasTable('discounts')
            ? DB::table('discounts')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, function ($q) use ($effectiveTenantId) {
                    $q->where(function ($sub) use ($effectiveTenantId) {
                        $sub->where('tenant_id', $effectiveTenantId)->orWhereNull('tenant_id');
                    });
                })
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['id', 'name', 'discount_pct', 'applies_to', 'status'])
                ->values()
                ->all()
            : [];

        $exchangeRate = $this->buildExchangeRatePayload($tenantId);
        $maxUpdated = collect($paginator->items())->max('updated_at') ?? '';
        $catalogVersion = sha1(implode('|', [
            (string) $activeOutletId,
            (string) $paginator->total(),
            (string) $maxUpdated,
            (string) $exchangeRate['active_rate'],
        ]));

        return [
            'outlet_id' => $activeOutletId,
            'catalog_version' => $catalogVersion,
            'synced_at' => now()->toIso8601String(),
            'categories' => $categories,
            'brands' => $brands,
            'discounts' => $discounts,
            'exchange_rate' => $exchangeRate,
            'products' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveShiftSummary(
        int $userId,
        string $tenantId,
        bool $isSuperAdminAll,
        ?int $shiftId = null,
        bool $abortIfNotFound = false
    ): ?array {
        if (!SchemaGuard::hasTable('shifts')) {
            return null;
        }

        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $query = DB::table('shifts as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
            ->select('s.*', 'u.name as cashier_name', 'o.name as outlet_name');

        if ($shiftId !== null) {
            $shift = $query->where('s.id', $shiftId)->first();
            if (!$shift) {
                if ($abortIfNotFound) {
                    abort(404, 'Shift not found.');
                }

                return null;
            }
            if (!$isSuperAdminAll) {
                if ($shift->tenant_id === null || ($effectiveTenantId !== null && (string) $shift->tenant_id !== (string) $effectiveTenantId)) {
                    abort(403, 'Unauthorized: Shift belongs to another organization.');
                }
            }
        } else {
            $shift = $query
                ->where('s.user_id', $userId)
                ->where('s.status', 'open')
                ->when(!$isSuperAdminAll && $effectiveTenantId !== null, fn ($q) => $q->where('s.tenant_id', $effectiveTenantId))
                ->orderByDesc('s.id')
                ->first();

            if (!$shift) {
                return null;
            }
        }

        $shiftTenant = $shift->tenant_id !== null ? (string) $shift->tenant_id : null;

        $paymentSums = DB::table('sales as s')
            ->join('payments as p', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shift->id)
            ->where('p.status', 'paid')
            ->when($shiftTenant !== null, fn ($q) => $q->where('s.tenant_id', $shiftTenant))
            ->select('p.tender_type', DB::raw('SUM(p.amount) as total_amount'))
            ->groupBy('p.tender_type')
            ->pluck('total_amount', 'p.tender_type');

        $salesCount = (int) DB::table('sales')
            ->where('shift_id', $shift->id)
            ->when($shiftTenant !== null, fn ($q) => $q->where('tenant_id', $shiftTenant))
            ->count();

        $cashSales = (float) ($paymentSums['cash'] ?? 0);
        $khqrSales = (float) ($paymentSums['khqr'] ?? 0);
        $cardSales = (float) ($paymentSums['card'] ?? 0);

        $cashIn = 0.0;
        $cashOut = 0.0;
        $movements = [];

        if (SchemaGuard::hasTable('cash_drawer_movements')) {
            $cashIn = (float) DB::table('cash_drawer_movements')
                ->where('shift_id', $shift->id)
                ->where('type', 'in')
                ->when($shiftTenant !== null, fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', $shiftTenant)->orWhereNull('tenant_id')))
                ->sum('amount');

            $cashOut = (float) DB::table('cash_drawer_movements')
                ->where('shift_id', $shift->id)
                ->where('type', 'out')
                ->when($shiftTenant !== null, fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', $shiftTenant)->orWhereNull('tenant_id')))
                ->sum('amount');

            $movements = DB::table('cash_drawer_movements')
                ->where('shift_id', $shift->id)
                ->when($shiftTenant !== null, fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', $shiftTenant)->orWhereNull('tenant_id')))
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->values()
                ->all();
        }

        $openingFloat = (float) $shift->opening_float;
        $expectedCash = round($openingFloat + $cashSales + $cashIn - $cashOut, 2);

        return [
            'shift' => $shift,
            'summary' => [
                'opening_float' => $openingFloat,
                'cash_sales' => $cashSales,
                'khqr_sales' => $khqrSales,
                'card_sales' => $cardSales,
                'gross_sales' => round($cashSales + $khqrSales + $cardSales, 2),
                'sales_count' => $salesCount,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'expected_cash' => $expectedCash,
            ],
            'movements' => $movements,
        ];
    }

    /**
     * Execute POS checkout and optionally prepare dynamic KHQR payload + payment attempt.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public function checkout(array $validated, object $user, string $tenantId, bool $isSuperAdminAll): array
    {
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;
        $checkoutResult = $this->checkoutService->finalizeSale(
            $validated,
            $user instanceof \App\Models\User ? $user : null,
            $effectiveTenantId
        );

        $sale = $checkoutResult['sale'];
        $saleTenantId = $sale->tenant_id !== null ? (string) $sale->tenant_id : $effectiveTenantId;
        $tenderType = strtolower((string) ($validated['tender_type'] ?? 'cash'));
        $generateKhqr = filter_var($validated['generate_khqr'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $khqrPayload = null;
        if ($tenderType === 'khqr' && $generateKhqr) {
            $khqrPayload = $this->attachOrReuseKhqrAttempt(
                $sale,
                $saleTenantId,
                $validated
            );
        }

        $shiftSummary = $this->resolveShiftSummary(
            (int) $user->id,
            $tenantId,
            $isSuperAdminAll,
            isset($sale->shift_id) && $sale->shift_id !== null ? (int) $sale->shift_id : null,
            false
        );

        return [
            'sale' => $sale,
            'lines' => $checkoutResult['lines'],
            'is_duplicate' => (bool) ($checkoutResult['is_duplicate'] ?? false),
            'khqr' => $khqrPayload,
            'exchange_rate' => $this->buildExchangeRatePayload($tenantId),
            'active_shift' => $shiftSummary,
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function attachOrReuseKhqrAttempt(object $sale, ?string $saleTenantId, array $validated): array
    {
        $payment = DB::table('payments')
            ->where('sale_id', $sale->id)
            ->orderByDesc('id')
            ->first();

        if ($payment) {
            $existingAttempt = DB::table('payment_attempts')
                ->where('payment_id', $payment->id)
                ->orderByDesc('id')
                ->first();

            if ($existingAttempt) {
                $decoded = json_decode((string) ($existingAttempt->raw_payload ?? ''), true);
                if (is_array($decoded) && !empty($decoded['khqr_string'])) {
                    return [
                        'payment_id' => (int) $payment->id,
                        'attempt_id' => (int) $existingAttempt->id,
                        'merchant_reference' => $existingAttempt->merchant_reference,
                        'md5' => $decoded['md5'] ?? $existingAttempt->provider_transaction_id,
                        'khqr_string' => $decoded['khqr_string'],
                        'amount' => (float) $existingAttempt->amount,
                        'currency' => (string) $existingAttempt->currency,
                        'khr_equivalent' => $decoded['khr_equivalent'] ?? null,
                        'merchant_name' => $decoded['merchant_name'] ?? null,
                        'merchant_city' => $decoded['merchant_city'] ?? null,
                        'status' => $existingAttempt->status,
                    ];
                }
            }
        }

        $currency = strtoupper((string) ($validated['khqr_currency'] ?? $sale->currency ?? 'USD'));
        $amount = (float) $sale->grand_total;
        if ($currency === 'KHR' && strtoupper((string) ($sale->currency ?? 'USD')) === 'USD') {
            $rate = $this->exchangeRateService->getActiveRate('USD', 'KHR', $saleTenantId);
            $amount = round($amount * $rate, -2);
        }

        // Enforce strict merchant recipient resolution from authorized tenant configuration
        $merchantId = null;
        $merchantName = null;
        $merchantCity = null;
        $bankName = null;

        if (SchemaGuard::hasTable('bank_accounts')) {
            $bankAccountId = isset($validated['bank_account_id']) && is_numeric($validated['bank_account_id'])
                ? (int) $validated['bank_account_id']
                : null;

            if ($bankAccountId !== null) {
                $bankAccount = DB::table('bank_accounts')->where('id', $bankAccountId)->first();
                if (!$bankAccount) {
                    throw new \Exception('Unauthorized: Bank account not found.');
                }
                if ($saleTenantId !== null && $bankAccount->tenant_id !== null && (string) $bankAccount->tenant_id !== (string) $saleTenantId) {
                    throw new \Exception('Unauthorized: Bank account belongs to another organization.');
                }
            } else {
                $bankAccount = DB::table('bank_accounts')
                    ->when($saleTenantId !== null, fn ($q) => $q->where('tenant_id', $saleTenantId))
                    ->where('status', '!=', 'inactive')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($bankAccount) {
                $merchantId = !empty($bankAccount->account_number) ? (string) $bankAccount->account_number : null;
                $merchantName = !empty($bankAccount->account_name) ? (string) $bankAccount->account_name : null;
                $bankName = !empty($bankAccount->bank_name) ? (string) $bankAccount->bank_name : null;
            }
        }

        if (!$merchantName && $saleTenantId !== null && SchemaGuard::hasTable('tenants')) {
            $tenant = DB::table('tenants')->where('id', $saleTenantId)->first();
            if ($tenant && !empty($tenant->name)) {
                $merchantName = (string) $tenant->name;
            }
        }

        $billNumber = (string) ($sale->receipt_number ?: ('BILL-' . date('Ymd') . '-' . $sale->id));
        $generated = $this->bakongService->generateKhqrPayload(
            $amount,
            $currency,
            $billNumber,
            $merchantId,
            $merchantName,
            $merchantCity,
            $bankName
        );

        if ($payment) {
            DB::table('payments')->where('id', $payment->id)->update([
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'reference_number' => $billNumber,
                'updated_at' => now(),
            ]);
            $paymentId = (int) $payment->id;
        } else {
            $paymentId = DB::table('payments')->insertGetId([
                'tenant_id' => $saleTenantId,
                'sale_id' => $sale->id,
                'tender_type' => 'khqr',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'reference_number' => $billNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingAttempt = DB::table('payment_attempts')
            ->where('payment_id', $paymentId)
            ->orderByDesc('id')
            ->first();

        if ($existingAttempt) {
            DB::table('payment_attempts')->where('id', $existingAttempt->id)->update([
                'merchant_reference' => $billNumber,
                'provider_transaction_id' => $generated['md5'],
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'raw_payload' => json_encode($generated),
                'updated_at' => now(),
            ]);
            $attemptId = (int) $existingAttempt->id;
        } else {
            $attemptId = DB::table('payment_attempts')->insertGetId([
                'tenant_id' => $saleTenantId,
                'payment_id' => $paymentId,
                'merchant_reference' => $billNumber,
                'provider_transaction_id' => $generated['md5'],
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'raw_payload' => json_encode($generated),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'payment_id' => $paymentId,
            'attempt_id' => $attemptId,
            'merchant_reference' => $billNumber,
            'md5' => $generated['md5'],
            'khqr_string' => $generated['khqr_string'],
            'amount' => $amount,
            'currency' => $currency,
            'khr_equivalent' => $generated['khr_equivalent'],
            'merchant_name' => $generated['merchant_name'],
            'merchant_city' => $generated['merchant_city'],
            'status' => 'pending',
        ];
    }
}

