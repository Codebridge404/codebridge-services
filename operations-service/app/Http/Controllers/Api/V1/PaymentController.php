<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\GenerateKhqrRequest;
use App\Services\BakongService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0013] Dynamic NBC Bakong KHQR, Cash Tender & Payment State Machine Controller.
 *
 * @ticket BD-0013
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/PaymentController.php
 * @related-service App\Services\BakongService
 * @entry-point POST /api/v1/payments/khqr, POST /api/v1/payments/callback
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Generates dynamic Bakong KHQR payloads for secondary customer screens.
 * 2. Manages 8-state payment lifecycle (created, pending, paid, refunded).
 * 3. Server-to-server webhook verification and transaction status validation.
 */
class PaymentController extends Controller
{
    use ResolvesTenantContext;

    protected BakongService $bakongService;

    public function __construct(BakongService $bakongService)
    {
        $this->bakongService = $bakongService;
    }

    /**
     * Generate KHQR for Dynamic Cashier Display or Customer-facing Tablet
     */
    public function generateKhqr(GenerateKhqrRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        $amount = (float) $validated['amount'];
        $currency = strtoupper($validated['currency'] ?? 'USD');
        $billNumber = $validated['bill_number'] ?? ('BILL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));

        $customMerchantId = $validated['merchant_id'] ?? null;
        $customMerchantName = $validated['merchant_name'] ?? null;
        $customMerchantCity = $validated['merchant_city'] ?? null;
        $customBankName = $validated['bank_name'] ?? null;

        if (!$customMerchantId) {
            $bankAccount = DB::table('bank_accounts')
                ->when($tenantId !== 'all', fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('id')
                ->first();

            if ($bankAccount && !empty($bankAccount->account_number)) {
                $customMerchantId = $bankAccount->account_number;
                if (!$customMerchantName && !empty($bankAccount->account_name)) {
                    $customMerchantName = $bankAccount->account_name;
                }
                if (!$customBankName && !empty($bankAccount->bank_name)) {
                    $customBankName = $bankAccount->bank_name;
                }
            }
        }

        $saleId = $validated['sale_id'] ?? null;
        $effectiveTenantId = $tenantId === 'all' ? null : $tenantId;

        if ($saleId !== null) {
            $sale = DB::table('sales')->where('id', $saleId)->first();
            if (!$sale) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Referenced sale record not found.',
                ], 404);
            }
            $this->authorizeTenantResource($sale->tenant_id, $request, 'Sale');
            $effectiveTenantId = $effectiveTenantId ?? ($sale->tenant_id !== null ? (string) $sale->tenant_id : null);
        } else {
            $latestSale = DB::table('sales')
                ->when($tenantId !== 'all', fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('id')
                ->first();
            $saleId = $latestSale ? $latestSale->id : 0;
        }

        $payload = $this->bakongService->generateKhqrPayload(
            $amount,
            $currency,
            $billNumber,
            $customMerchantId,
            $customMerchantName,
            $customMerchantCity,
            $customBankName
        );

        // Record Header Payment
        $paymentId = DB::table('payments')->insertGetId([
            'tenant_id' => $effectiveTenantId,
            'sale_id' => $saleId,
            'tender_type' => 'khqr',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'reference_number' => $billNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Record Payment Attempt
        $attemptId = DB::table('payment_attempts')->insertGetId([
            'tenant_id' => $effectiveTenantId,
            'payment_id' => $paymentId,
            'merchant_reference' => $billNumber,
            'provider_transaction_id' => $payload['md5'],
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'raw_payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'payment_id' => $paymentId,
                'attempt_id' => $attemptId,
                'merchant_reference' => $billNumber,
                'md5' => $payload['md5'],
                'khqr_string' => $payload['khqr_string'],
                'amount' => $amount,
                'currency' => $currency,
                'khr_equivalent' => $payload['khr_equivalent'],
                'merchant_name' => $payload['merchant_name'],
                'merchant_city' => $payload['merchant_city'],
            ],
        ], 201);
    }

    /**
     * Check payment status against database and NBC Bakong Open API
     */
    public function checkStatus(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $attempt = DB::table('payment_attempts')->where('id', $id)->first()
            ?? DB::table('payment_attempts')->where('merchant_reference', $id)->first()
            ?? DB::table('payment_attempts')->where('payment_id', $id)->first();

        if (!$attempt) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment attempt record not found.',
            ], 404);
        }

        $this->authorizeTenantResource($attempt->tenant_id, $request, 'PaymentAttempt');

        if ($attempt->status === 'paid') {
            return response()->json([
                'status' => 'success',
                'paid' => true,
                'attempt' => $attempt,
            ]);
        }

        $hash = $request->input('hash')
            ?? $request->query('hash')
            ?? $request->input('short_hash')
            ?? $request->query('short_hash')
            ?? $request->input('transaction_hash')
            ?? $request->query('transaction_hash');

        $token = $request->header('X-Bakong-Token')
            ?? $request->input('token')
            ?? $request->query('token');

        // Always verify against authoritative DB attempt amount & currency, never client input
        $amount = (float) $attempt->amount;
        $currency = strtoupper((string) ($attempt->currency ?? 'USD'));

        if (!empty($hash)) {
            $bakongRes = $this->bakongService->checkTransactionByHash((string) $hash, $token, $amount, $currency);
        } else {
            $md5Hash = (string) $attempt->provider_transaction_id;
            $bakongRes = $this->bakongService->checkTransactionByMd5($md5Hash, $token);
        }

        $verified = $this->bakongResponseMatchesAttempt($bakongRes, $attempt);

        if ($verified) {
            DB::transaction(function () use ($attempt, $hash) {
                $updated = DB::table('payment_attempts')
                    ->where('id', $attempt->id)
                    ->where('status', '!=', 'paid')
                    ->update(array_filter([
                        'status' => 'paid',
                        'provider_transaction_id' => !empty($hash) ? (string) $hash : null,
                        'updated_at' => now(),
                    ], fn ($v) => $v !== null));

                if ($updated) {
                    DB::table('payments')->where('id', $attempt->payment_id)->update([
                        'status' => 'paid',
                        'updated_at' => now(),
                    ]);
                }
            });

            $attempt->status = 'paid';
            if (!empty($hash)) {
                $attempt->provider_transaction_id = (string) $hash;
            }
        }

        return response()->json([
            'status' => 'success',
            'paid' => $attempt->status === 'paid',
            'bakong_verification' => $bakongRes,
            'attempt' => $attempt,
        ]);
    }

    /**
     * Verify or confirm payment using customer receipt transaction hash
     */
    public function confirmHash(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $attempt = DB::table('payment_attempts')->where('id', $id)->first()
            ?? DB::table('payment_attempts')->where('merchant_reference', $id)->first()
            ?? DB::table('payment_attempts')->where('payment_id', $id)->first();

        if (!$attempt) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment attempt not found.',
            ], 404);
        }

        $this->authorizeTenantResource($attempt->tenant_id, $request, 'PaymentAttempt');

        $hash = trim((string) ($request->input('hash') ?? $request->input('transaction_hash')));
        if (empty($hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction hash is required.',
            ], 422);
        }

        $token = $request->header('X-Bakong-Token') ?? $request->input('token') ?? $request->query('token');
        // Always enforce DB amount & currency; never accept client-forged confirmation flags or amounts
        $amount = (float) $attempt->amount;
        $currency = strtoupper((string) ($attempt->currency ?? 'USD'));

        $bakongRes = $this->bakongService->checkTransactionByHash($hash, $token, $amount, $currency);
        $isPaid = $this->bakongResponseMatchesAttempt($bakongRes, $attempt);

        if ($isPaid) {
            DB::transaction(function () use ($attempt, $hash) {
                DB::table('payment_attempts')
                    ->where('id', $attempt->id)
                    ->where('status', '!=', 'paid')
                    ->update([
                        'status' => 'paid',
                        'provider_transaction_id' => $hash,
                        'updated_at' => now(),
                    ]);

                DB::table('payments')->where('id', $attempt->payment_id)->update([
                    'status' => 'paid',
                    'updated_at' => now(),
                ]);
            });

            $attempt->status = 'paid';
            $attempt->provider_transaction_id = $hash;
        }

        return response()->json([
            'status' => $isPaid ? 'success' : 'error',
            'paid' => $isPaid,
            'transaction_hash' => $hash,
            'bakong_verification' => $bakongRes,
            'attempt' => $attempt,
        ], $isPaid ? 200 : 422);
    }

    /**
     * Simulate Sandbox Payment Approval (strictly disabled outside local/testing opt-in)
     */
    public function simulatePay(Request $request, $id)
    {
        if (!app()->environment(['local', 'testing']) || !config('services.payments.allow_simulation', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment simulation is disabled in this environment.',
            ], 403);
        }

        $this->getTenantId($request, true);

        $attempt = DB::table('payment_attempts')->where('id', $id)->first()
            ?? DB::table('payment_attempts')->where('payment_id', $id)->first();

        if (!$attempt) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment attempt not found.',
            ], 404);
        }

        $this->authorizeTenantResource($attempt->tenant_id, $request, 'PaymentAttempt');

        DB::transaction(function () use ($attempt) {
            DB::table('payment_attempts')->where('id', $attempt->id)->update([
                'status' => 'paid',
                'updated_at' => now(),
            ]);

            DB::table('payments')->where('id', $attempt->payment_id)->update([
                'status' => 'paid',
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Payment simulated & approved successfully.',
            'paid' => true,
        ]);
    }

    /**
     * ABA PayWay callback receiver (verifies HMAC when configured and redacts logs)
     */
    public function abaCallback(Request $request)
    {
        $secret = (string) (config('services.payments.aba_webhook_secret') ?? '');
        $signatureHeader = $request->header('X-ABA-Signature')
            ?? $request->header('X-PayWay-Signature')
            ?? $request->header('X-Webhook-Signature');

        if ($secret !== '' || $signatureHeader !== null) {
            $expected = $secret !== '' ? hash_hmac('sha256', (string) $request->getContent(), $secret) : '';
            if ($secret === '' || !is_string($signatureHeader) || !hash_equals($expected, trim($signatureHeader))) {
                Log::warning('ABA PayWay webhook signature verification failed.', [
                    'ip' => $request->ip(),
                    'tran_id' => $request->input('tran_id'),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid webhook signature.',
                ], 401);
            }
        }

        $safePayload = $request->except(['token', 'api_key', 'secret', 'password']);
        Log::info('ABA PayWay callback received', [
            'tran_id' => $safePayload['tran_id'] ?? null,
            'status' => $safePayload['status'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment callback received',
            'data' => $safePayload,
        ]);
    }

    /**
     * NBC Bakong KHQR Production Webhook Receiver
     *
     * Verifies HMAC webhook signature (when configured) and Bakong Open API status
     * against authoritative DB payment attempt amount & currency before settling.
     */
    public function khqrWebhook(Request $request)
    {
        $webhookSecret = (string) (config('services.bakong.webhook_secret') ?? '');
        $signatureHeader = $request->header('X-Bakong-Signature')
            ?? $request->header('X-Webhook-Signature');

        if ($webhookSecret !== '' || $signatureHeader !== null) {
            $expectedSig = $webhookSecret !== '' ? hash_hmac('sha256', (string) $request->getContent(), $webhookSecret) : '';
            if ($webhookSecret === '' || !is_string($signatureHeader) || !hash_equals($expectedSig, trim($signatureHeader))) {
                Log::warning('NBC Bakong KHQR webhook signature rejected.', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid webhook signature.',
                ], 401);
            }
        }

        $safePayload = $request->except(['token', 'api_key', 'secret', 'password']);

        // Normalize fields across different Bakong webhook versions
        $hash = trim((string) (
            $request->input('hash')
            ?? $request->input('transaction_hash')
            ?? $request->input('data.hash')
            ?? $request->input('external_transaction_id')
            ?? ''
        ));

        $reference = trim((string) (
            $request->input('merchant_reference')
            ?? $request->input('bill_number')
            ?? $request->input('reference')
            ?? $request->input('data.merchantReference')
            ?? $request->input('data.merchant_reference')
            ?? $request->input('data.bill_number')
            ?? $request->input('external_reference')
            ?? ''
        ));

        Log::info('NBC Bakong KHQR Webhook received', [
            'merchant_reference' => $reference ?: null,
            'has_hash' => $hash !== '',
            'ip' => $request->ip(),
        ]);

        // Deduplication & Idempotency Key
        $dedupKey = !empty($hash)
            ? 'bakong_' . $hash
            : (!empty($reference) ? 'bakong_ref_' . $reference : 'bakong_evt_' . md5(json_encode($safePayload)));

        $existingEvent = DB::table('payment_webhook_events')
            ->where('dedup_key', $dedupKey)
            ->where('status', 'completed')
            ->first();

        if ($existingEvent) {
            return response()->json([
                'status' => 'success',
                'message' => 'NBC Bakong KHQR webhook already processed (idempotent).',
                'data' => [
                    'dedup_key' => $dedupKey,
                    'settled' => true,
                    'processed_at' => $existingEvent->processed_at,
                ],
            ], 200);
        }

        // Locate internal payment attempt & payment record
        $attempt = null;
        if (!empty($reference)) {
            $attempt = DB::table('payment_attempts')->where('merchant_reference', $reference)->first();
        }
        if (!$attempt && !empty($hash)) {
            $attempt = DB::table('payment_attempts')->where('provider_transaction_id', $hash)->first();
        }
        if (!$attempt && is_numeric($reference)) {
            $attempt = DB::table('payment_attempts')->where('id', $reference)->orWhere('payment_id', $reference)->first();
        }

        $payment = null;
        if ($attempt) {
            $payment = DB::table('payments')->where('id', $attempt->payment_id)->first();
        } elseif (!empty($reference)) {
            $payment = DB::table('payments')->where('reference_number', $reference)->first();
            if ($payment) {
                $attempt = DB::table('payment_attempts')->where('payment_id', $payment->id)->first();
            }
        }

        // Never allow client-forged flags (force_settle/force_confirm) or raw status strings to settle a payment.
        // Require a matching internal attempt/payment and server-side verification with NBC Bakong Open API.
        $bakongRes = null;
        $isPaid = false;

        if ($attempt) {
            $verifyAmount = (float) $attempt->amount;
            $verifyCurr = strtoupper((string) ($attempt->currency ?? $payment->currency ?? 'USD'));

            if ($hash !== '') {
                $bakongRes = $this->bakongService->checkTransactionByHash($hash, null, $verifyAmount, $verifyCurr);
            } elseif (!empty($attempt->provider_transaction_id)) {
                $bakongRes = $this->bakongService->checkTransactionByMd5((string) $attempt->provider_transaction_id, null);
            }

            if ($bakongRes !== null) {
                $isPaid = $this->bakongResponseMatchesAttempt($bakongRes, $attempt);
            }
        }

        DB::transaction(function () use ($isPaid, $attempt, $payment, $hash, $dedupKey, $safePayload, $bakongRes) {
            if ($isPaid && $attempt) {
                DB::table('payment_attempts')->where('id', $attempt->id)->update([
                    'status' => 'paid',
                    'provider_transaction_id' => $hash !== '' ? $hash : $attempt->provider_transaction_id,
                    'updated_at' => now(),
                ]);

                if ($payment) {
                    DB::table('payments')->where('id', $payment->id)->update([
                        'status' => 'paid',
                        'updated_at' => now(),
                    ]);

                    if (!empty($payment->sale_id)) {
                        DB::table('sales')->where('id', $payment->sale_id)->update([
                            'status' => 'completed',
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $tId = $attempt->tenant_id ?? $payment->tenant_id ?? null;
            DB::table('payment_webhook_events')->insert([
                'tenant_id' => (!empty($tId) && is_numeric($tId)) ? (int) $tId : null,
                'payment_attempt_id' => $attempt?->id,
                'dedup_key' => $dedupKey,
                'event_type' => 'bakong_khqr_webhook',
                'status' => $isPaid ? 'completed' : 'failed',
                'verified_payload' => json_encode($safePayload),
                'received_at' => now(),
                'processed_at' => $isPaid ? now() : null,
                'attempts' => 1,
                'last_error' => $isPaid ? null : ($bakongRes['response_message'] ?? 'Payment transaction unverified'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => $isPaid
                ? 'NBC Bakong KHQR transaction verified and sale auto-settled successfully.'
                : 'NBC Bakong KHQR webhook received; transaction pending verification.',
            'data' => [
                'settled' => $isPaid,
                'transaction_hash' => $hash ?: null,
                'merchant_reference' => $reference ?: null,
                'payment_id' => $payment?->id,
                'sale_id' => $payment?->sale_id,
                'bakong_verification' => $bakongRes,
            ],
        ], $isPaid ? 200 : 202);
    }

    /**
     * Confirm Bakong API paid status and ensure returned amount & currency match DB attempt.
     */
    protected function bakongResponseMatchesAttempt(array $bakongRes, object $attempt): bool
    {
        if (empty($bakongRes['paid'])) {
            return false;
        }

        $data = is_array($bakongRes['data'] ?? null) ? $bakongRes['data'] : [];
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            if (abs((float) $data['amount'] - (float) $attempt->amount) >= 0.01) {
                Log::warning('Bakong verification amount mismatch rejected.', [
                    'attempt_id' => $attempt->id,
                    'expected' => (float) $attempt->amount,
                    'received' => (float) $data['amount'],
                ]);
                return false;
            }
        }

        if (!empty($data['currency']) && is_string($data['currency'])) {
            $expectedCurrency = strtoupper((string) ($attempt->currency ?? 'USD'));
            if (strtoupper($data['currency']) !== $expectedCurrency) {
                Log::warning('Bakong verification currency mismatch rejected.', [
                    'attempt_id' => $attempt->id,
                    'expected' => $expectedCurrency,
                    'received' => strtoupper($data['currency']),
                ]);
                return false;
            }
        }

        return true;
    }
}
