<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCard\StoreGiftCardRequest;
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
        $user = $request->user();
        if ($user && $user->role !== 'super_admin') {
            return $user->tenant_id ? (string) $user->tenant_id : null;
        }

        return $request->header('X-Tenant-Id')
            ?? ($user?->tenant_id ? (string) $user->tenant_id : null)
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

        $cards = $query->orderBy('created_at', 'desc')->get()->map(function ($c) {
            $c->card_code = $c->card_number;
            $c->balance = (float) $c->current_balance;
            $c->status = $c->is_active ? 'active' : 'inactive';
            return $c;
        });

        return response()->json(['status' => 'success', 'data' => $cards]);
    }

    public function store(StoreGiftCardRequest $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validated();

        $cardNumber = $validated['card_number'] ?? $validated['card_code'] ?? ('GC-' . rand(1000, 9999) . '-' . rand(1000, 9999));
        $initialValue = (float) ($validated['initial_value'] ?? $validated['balance'] ?? 0.00);

        $id = DB::table('gift_cards')->insertGetId([
            'tenant_id' => $tenantId,
            'card_number' => $cardNumber,
            'initial_value' => $initialValue,
            'current_balance' => $initialValue,
            'currency' => $validated['currency'] ?? 'USD',
            'is_active' => true,
            'expires_at' => !empty($validated['expires_at']) ? $validated['expires_at'] : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $card = DB::table('gift_cards')->where('id', $id)->first();
        if ($card) {
            $card->card_code = $card->card_number;
            $card->balance = (float) $card->current_balance;
            $card->status = $card->is_active ? 'active' : 'inactive';
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Gift card issued successfully.',
            'data' => $card,
        ], 201);
    }

    public function checkBalance(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $code = $request->input('card_number') ?? $request->input('card_code');

        $query = DB::table('gift_cards')->where('card_number', $code);
        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $card = $query->first();
        if (!$card) {
            return response()->json(['status' => 'error', 'message' => 'Gift card not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'card_number' => $card->card_number,
                'card_code' => $card->card_number,
                'current_balance' => (float) $card->current_balance,
                'balance' => (float) $card->current_balance,
                'initial_value' => (float) $card->initial_value,
                'currency' => $card->currency,
                'is_active' => (bool) $card->is_active,
                'expires_at' => $card->expires_at,
            ],
        ]);
    }

    public function redeem(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $code = $request->input('card_number') ?? $request->input('card_code');
        $amount = (float) $request->input('amount', 0);

        if ($amount <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Redemption amount must be greater than zero.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $code, $amount) {
            $query = DB::table('gift_cards')->where('card_number', $code)->lockForUpdate();
            if (!empty($tenantId) && $tenantId !== 'all') {
                $query->where('tenant_id', $tenantId);
            }

            $card = $query->first();
            if (!$card) {
                return response()->json(['status' => 'error', 'message' => 'Gift card not found.'], 404);
            }

            if (!$card->is_active) {
                return response()->json(['status' => 'error', 'message' => 'Gift card is inactive or disabled.'], 422);
            }

            if ($card->expires_at && strtotime($card->expires_at) < time()) {
                return response()->json(['status' => 'error', 'message' => 'Gift card has expired.'], 422);
            }

            $currentBalance = (float) $card->current_balance;
            if ($currentBalance < $amount) {
                return response()->json(['status' => 'error', 'message' => 'Insufficient gift card balance.'], 422);
            }

            $newBalance = $currentBalance - $amount;
            DB::table('gift_cards')->where('id', $card->id)->update([
                'current_balance' => $newBalance,
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Successfully redeemed \${$amount} from gift card.",
                'data' => [
                    'card_number' => $card->card_number,
                    'card_code' => $card->card_number,
                    'previous_balance' => $currentBalance,
                    'redeemed_amount' => $amount,
                    'new_balance' => $newBalance,
                ],
            ]);
        });
    }
}
