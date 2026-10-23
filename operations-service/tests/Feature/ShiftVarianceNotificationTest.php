<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftVarianceNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/shifts/variance-alerts')->assertStatus(401);
        $this->getJson('/api/v1/shifts/variance-config')->assertStatus(401);
        $this->postJson('/api/v1/shifts/test-variance-alert')->assertStatus(401);
    }

    public function test_shift_close_within_tolerance_does_not_trigger_alert(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        Sanctum::actingAs($cashier, ['*']);

        $shift = Shift::create([
            'outlet_id' => 1,
            'register_id' => 1,
            'user_id' => $cashier->id,
            'opened_at' => now()->subHours(8),
            'opening_float' => 100.00,
            'status' => 'open',
        ]);

        // Counted cash is $102.00 (variance = +$2.00, within $5.00 tolerance)
        $response = $this->postJson("/api/v1/shifts/{$shift->id}/close", [
            'counted_cash' => 102.00,
            'closing_note' => 'Normal shift close',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'shift_id' => $shift->id,
                    'cash_variance' => 2.00,
                    'variance_alert' => [
                        'alert_triggered' => false,
                        'tolerance_threshold' => 5.00,
                    ],
                ],
            ]);

        $this->assertDatabaseMissing('shift_variance_alerts', [
            'shift_id' => $shift->id,
        ]);
    }

    public function test_shift_close_exceeding_tolerance_triggers_automated_notification(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'pin_code' => '1234',
        ]);
        Sanctum::actingAs($admin, ['*']);

        $shift = Shift::create([
            'outlet_id' => 1,
            'register_id' => 1,
            'user_id' => $admin->id,
            'opened_at' => now()->subHours(8),
            'opening_float' => 150.00,
            'status' => 'open',
        ]);

        // Counted cash is $125.00 (shortage of -$25.00, exceeding $5.00 tolerance)
        $response = $this->postJson("/api/v1/shifts/{$shift->id}/close", [
            'counted_cash' => 125.00,
            'closing_note' => 'Drawer discrepancy noticed at shift handoff',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'shift_id' => $shift->id,
                    'expected_cash' => 150.00,
                    'counted_cash' => 125.00,
                    'cash_variance' => -25.00,
                    'variance_alert' => [
                        'alert_triggered' => true,
                        'tolerance_threshold' => 5.00,
                        'cash_variance' => -25.00,
                        'variance_type' => 'shortage',
                    ],
                ],
            ]);

        // Alert persisted in database
        $this->assertDatabaseHas('shift_variance_alerts', [
            'shift_id' => $shift->id,
            'expected_cash' => 150.00,
            'counted_cash' => 125.00,
            'cash_variance' => -25.00,
            'variance_type' => 'shortage',
        ]);
    }

    public function test_can_view_variance_alerts_and_config(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);

        DB::table('shift_variance_alerts')->insert([
            'shift_id' => 99,
            'user_id' => $admin->id,
            'outlet_id' => 1,
            'expected_cash' => 200.00,
            'counted_cash' => 170.00,
            'cash_variance' => -30.00,
            'tolerance_threshold' => 5.00,
            'variance_type' => 'shortage',
            'closing_note' => 'Audit variance test',
            'telegram_dispatched' => true,
            'email_dispatched' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Test GET variance alerts
        $alertsRes = $this->getJson('/api/v1/shifts/variance-alerts');
        $alertsRes->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'alerts',
                ],
            ]);
        $this->assertCount(1, $alertsRes->json('data.alerts'));

        // Test GET variance config
        $configRes = $this->getJson('/api/v1/shifts/variance-config');
        $configRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'tolerance_threshold' => 5.00,
                    'channels' => [
                        'telegram' => [],
                        'email' => [],
                    ],
                ],
            ]);
    }

    public function test_can_dispatch_test_variance_alert(): void
    {
        $manager = User::factory()->create(['role' => 'outlet_manager']);
        Sanctum::actingAs($manager, ['*']);

        $response = $this->postJson('/api/v1/shifts/test-variance-alert', [
            'variance_amount' => -18.50,
            'note' => 'Weekly drawer audit dry-run',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Automated shift float variance test notification dispatched.',
                'data' => [
                    'alert_triggered' => true,
                    'cash_variance' => -18.50,
                    'variance_type' => 'shortage',
                ],
            ]);
    }
}
