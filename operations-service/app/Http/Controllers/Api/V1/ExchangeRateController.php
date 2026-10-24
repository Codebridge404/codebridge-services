<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

/**
 * [BD-0050] Multi-Currency Dynamic Exchange Rate Sync & Conversion APIs.
 *
 * @ticket BD-0050
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/ExchangeRateController.php
 * @related-service App\Services\ExchangeRateService
 * @entry-point API Routes: /api/v1/currencies/*
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Connects directly to live daily official rates from National Bank of Cambodia (NBC).
 *
 * @tags Multi-Currency
 */
#[Group('Multi-Currency')]
class ExchangeRateController extends Controller
{
    public function __construct(
        protected ExchangeRateService $exchangeRateService
    ) {}

    protected function getTenantId(Request $request): ?string
    {
        $val = $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;

        return (!empty($val) && trim((string)$val) !== '') ? (string) $val : null;
    }

    /**
     * Get the active NBC USD-KHR exchange rate and quick conversion presets.
     * GET /api/v1/currencies/exchange-rate/active
     */
    public function getActive(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $record = $this->exchangeRateService->getActiveRateRecord('USD', 'KHR', $tenantId);
        $rate = (float) ($record->rate ?? ExchangeRateService::DEFAULT_USD_KHR_RATE);

        // Pre-compute dual-currency POS denomination chips
        $presets = [
            1 => round(1 * $rate),
            5 => round(5 * $rate),
            10 => round(10 * $rate),
            20 => round(20 * $rate),
            50 => round(50 * $rate),
            100 => round(100 * $rate),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'base_currency' => 'USD',
                'target_currency' => 'KHR',
                'active_rate' => $rate,
                'bid_rate' => $record ? (float) $record->bid_rate : null,
                'ask_rate' => $record ? (float) $record->ask_rate : null,
                'source' => $record->source ?? 'NBC_BASELINE',
                'effective_date' => $record?->effective_date?->toDateString() ?? Carbon::today()->toDateString(),
                'last_synced_at' => $record?->synced_at?->toIso8601String(),
                'formatted' => '1 USD = ' . number_format($rate) . ' KHR',
                'presets' => $presets,
            ],
        ]);
    }

    /**
     * Get exchange rates history.
     * GET /api/v1/currencies/exchange-rate/history
     */
    public function history(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $query = ExchangeRate::query();

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $rates = $query->orderBy('effective_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'rates' => $rates,
            ],
        ]);
    }

    /**
     * Trigger live sync from National Bank of Cambodia (NBC).
     * POST /api/v1/currencies/exchange-rate/sync-nbc
     */
    public function syncNbc(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $forceRate = $request->input('rate') ? (float) $request->input('rate') : null;

        $record = $this->exchangeRateService->syncFromNbc($tenantId, $forceRate);

        return response()->json([
            'status' => 'success',
            'message' => 'National Bank of Cambodia (NBC) exchange rate synchronized successfully.',
            'data' => [
                'exchange_rate' => $record,
                'formatted' => '1 USD = ' . number_format((float) $record->rate) . ' KHR',
            ],
        ]);
    }

    /**
     * Manually update active exchange rate.
     * POST /api/v1/currencies/exchange-rate
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'rate' => 'required|numeric|min:1',
            'bid_rate' => 'nullable|numeric|min:1',
            'ask_rate' => 'nullable|numeric|min:1',
            'source' => 'nullable|string|max:50',
            'effective_date' => 'nullable|date',
        ]);

        $tenantId = $this->getTenantId($request);
        $effectiveDate = !empty($validated['effective_date'])
            ? Carbon::parse($validated['effective_date'])
            : Carbon::today();

        $record = $this->exchangeRateService->saveRateRecord(
            rate: (float) $validated['rate'],
            bidRate: isset($validated['bid_rate']) ? (float) $validated['bid_rate'] : null,
            askRate: isset($validated['ask_rate']) ? (float) $validated['ask_rate'] : null,
            source: $validated['source'] ?? 'MANUAL_OVERRIDE',
            effectiveDate: $effectiveDate,
            tenantId: $tenantId,
            rawPayload: ['updated_by' => $request->user()?->id]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Active exchange rate updated successfully.',
            'data' => [
                'exchange_rate' => $record,
            ],
        ], 201);
    }

    /**
     * Convert an amount between USD and KHR.
     * POST /api/v1/currencies/exchange-rate/convert
     */
    public function convert(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'from' => 'nullable|string|in:USD,KHR,usd,khr',
            'to' => 'nullable|string|in:USD,KHR,usd,khr',
        ]);

        $tenantId = $this->getTenantId($request);
        $from = strtoupper($validated['from'] ?? 'USD');
        $to = strtoupper($validated['to'] ?? ($from === 'USD' ? 'KHR' : 'USD'));

        $conversion = $this->exchangeRateService->convert(
            amount: (float) $validated['amount'],
            from: $from,
            to: $to,
            tenantId: $tenantId
        );

        return response()->json([
            'status' => 'success',
            'data' => $conversion,
        ]);
    }
}
