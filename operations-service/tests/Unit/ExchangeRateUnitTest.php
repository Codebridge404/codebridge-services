<?php

namespace Tests\Unit;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateUnitTest extends TestCase
{
    use RefreshDatabase;

    protected ExchangeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExchangeRateService();

        // Seed active test exchange rate
        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => 4100.0000,
            'source' => 'NBC_LIVE',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);
        $this->service->clearCache();
    }

    /**
     * Test getting active exchange rate.
     */
    public function test_get_active_rate_resolves_correctly(): void
    {
        $rate = $this->service->getActiveRate('USD', 'KHR');
        $this->assertEquals(4100.0, $rate);

        // Same currency returns 1.0
        $this->assertEquals(1.0, $this->service->getActiveRate('USD', 'USD'));
    }

    /**
     * Test converting USD to KHR.
     */
    public function test_convert_usd_to_khr(): void
    {
        $res = $this->service->convert(10.0, 'USD', 'KHR');

        $this->assertEquals(10.0, $res['original_amount']);
        $this->assertEquals('USD', $res['from_currency']);
        $this->assertEquals('KHR', $res['to_currency']);
        $this->assertEquals(41000.0, $res['converted_amount']);
        $this->assertStringContainsString('41,000', $res['formatted']);
    }

    /**
     * Test converting KHR to USD.
     */
    public function test_convert_khr_to_usd(): void
    {
        $res = $this->service->convert(41000.0, 'KHR', 'USD');

        $this->assertEquals(41000.0, $res['original_amount']);
        $this->assertEquals('KHR', $res['from_currency']);
        $this->assertEquals('USD', $res['to_currency']);
        $this->assertEquals(10.00, $res['converted_amount']);
        $this->assertEquals('$10.00', $res['formatted']);
    }

    /**
     * Test converting same currency returns identical amount.
     */
    public function test_convert_same_currency(): void
    {
        $res = $this->service->convert(25.50, 'USD', 'USD');
        $this->assertEquals(25.50, $res['converted_amount']);
    }

    /**
     * Test cache clearing invalidates cached rate.
     */
    public function test_clear_cache_invalidates_active_key(): void
    {
        $this->service->getActiveRate('USD', 'KHR');
        $this->service->clearCache();
        $rateAfter = $this->service->getActiveRate('USD', 'KHR');
        $this->assertEquals(4100.0, $rateAfter);
    }
}

