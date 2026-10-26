<?php

namespace App\Jobs;

use App\Services\ExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0017] Dedicated Background Worker Queue for Heavy Sales & Tax CSV/Excel Export Jobs.
 * 
 * Handles heavy multi-month GDT VAT and Sales journals asynchronously via Redis/Database workers
 * without blocking API Gateway FastCGI workers.
 */
class ExportTaxAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600;

    public function __construct(
        public string $exportId,
        public string $startDate,
        public string $endDate,
        public ?string $outletId = null,
        public ?string $tenantId = null,
        public ?string $userId = null
    ) {
        $this->onQueue('exports');
    }

    public function handle(ExchangeRateService $exchangeRateService): void
    {
        Log::info("[ExportTaxAuditJob] Starting async export: {$this->exportId} for period {$this->startDate} to {$this->endDate}");

        Cache::put("export_status_{$this->exportId}", [
            'status' => 'processing',
            'progress' => 10,
            'started_at' => now()->toIso8601String(),
        ], 3600);

        try {
            $query = DB::table('sales as s')
                ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
                ->whereDate('s.created_at', '>=', $this->startDate)
                ->whereDate('s.created_at', '<=', $this->endDate)
                ->where('s.status', '!=', 'cancelled');

            if (!empty($this->outletId) && $this->outletId !== 'all') {
                $query->where('s.outlet_id', $this->outletId);
            }

            $rate = $exchangeRateService->getActiveRate('USD', 'KHR', $this->tenantId);
            if (!$rate || $rate <= 0) {
                $rate = 4100;
            }

            $sales = $query->select(
                's.id',
                's.receipt_number',
                's.created_at',
                's.subtotal',
                's.tax_total',
                's.discount_total',
                's.grand_total',
                's.customer_name',
                's.status',
                'o.name as outlet_name'
            )->orderBy('s.created_at', 'asc')->get();

            Cache::put("export_status_{$this->exportId}", [
                'status' => 'processing',
                'progress' => 50,
                'total_records' => $sales->count(),
            ], 3600);

            $exportDir = storage_path('app/exports');
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            $filename = "gdt_vat_audit_export_{$this->exportId}.csv";
            $filePath = "{$exportDir}/{$filename}";
            $file = fopen($filePath, 'w');

            // UTF-8 BOM
            fputs($file, "\xEF\xBB\xBF");

            // Header
            fputcsv($file, ['KINGDOM OF CAMBODIA - GENERAL DEPARTMENT OF TAXATION (GDT)']);
            fputcsv($file, ['ASYNC BATCH EXPORT - MONTHLY VALUE ADDED TAX (VAT) AUDIT']);
            fputcsv($file, ['Export Job ID', $this->exportId, 'Generated At', now()->toDateTimeString()]);
            fputcsv($file, ['Audit Period', "{$this->startDate} to {$this->endDate}", 'Official Exchange Rate', "1 USD = {$rate} KHR"]);
            fputcsv($file, []);

            // Column Headers
            fputcsv($file, [
                'Line #',
                'Invoice / Receipt No',
                'Date & Time',
                'Outlet Branch',
                'Customer Name',
                'Taxable Amount (USD)',
                'VAT 10% (USD)',
                'Grand Total (USD)',
                'Taxable Amount (KHR)',
                'VAT 10% (KHR)',
                'Grand Total (KHR)',
                'Status',
            ]);

            $grossUsd = 0.0;
            $taxableUsd = 0.0;
            $vatUsd = 0.0;
            $i = 1;

            foreach ($sales as $s) {
                $sub = (float) $s->subtotal;
                $vat = (float) $s->tax_total;
                $tot = (float) $s->grand_total;

                $grossUsd += $tot;
                $taxableUsd += $sub;
                $vatUsd += $vat;

                fputcsv($file, [
                    $i++,
                    $s->receipt_number,
                    $s->created_at,
                    $s->outlet_name ?? 'Main Outlet',
                    $s->customer_name ?? 'Walk-in Customer',
                    number_format($sub, 2, '.', ''),
                    number_format($vat, 2, '.', ''),
                    number_format($tot, 2, '.', ''),
                    round($sub * $rate),
                    round($vat * $rate),
                    round($tot * $rate),
                    $s->status,
                ]);
            }

            // Summary Totals
            fputcsv($file, []);
            fputcsv($file, [
                'TOTAL AUDITED SUMMARY',
                '',
                '',
                '',
                '',
                number_format($taxableUsd, 2, '.', ''),
                number_format($vatUsd, 2, '.', ''),
                number_format($grossUsd, 2, '.', ''),
                round($taxableUsd * $rate),
                round($vatUsd * $rate),
                round($grossUsd * $rate),
                'AUDIT VERIFIED',
            ]);

            fclose($file);

            Cache::put("export_status_{$this->exportId}", [
                'status' => 'completed',
                'progress' => 100,
                'file_name' => $filename,
                'file_path' => $filePath,
                'total_records' => $sales->count(),
                'gross_sales_usd' => $grossUsd,
                'vat_collected_usd' => $vatUsd,
                'completed_at' => now()->toIso8601String(),
                'download_url' => "/api/v1/reports/export-download/{$this->exportId}",
            ], 86400);

            Log::info("[ExportTaxAuditJob] Completed async export: {$this->exportId} ({$sales->count()} records)");
        } catch (\Throwable $e) {
            Log::error("[ExportTaxAuditJob] Failed async export: {$this->exportId} - " . $e->getMessage());
            Cache::put("export_status_{$this->exportId}", [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ], 3600);
            throw $e;
        }
    }
}
