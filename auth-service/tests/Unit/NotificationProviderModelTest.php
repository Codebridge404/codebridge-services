<?php

namespace Tests\Unit;

use App\Models\NotificationProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationProviderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_auto_generates_uuid_and_default_version(): void
    {
        $provider = NotificationProvider::create([
            'name' => 'Auto UUID Provider',
            'channel' => 'webhook',
            'provider' => 'custom_webhook',
            'environment' => 'production',
            'credentials' => ['url' => 'https://webhook.site/test'],
        ]);

        $this->assertNotEmpty($provider->uuid);
        $this->assertTrue(Str::isUuid($provider->uuid));
        $this->assertEquals(1, $provider->config_version);
        $this->assertFalse($provider->is_enabled);
    }

    public function test_credentials_encryption_and_decryption_handling(): void
    {
        $payload = [
            'api_key' => 'live_secret_key_123456789',
            'bot_token' => '987654321:XYZ',
            'password' => 'MyP@ssw0rd',
            'public_id' => 'pub_123',
        ];

        $provider = new NotificationProvider();
        $provider->credentials = $payload;

        // Raw encrypted value is not equal to plaintext JSON
        $this->assertNotEquals(json_encode($payload), $provider->getAttributes()['credentials']);

        // Decrypted returns exact array
        $decrypted = $provider->getDecryptedCredentials();
        $this->assertEquals($payload, $decrypted);

        // Masked credentials masks sensitive keys
        $masked = $provider->getMaskedCredentials();
        $this->assertEquals('liv••••789', $masked['api_key']);
        $this->assertEquals('987••••XYZ', $masked['bot_token']);
        $this->assertEquals('MyP••••0rd', $masked['password']);
        $this->assertEquals('pub_123', $masked['public_id']); // non-sensitive key preserved
    }

    public function test_masked_credentials_with_short_secret(): void
    {
        $provider = new NotificationProvider();
        $provider->credentials = ['password' => '12345']; // <= 6 chars

        $masked = $provider->getMaskedCredentials();
        $this->assertEquals('******', $masked['password']);
    }

    public function test_query_scopes(): void
    {
        $tenant = Tenant::create([
            'name' => 'Scope Tenant',
            'slug' => 'scope-tenant',
            'status' => 'active',
        ]);

        $p1 = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Prod Email',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test'],
            'is_enabled' => true,
        ]);

        $p2 = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staging SMS',
            'channel' => 'sms',
            'provider' => 'twilio',
            'environment' => 'staging',
            'credentials' => ['sid' => 'sid123'],
            'is_enabled' => false,
        ]);

        $global = NotificationProvider::create([
            'tenant_id' => null,
            'name' => 'Global All',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'all',
            'credentials' => ['token' => 't1'],
            'is_enabled' => true,
        ]);

        // scopeForChannel
        $this->assertEquals(1, NotificationProvider::forChannel('email')->count());
        $this->assertEquals($p1->id, NotificationProvider::forChannel('email')->first()->id);

        // scopeEnabled
        $this->assertEquals(2, NotificationProvider::enabled()->count());

        // scopeForEnvironment
        $this->assertEquals(2, NotificationProvider::forEnvironment('production')->count()); // includes 'all'
        $this->assertEquals(2, NotificationProvider::forEnvironment('staging')->count()); // includes 'all'

        // scopeForTenant
        $this->assertEquals(2, NotificationProvider::forTenant($tenant->id)->count());
        $this->assertEquals(3, NotificationProvider::forTenant($tenant->id, true)->count()); // includes global
        $this->assertEquals(1, NotificationProvider::forTenant(null)->count());
    }

    public function test_relationships(): void
    {
        $tenant = Tenant::create(['name' => 'Rel Tenant', 'slug' => 'rel-tenant', 'status' => 'active']);
        $creator = User::factory()->inWorkspace()->create(['tenant_id' => $tenant->id]);
        $updater = User::factory()->inWorkspace()->create(['tenant_id' => $tenant->id]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Relation Test',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test'],
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
        ]);

        $this->assertEquals($tenant->id, $provider->tenant->id);
        $this->assertEquals($creator->id, $provider->creator->id);
        $this->assertEquals($updater->id, $provider->updater->id);
    }
}
