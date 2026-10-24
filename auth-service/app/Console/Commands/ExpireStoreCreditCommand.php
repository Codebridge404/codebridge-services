<?php

namespace App\Console\Commands;

use App\Services\StoreCreditService;
use Illuminate\Console\Command;

class ExpireStoreCreditCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'store-credit:expire 
                            {--tenant= : Optional tenant ID to scope expiration process}';

    /**
     * The console command description.
     */
    protected $description = 'Process expired digital vouchers and customer store credit tranches past their validity date';

    /**
     * [BD-0034] Process Customer Store Credit & Digital Voucher Expirations.
     *
     * @ticket BD-0034
     * @component auth-service
     * @file codebridge-services/auth-service/app/Console/Commands/ExpireStoreCreditCommand.php
     * @related-service App\Services\StoreCreditService (processExpirations)
     * @command-trigger php artisan store-credit:expire {--tenant=}
     * @date-time 2026-10-23T15:33:00+07:00
     *
     * Process Steps:
     * 1. Resolve optional tenant scoping.
     * 2. Execute atomic sweep of expired digital vouchers and credit tranches.
     * 3. Apply pessimistic locking on affected customer records.
     * 4. Deduct expired balances and append immutable ledger audit entries.
     * 5. Render summary metrics table to console output.
     *
     * @param StoreCreditService $storeCreditService
     * @return int Command::SUCCESS | Command::FAILURE
     */
    public function handle(StoreCreditService $storeCreditService): int
    {
        // [BD-0034] Step 1: Resolve tenant option
        $tenantId = $this->option('tenant');
        $this->info("⏳ Running customer store credit and digital voucher expiration sweep...");

        try {
            // [BD-0034] Step 2: Execute atomic expiration sweep via StoreCreditService
            $summary = $storeCreditService->processExpirations($tenantId);

            // [BD-0034] Step 3: Render execution telemetry to CLI
            $this->info("✅ Expiration sweep completed successfully!");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Expired Digital Vouchers', $summary['expired_vouchers']],
                    ['Expired Credit Tranches', $summary['expired_tranches']],
                    ['Total Expired Credit Reclaimed', '$' . number_format($summary['total_expired_credit'], 2)],
                    ['Affected Customer Wallets', $summary['affected_customers']],
                    ['Processed Timestamp', $summary['processed_at']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to process store credit expirations: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
