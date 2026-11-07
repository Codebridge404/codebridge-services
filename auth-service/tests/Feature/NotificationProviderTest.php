<?php

namespace Tests\Feature;

use App\Models\NotificationProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name = 'Test Org'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'active',
        ]);
    }

    public function test_notification_providers_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/notification-providers')->assertStatus(401);
    }

    public function test_subordinate_roles_cannot_access_notification_providers(): void
    {
        $tenant = $this->createTenant();
        $cashier = User::factory()->inWorkspace()->create([
            'role' => 'cashier',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/v1/notification-providers')->assertStatus(403);
    }

    public function test_organization_admin_can_create_notification_provider(): void
    {
        $tenant = $this->createTenant('Sunny Cafe');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'name' => 'Hostinger Production SMTP',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => [
                'host' => 'smtp.hostinger.com',
                'port' => 587,
                'username' => 'noreply@sunnycafe.kh',
                'password' => 'SuperSecretSmtpPass2026!',
            ],
            'settings' => [
                'encryption' => 'tls',
                'from_address' => 'noreply@sunnycafe.kh',
                'from_name' => 'Sunny Cafe Phnom Penh',
            ],
            'is_enabled' => true,
        ];

        $res = $this->postJson('/api/v1/notification-providers', $payload)
            ->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Hostinger Production SMTP')
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.provider', 'smtp')
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.config_version', 1)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $providerId = $res->json('data.id');
        $this->assertNotNull($providerId);

        // Verify credentials encrypted in database
        $rawRecord = DB::table('notification_providers')->where('id', $providerId)->first();
        $this->assertNotEmpty($rawRecord->credentials);
        $this->assertStringNotContainsString('SuperSecretSmtpPass2026!', $rawRecord->credentials);

        // Verify masked in response (never returns plaintext)
        $this->assertStringNotContainsString('SuperSecretSmtpPass2026!', $res->json('data.masked_credentials.password'));
        $this->assertEquals('Sup••••26!', $res->json('data.masked_credentials.password'));
    }

    public function test_super_admin_can_create_global_platform_notification_provider(): void
    {
        $superAdmin = User::factory()->inWorkspace()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $res = $this->postJson('/api/v1/notification-providers', [
            'name' => 'Global Platform Telegram Incident Bot',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'all',
            'credentials' => [
                'bot_token' => '1234567890:ABCdefGhIJKlmNoPQRsTUVwxyZ',
                'chat_id' => '-100987654321',
            ],
            'settings' => [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ],
            'is_enabled' => true,
        ])->assertStatus(201)
          ->assertJsonPath('status', 'success')
          ->assertJsonPath('data.tenant_id', null);

        $this->assertNull($res->json('data.tenant_id'));
    }

    public function test_updating_credentials_or_settings_increments_config_version(): void
    {
        $tenant = $this->createTenant('Slack Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Slack Operations Webhook',
            'channel' => 'slack',
            'provider' => 'slack_webhook',
            'environment' => 'staging',
            'credentials' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/X00'],
            'settings' => ['channel_override' => '#alerts-staging'],
            'is_enabled' => true,
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Update settings
        $res = $this->putJson("/api/v1/notification-providers/{$provider->id}", [
            'settings' => ['channel_override' => '#alerts-critical'],
        ])->assertStatus(200)
          ->assertJsonPath('status', 'success')
          ->assertJsonPath('data.config_version', 2)
          ->assertJsonPath('data.settings.channel_override', '#alerts-critical');

        // Update credentials
        $this->putJson("/api/v1/notification-providers/{$provider->id}", [
            'credentials' => ['webhook_url' => 'https://hooks.slack.com/services/T00/B00/X01_NEW'],
        ])->assertStatus(200)
          ->assertJsonPath('data.config_version', 3);
    }

    public function test_toggle_provider_endpoint(): void
    {
        $tenant = $this->createTenant('Twilio Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Twilio SMS Gateway',
            'channel' => 'sms',
            'provider' => 'twilio',
            'environment' => 'production',
            'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'auth456'],
            'settings' => ['from_number' => '+15551234567'],
            'is_enabled' => false,
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/notification-providers/{$provider->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('is_enabled', true);

        $this->assertTrue($provider->fresh()->is_enabled);

        $this->patchJson("/api/v1/notification-providers/{$provider->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('is_enabled', false);

        $this->assertFalse($provider->fresh()->is_enabled);
    }

    public function test_health_probe_test_endpoint(): void
    {
        $tenant = $this->createTenant('Mailgun Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Mailgun Enterprise',
            'channel' => 'email',
            'provider' => 'mailgun',
            'environment' => 'production',
            'credentials' => ['api_key' => 'key-1234567890abcdef', 'domain' => 'mail.sunnycafe.kh'],
            'settings' => ['from_address' => 'noreply@sunnycafe.kh'],
            'is_enabled' => true,
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        $this->assertNull($provider->last_tested_at);

        Sanctum::actingAs($admin, ['*']);

        $res = $this->postJson("/api/v1/notification-providers/{$provider->id}/test", [
            'recipient' => 'test@sunnycafe.kh',
        ])->assertStatus(200)
          ->assertJsonPath('status', 'success')
          ->assertJsonPath('data.probe.status', 'healthy');

        $this->assertNotNull($res->json('data.last_tested_at'));
        $this->assertNotNull($provider->fresh()->last_tested_at);
    }

    public function test_tenant_boundary_isolation(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $adminA = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantA->id,
        ]);

        $adminB = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantB->id,
        ]);

        $providerA = NotificationProvider::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A SMTP',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.tenant-a.com'],
            'settings' => [],
            'is_enabled' => true,
            'config_version' => 1,
            'created_by' => $adminA->id,
        ]);

        // Admin B should NOT see Provider A in index
        Sanctum::actingAs($adminB, ['*']);
        $res = $this->getJson('/api/v1/notification-providers')->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($providerA->id, $ids);

        // Admin B should NOT be able to view, update, toggle, test, or delete Provider A
        $this->getJson("/api/v1/notification-providers/{$providerA->id}")->assertStatus(404);
        $this->putJson("/api/v1/notification-providers/{$providerA->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->patchJson("/api/v1/notification-providers/{$providerA->id}/toggle")->assertStatus(404);
        $this->postJson("/api/v1/notification-providers/{$providerA->id}/test")->assertStatus(404);
        $this->deleteJson("/api/v1/notification-providers/{$providerA->id}")->assertStatus(404);

        $this->assertEquals('Tenant A SMTP', $providerA->fresh()->name);
    }

    public function test_delete_notification_provider(): void
    {
        $tenant = $this->createTenant('Temp Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Temporary Slack Channel',
            'channel' => 'slack',
            'provider' => 'slack_webhook',
            'environment' => 'development',
            'credentials' => ['webhook_url' => 'https://hooks.slack.com/services/temp'],
            'settings' => [],
            'is_enabled' => false,
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/v1/notification-providers/{$provider->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNull(NotificationProvider::find($provider->id));
    }
}
