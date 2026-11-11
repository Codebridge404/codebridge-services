<?php

namespace Tests\Feature;

use App\Models\NotificationProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $adminUserA;
    protected User $adminUserB;
    protected User $cashierUserA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Alerts Tenant Alpha',
            'slug' => 'alert-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Alerts Tenant Beta',
            'slug' => 'alert-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin.alert@alpha.com',
            'is_active' => true,
        ]);

        $this->adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin.alert@beta.com',
            'is_active' => true,
        ]);

        $this->cashierUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'cashier',
            'email' => 'cashier.alert@alpha.com',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/telegram-alerts/status')->assertUnauthorized();
        $this->getJson('/api/v1/telegram-alerts/history')->assertUnauthorized();
        $this->getJson('/api/v1/telegram-alerts/templates')->assertUnauthorized();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $this->actingAs($this->cashierUserA)
            ->getJson('/api/v1/telegram-alerts/status')
            ->assertForbidden();
    }

    public function test_status_endpoint_returns_bot_config_with_masked_token(): void
    {
        $rawBotToken = '123456789:SecretTelegramBotTokenXYZ';
        $chatId = '-100888999';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alert Bot';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = [
            'bot_username' => 'AlphaAlertBot',
            'default_chat_id' => $chatId,
        ];
        $provider->save();

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/telegram-alerts/status');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bot_enabled', true)
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.bot_username', 'AlphaAlertBot')
            ->assertJsonPath('data.default_chat_id', $chatId);

        // Token must be masked (no raw secret)
        $masked = $response->json('data.bot_token_masked');
        $this->assertStringContainsString('••••', $masked);
        $this->assertStringNotContainsString($rawBotToken, $masked);
    }

    public function test_alert_history_is_scoped_to_tenant(): void
    {
        DB::table('meeting_notifications')->insert([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'type' => 'telegram_reminder',
            'title' => 'Alpha Meeting Alert',
            'message' => 'Meeting starts in 15 minutes',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('meeting_notifications')->insert([
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'type' => 'telegram_reminder',
            'title' => 'Beta Meeting Alert',
            'message' => 'Meeting starts in 10 minutes',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/telegram-alerts/history');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Alpha Meeting Alert');
    }

    public function test_preview_and_template_management(): void
    {
        // 1. Preview template
        $previewRes = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/telegram-alerts/preview', [
                'content' => 'Meeting: {title} at {room}',
                'sample_variables' => [
                    'title' => 'Sprint Retrospective',
                    'room' => 'Conference Room 2',
                ],
            ]);
        $previewRes->assertOk()
            ->assertJsonPath('data.rendered', 'Meeting: Sprint Retrospective at Conference Room 2');

        // 2. Templates list
        $templatesRes = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/telegram-alerts/templates');
        $templatesRes->assertOk()
            ->assertJsonStructure(['data' => ['confirmation', 'reminder', 'change', 'cancellation']]);

        // 3. Update template
        $customBody = '📢 *Custom Notice*: Meeting {title} confirmed in {room}.';
        $updateRes = $this->actingAs($this->adminUserA)
            ->putJson('/api/v1/telegram-alerts/templates/confirmation', [
                'body' => $customBody,
            ]);
        $updateRes->assertOk();

        // 4. Reset template
        $resetRes = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/telegram-alerts/templates/confirmation/reset');
        $resetRes->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_send_test_alert_via_telegram_api(): void
    {
        $rawBotToken = '987654321:LiveSendTestToken';
        $chatId = '-100999000';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Test Bot';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        Http::fake([
            'https://api.telegram.org/bot' . $rawBotToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                    'chat' => ['id' => -100999000],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/telegram-alerts/send-test', [
                'message' => 'Hello from CodeBridge test!',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.delivered', true)
            ->assertJsonPath('data.telegram_message_id', 777);

        Http::assertSent(function ($request) use ($rawBotToken, $chatId) {
            return $request->url() === 'https://api.telegram.org/bot' . $rawBotToken . '/sendMessage'
                && $request['chat_id'] === $chatId
                && str_contains($request['text'], 'Hello from CodeBridge test!');
        });
    }
}

