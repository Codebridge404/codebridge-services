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

    public function test_validation_errors_on_invalid_payload(): void
    {
        $tenant = $this->createTenant('Validation Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Empty payload
        $this->postJson('/api/v1/notification-providers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'channel', 'provider', 'environment', 'credentials']);

        // Invalid channel and environment enum values
        $this->postJson('/api/v1/notification-providers', [
            'name' => 'Bad Provider',
            'channel' => 'carrier_pigeon',
            'provider' => 'avian_service',
            'environment' => 'outer_space',
            'credentials' => ['key' => 'secret'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['channel', 'environment']);
    }

    public function test_tenant_admin_cannot_spoof_tenant_id_on_create(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $adminA = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantA->id,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        // Admin A tries to pass tenant_id of Tenant B
        $res = $this->postJson('/api/v1/notification-providers', [
            'tenant_id' => $tenantB->id,
            'name' => 'Spoof Attempt SMTP',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test.com'],
        ])->assertStatus(201);

        // Must be bound to Tenant A, NOT Tenant B
        $this->assertEquals($tenantA->id, $res->json('data.tenant_id'));
    }

    public function test_show_provider_by_numeric_id_and_by_uuid(): void
    {
        $tenant = $this->createTenant('Show Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lookup Provider',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'staging',
            'credentials' => ['bot_token' => '123:ABC'],
            'settings' => [],
            'is_enabled' => true,
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Lookup by integer ID
        $this->getJson("/api/v1/notification-providers/{$provider->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $provider->id)
            ->assertJsonPath('data.uuid', $provider->uuid);

        // Lookup by UUID
        $this->getJson("/api/v1/notification-providers/{$provider->uuid}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $provider->id)
            ->assertJsonPath('data.uuid', $provider->uuid);
    }

    public function test_index_filtering_by_channel_environment_enabled_and_search_q(): void
    {
        $tenant = $this->createTenant('Filtering Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Production Emailer',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.prod.com'],
            'is_enabled' => true,
            'created_by' => $admin->id,
        ]);

        NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staging Telegram Dispatcher',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'staging',
            'credentials' => ['bot_token' => '123'],
            'is_enabled' => false,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Filter channel=email
        $resEmail = $this->getJson('/api/v1/notification-providers?channel=email')->assertStatus(200);
        $this->assertCount(1, $resEmail->json('data'));
        $this->assertEquals('Production Emailer', $resEmail->json('data.0.name'));

        // Filter environment=staging
        $resStaging = $this->getJson('/api/v1/notification-providers?environment=staging')->assertStatus(200);
        $this->assertCount(1, $resStaging->json('data'));
        $this->assertEquals('Staging Telegram Dispatcher', $resStaging->json('data.0.name'));

        // Filter is_enabled=true
        $resEnabled = $this->getJson('/api/v1/notification-providers?is_enabled=true')->assertStatus(200);
        $this->assertCount(1, $resEnabled->json('data'));
        $this->assertEquals('Production Emailer', $resEnabled->json('data.0.name'));

        // Search q=Telegram
        $resSearch = $this->getJson('/api/v1/notification-providers?q=Telegram')->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertEquals('Staging Telegram Dispatcher', $resSearch->json('data.0.name'));
    }

    public function test_super_admin_index_filtering_by_tenant_id_and_global(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $superAdmin = User::factory()->inWorkspace()->create(['role' => 'super_admin']);

        // Global provider
        NotificationProvider::create([
            'tenant_id' => null,
            'name' => 'Platform Wide Alert',
            'channel' => 'webhook',
            'provider' => 'custom_webhook',
            'environment' => 'all',
            'credentials' => ['url' => 'https://example.com'],
            'is_enabled' => true,
            'created_by' => $superAdmin->id,
        ]);

        // Tenant A provider
        NotificationProvider::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alpha Slack Alerts',
            'channel' => 'slack',
            'provider' => 'slack_webhook',
            'environment' => 'production',
            'credentials' => ['url' => 'https://slack.com'],
            'is_enabled' => true,
            'created_by' => $superAdmin->id,
        ]);

        Sanctum::actingAs($superAdmin, ['*']);

        // Filter tenant_id=global
        $resGlobal = $this->getJson('/api/v1/notification-providers?tenant_id=global')->assertStatus(200);
        $this->assertCount(1, $resGlobal->json('data'));
        $this->assertEquals('Platform Wide Alert', $resGlobal->json('data.0.name'));

        // Filter tenant_id={id}
        $resTenant = $this->getJson("/api/v1/notification-providers?tenant_id={$tenantA->id}")->assertStatus(200);
        $this->assertCount(1, $resTenant->json('data'));
        $this->assertEquals('Alpha Slack Alerts', $resTenant->json('data.0.name'));
    }

    public function test_updating_non_credential_attributes_does_not_increment_config_version(): void
    {
        $tenant = $this->createTenant('Version Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Initial Name',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test.com'],
            'settings' => ['encryption' => 'tls'],
            'config_version' => 1,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Updating only name does not change config_version
        $res = $this->putJson("/api/v1/notification-providers/{$provider->id}", [
            'name' => 'Updated Friendly Name',
        ])->assertStatus(200);

        $this->assertEquals(1, $res->json('data.config_version'));
        $this->assertEquals('Updated Friendly Name', $res->json('data.name'));
    }

    public function test_update_delete_and_toggle_using_uuid(): void
    {
        $tenant = $this->createTenant('UUID Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'UUID Target',
            'channel' => 'slack',
            'provider' => 'slack_webhook',
            'environment' => 'staging',
            'credentials' => ['url' => 'https://slack.com/uuid'],
            'is_enabled' => false,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Toggle via UUID
        $this->patchJson("/api/v1/notification-providers/{$provider->uuid}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('is_enabled', true);

        // Update via UUID
        $this->putJson("/api/v1/notification-providers/{$provider->uuid}", [
            'name' => 'UUID Renamed',
        ])->assertStatus(200)
          ->assertJsonPath('data.name', 'UUID Renamed');

        // Delete via UUID
        $this->deleteJson("/api/v1/notification-providers/{$provider->uuid}")
            ->assertStatus(200);

        $this->assertNull(NotificationProvider::find($provider->id));
    }

    public function test_health_probe_probes_various_channels(): void
    {
        $tenant = $this->createTenant('Probe Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Telegram channel
        $telegram = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Telegram Alert',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'production',
            'credentials' => ['bot_token' => 'bot123', 'chat_id' => '-100'],
            'created_by' => $admin->id,
        ]);
        $this->postJson("/api/v1/notification-providers/{$telegram->id}/test")
            ->assertStatus(200)
            ->assertJsonPath('data.probe.status', 'healthy')
            ->assertJsonPath('data.channel', 'telegram');

        // Slack channel
        $slack = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Slack Alert',
            'channel' => 'slack',
            'provider' => 'slack_webhook',
            'environment' => 'production',
            'credentials' => ['webhook_url' => 'https://hooks.slack.com/services/test'],
            'created_by' => $admin->id,
        ]);
        $this->postJson("/api/v1/notification-providers/{$slack->id}/test")
            ->assertStatus(200)
            ->assertJsonPath('data.probe.status', 'healthy')
            ->assertJsonPath('data.channel', 'slack');

        // SMS channel
        $sms = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Twilio SMS',
            'channel' => 'sms',
            'provider' => 'twilio',
            'environment' => 'production',
            'credentials' => ['auth_token' => 'token123'],
            'created_by' => $admin->id,
        ]);
        $this->postJson("/api/v1/notification-providers/{$sms->id}/test", ['recipient' => '+85599887766'])
            ->assertStatus(200)
            ->assertJsonPath('data.probe.status', 'healthy')
            ->assertJsonPath('data.probe.target', '+85599887766');
    }

    public function test_audit_logs_recorded_for_all_mutations(): void
    {
        $tenant = $this->createTenant('Audit Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // 1. Create -> audit log
        $res = $this->postJson('/api/v1/notification-providers', [
            'name' => 'Audit Tested Provider',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.audit.com'],
        ])->assertStatus(201);
        $providerId = $res->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_provider.created',
            'module' => 'notifications',
            'entity_id' => (string) $providerId,
        ]);

        // 2. Update -> audit log
        $this->putJson("/api/v1/notification-providers/{$providerId}", [
            'name' => 'Audit Updated Provider',
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_provider.updated',
            'module' => 'notifications',
            'entity_id' => (string) $providerId,
        ]);

        // 3. Toggle -> audit log
        $this->patchJson("/api/v1/notification-providers/{$providerId}/toggle")->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_provider.toggled',
            'module' => 'notifications',
            'entity_id' => (string) $providerId,
        ]);

        // 4. Test -> audit log
        $this->postJson("/api/v1/notification-providers/{$providerId}/test")->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_provider.tested',
            'module' => 'notifications',
            'entity_id' => (string) $providerId,
        ]);

        // 5. Delete -> audit log
        $this->deleteJson("/api/v1/notification-providers/{$providerId}")->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification_provider.deleted',
            'module' => 'notifications',
            'entity_id' => (string) $providerId,
        ]);
    }
}
