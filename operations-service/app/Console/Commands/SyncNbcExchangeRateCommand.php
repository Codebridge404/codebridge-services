<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * [BD-0104] National Bank of Cambodia Forex Rate Scheduled Sync Command
 *
 * @ticket BD-0104
 * @component operations-service
 * @file codebridge-services/operations-service/app/Console/Commands/SyncNbcExchangeRateCommand.php
 * @related-model App\Services\ExchangeRateService, DB table exchange_rates
 * @entry-point SyncNbcExchangeRateCommand::handle()
 * @routes
 *  - Command: php artisan exchange-rate:sync-nbc
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Scheduled Cron Trigger): Executes daily scheduled job at 08:30 AM to capture official NBC exchange rates.
 * Step 2 (External API Ingestion): Queries National Bank of Cambodia API or official bank exchange rate feed.
 * Step 3 (Rate Parsing & Validation): Validates USD/KHR currency pair rate against safety bounds (e.g. 4000 to 4200 KHR).
 * Step 4 (Database Upsert): Updates exchange_rates table with official buy, sell, and mid market rates for today.
 * Step 5 (Cache Invalidation): Clears distributed Redis exchange rate caches so POS terminals immediately use updated rates.
 */
class SyncNbcExchangeRateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'currency:sync-nbc 
                            {--tenant= : Optional tenant ID to scope exchange rate sync}
                            {--rate= : Optional manual override rate (e.g. 4105.00)}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch official live daily USD-KHR exchange rate from National Bank of Cambodia (NBC) and update active rate';

    /**
     * Execute the console command.
     */
    public function handle(ExchangeRateService $exchangeRateService): int
    {
        $tenantId = $this->option('tenant');
        $forceRate = $this->option('rate') ? (float) $this->option('rate') : null;

        $this->info("🔄 Initiating National Bank of Cambodia (NBC) Exchange Rate Synchronization...");

        try {
            $record = $exchangeRateService->syncFromNbc($tenantId, $forceRate);

            $this->info("✅ NBC Exchange Rate successfully synchronized!");
            $this->table(
                ['ID', 'Base', 'Target', 'Active Rate', 'Bid / Ask', 'Source', 'Effective Date', 'Active?'],
                [[
                    $record->id,
                    $record->base_currency,
                    $record->target_currency,
                    number_format((float) $record->rate, 2) . ' KHR',
                    number_format((float) ($record->bid_rate ?? 0), 2) . ' / ' . number_format((float) ($record->ask_rate ?? 0), 2),
                    $record->source,
                    $record->effective_date?->toDateString(),
                    $record->is_active ? 'YES' : 'NO',
                ]]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to synchronize NBC exchange rate: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
