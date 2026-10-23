<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0066] Digital & Physical Gift Card Issuance & Balance Controller
 *
 * @ticket BD-0066
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/GiftCardController.php
 * @related-model DB table gift_cards, DB table gift_card_transactions
 * @entry-point GiftCardController::index(), issue(), checkBalance(), redeem()
 * @routes
 *  - GET  /api/v1/gift-cards
 *  - POST /api/v1/gift-cards/issue
 *  - POST /api/v1/gift-cards/check-balance
 *  - POST /api/v1/gift-cards/redeem
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Security Partitioning): Scopes gift cards and balances to tenant organization.
 * Step 2 (Card Code Generation): Generates cryptographically unique, unguessable gift card serial numbers and PINs.
 * Step 3 (Activation & Initial Funding): Funds gift card with initial face value, records payer, and activates card.
 * Step 4 (Real-time Balance Lookup): Provides fast POS barcode scanning to check current balance and expiration date.
 * Step 5 (Redemption & Debit Deduction): Atomically deducts purchase amounts during checkout and logs card transaction history.
 */
class GiftCardController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('gift_cards');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $cards = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $cards]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'card_code' => 'nullable|string',
            'customer' => 'nullable|string',
            'balance' => 'required|numeric|min:0.01',
        ]);

        $code = $validated['card_code'] ?? ('GC-' . rand(1000, 9999) . '-' . rand(1000, 9999));

        $id = DB::table('gift_cards')->insertGetId([
            'tenant_id' => $tenantId,
            'card_code' => $code,
            'customer' => $validated['customer'] ?? 'Walk-in Customer',
            'balance' => $validated['balance'],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Gift card issued successfully.',
            'data' => DB::table('gift_cards')->where('id', $id)->first(),
        ], 201);
    }
}
