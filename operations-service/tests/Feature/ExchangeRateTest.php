<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rate_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/currencies/exchange-rate/active')->assertStatus(401);
        $this->getJson('/api/v1/currencies/exchange-rate/history')->assertStatus(401);
        $this->postJson('/api/v1/currencies/exchange-rate/sync-nbc')->assertStatus(401);
        $this->postJson('/api/v1/currencies/exchange-rate/convert', ['amount' => 10])->assertStatus(401);
    }

    public function test_authenticated_user_can_get_active_nbc_rate(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        Sanctum::actingAs($user, ['*']);

        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => 4120.0000,
            'bid_rate' => 4115.0000,
            'ask_rate' => 4125.0000,
            'source' => 'NBC_LIVE',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/currencies/exchange-rate/active');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'base_currency' => 'USD',
                    'target_currency' => 'KHR',
                    'active_rate' => 4120,
                    'bid_rate' => 4115,
                    'ask_rate' => 4125,
                    'source' => 'NBC_LIVE',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(4120 * 10, $data['presets'][10]);
    }

    public function test_sync_nbc_exchange_rate_updates_active_rate(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/currencies/exchange-rate/sync-nbc', [
            'rate' => 4118.50,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'National Bank of Cambodia (NBC) exchange rate synchronized successfully.',
            ]);

        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 4118.50,
            'is_active' => true,
        ]);
    }

    public function test_currency_conversion_usd_to_khr_and_vice_versa(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        Sanctum::actingAs($user, ['*']);

        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => 4100.0000,
            'source' => 'NBC_OFFICIAL',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'synced_at' => now(),
        ]);

        // USD to KHR conversion: $15.50 * 4,100 = 63,550 KHR
        $resUsd = $this->postJson('/api/v1/currencies/exchange-rate/convert', [
            'amount' => 15.50,
            'from' => 'USD',
            'to' => 'KHR',
        ]);

        $resUsd->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'original_amount' => 15.50,
                    'from_currency' => 'USD',
                    'converted_amount' => 63550,
                    'to_currency' => 'KHR',
                ],
            ]);

        // KHR to USD conversion: 82,000 KHR / 4,100 = $20.00
        $resKhr = $this->postJson('/api/v1/currencies/exchange-rate/convert', [
            'amount' => 82000,
            'from' => 'KHR',
            'to' => 'USD',
        ]);

        $resKhr->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'original_amount' => 82000,
                    'from_currency' => 'KHR',
                    'converted_amount' => 20.00,
                    'to_currency' => 'USD',
                ],
            ]);
    }

    public function test_artisan_sync_nbc_command_executes_successfully(): void
    {
        $exitCode = Artisan::call('currency:sync-nbc', [
            '--rate' => '4108.00',
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 4108.00,
            'is_active' => true,
        ]);
    }
}
