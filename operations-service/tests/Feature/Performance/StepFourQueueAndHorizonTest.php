<?php

namespace Tests\Feature\Performance;

use App\Jobs\ExportTaxAuditJob;
use App\Jobs\SendShiftVarianceTelegramJob;
use App\Mail\DigitalReceiptMail;
use App\Mail\EndOfDayZReportMail;
use App\Mail\ShiftVarianceAlertMail;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashDrawerNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepFourQueueAndHorizonTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_configuration_supervisors_and_super_admin_gate(): void
    {
        $this->assertSame('codebridge_operations_horizon:', config('horizon.prefix'));
        $this->assertContains('notifications', config('horizon.defaults.supervisor-1.queue'));
        $this->assertContains('exports', config('horizon.defaults.exports-supervisor.queue'));
        $this->assertArrayHasKey('staging', config('horizon.environments'));
        $this->assertArrayHasKey('production', config('horizon.environments'));

        Config::set('horizon.allowed_emails', []);

        $cashier = User::factory()->create(['role' => 'cashier', 'email' => 'cashier@ops-horizon.test']);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'email' => 'super@ops-horizon.test']);

        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($cashier)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewHorizon'));
    }

    public function test_mailables_implement_should_queue_on_notifications_queue(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(ShiftVarianceAlertMail::class));
        $this->assertContains(ShouldQueue::class, class_implements(EndOfDayZReportMail::class));
        $this->assertContains(ShouldQueue::class, class_implements(DigitalReceiptMail::class));

        $receiptMail = new DigitalReceiptMail('RCP-1001', 'Flagship Store', '<html>ok</html>');
        $varianceMail = new ShiftVarianceAlertMail(['shift_id' => 1, 'variance_type' => 'shortage', 'cash_variance' => -15, 'tolerance_threshold' => 5]);
        $zMail = new EndOfDayZReportMail(['z_report_code' => 'Z-1001']);

        $this->assertSame('notifications', $receiptMail->queue);
        $this->assertSame('notifications', $varianceMail->queue);
        $this->assertSame('notifications', $zMail->queue);
    }

    public function test_shift_variance_alerts_queue_email_and_telegram_without_secret_in_payload(): void
    {
        Mail::fake();
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $secretToken = '987654:SECRET-OPS-TELEGRAM-TOKEN';
        Config::set('services.telegram.bot_token', $secretToken);
        Config::set('services.telegram.chat_id', '-100555444333');

        $cashier = User::factory()->create(['role' => 'cashier']);
        $cashier->setAttribute('tenant_id', 1);
        $shift = Shift::create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'register_id' => 1,
            'user_id' => $cashier->id,
            'opened_at' => now()->subHours(4),
            'opening_float' => 100.00,
            'status' => 'open',
        ]);

        /** @var CashDrawerNotificationService $service */
        $service = app(CashDrawerNotificationService::class);
        $result = $service->checkAndDispatchVarianceAlert(
            shift: $shift,
            cashVariance: -20.00,
            expectedCash: 100.00,
            countedCash: 80.00,
            closingNote: 'Async queue check',
            cashier: $cashier
        );

        $this->assertTrue($result['alert_triggered']);
        $this->assertTrue($result['channels']['telegram']);
        $this->assertTrue($result['channels']['email']);

        // No blocking Telegram HTTP POST during web request
        Http::assertNothingSent();

        Mail::assertQueued(ShiftVarianceAlertMail::class, function (ShiftVarianceAlertMail $mail) {
            return $mail->queue === 'notifications';
        });

        Queue::assertPushedOn('notifications', SendShiftVarianceTelegramJob::class, function (SendShiftVarianceTelegramJob $job) use ($secretToken, $shift) {
            $serialized = serialize($job);
            $this->assertStringNotContainsString($secretToken, $serialized);
            return $job->shiftId === $shift->id && $job->chatId === '-100555444333';
        });

        // Executing the job hits Telegram API with token loaded dynamically from config()
        (new SendShiftVarianceTelegramJob('-100555444333', 'Variance message', $shift->id))->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/bot' . $secretToken . '/sendMessage'));
    }

    public function test_digital_receipt_eod_zreport_and_tax_audit_dispatch_to_expected_queues(): void
    {
        Mail::fake();
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->setAttribute('tenant_id', 1);
        Sanctum::actingAs($admin, ['*']);

        DB::table('outlets')->insert([
            'id' => 1,
            'tenant_id' => 1,
            'name' => 'Sunny Cafe Main',
            'code' => 'MAIN-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'register_id' => 1,
            'user_id' => $admin->id,
            'receipt_number' => 'RCP-QUEUE-001',
            'idempotency_key' => 'idem-rcp-queue-001',
            'subtotal' => 10.00,
            'tax_total' => 1.00,
            'discount_total' => 0.00,
            'grand_total' => 11.00,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/sales/{$saleId}/send-receipt", [
            'channel' => 'email',
            'email' => 'customer@sunnycafe.kh',
        ])->assertStatus(200)
          ->assertJsonPath('status', 'success');

        Mail::assertQueued(DigitalReceiptMail::class, function (DigitalReceiptMail $mail) {
            return $mail->hasTo('customer@sunnycafe.kh')
                && $mail->receiptNumber === 'RCP-QUEUE-001'
                && $mail->queue === 'notifications';
        });

        $this->postJson('/api/v1/shifts/eod-dispatch', [
            'recipient_email' => 'owner@sunnycafe.kh',
            'outlet_id' => 1,
        ])->assertStatus(200)
          ->assertJsonPath('data.email_spooled', true);

        Mail::assertQueued(EndOfDayZReportMail::class, function (EndOfDayZReportMail $mail) {
            return $mail->hasTo('owner@sunnycafe.kh')
                && $mail->queue === 'notifications';
        });

        $this->postJson('/api/v1/reports/tax-audit/async', [
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date' => now()->toDateString(),
        ])->assertStatus(202);

        Queue::assertPushedOn('exports', ExportTaxAuditJob::class);
    }
}
