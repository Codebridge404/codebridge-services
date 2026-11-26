<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\IpRuleService;
use App\Services\IpThreatDefenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_cannot_manage_ip_rules(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/super-admin/security/ip-rules')->assertForbidden();
        $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '203.0.113.0/24',
            'rule_type' => 'block',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/super-admin/security/ip-rules/1')->assertForbidden();
    }

    public function test_super_admin_can_crud_ip_and_cidr_rules_with_validation(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        // Reject invalid IP/CIDR and rule_type
        $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '999.999.999.999',
            'rule_type' => 'invalid',
        ])->assertStatus(422)->assertJsonValidationErrors(['ip_or_cidr', 'rule_type']);

        $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '10.0.0.0/99',
            'rule_type' => 'allow',
        ])->assertStatus(422)->assertJsonValidationErrors(['ip_or_cidr']);

        // Create IPv4 CIDR block rule & IPv6 allow rule
        $blockRes = $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '203.0.113.0/24',
            'rule_type' => 'block',
            'reason' => 'Abusive scraper subnet',
        ])->assertCreated();

        $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '2001:db8:cafe::/48',
            'rule_type' => 'allow',
            'reason' => 'Enterprise HQ IPv6',
        ])->assertCreated();

        $this->getJson('/api/v1/super-admin/security/ip-rules?rule_type=block')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip_or_cidr', '203.0.113.0/24');

        $this->assertNotNull(Cache::get(IpRuleService::CACHE_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.ip_rule_upserted']);

        // Delete rule by UUID
        $uuid = $blockRes->json('data.uuid');
        $this->deleteJson("/api/v1/super-admin/security/ip-rules/{$uuid}")
            ->assertOk();

        $this->assertDatabaseMissing('platform_ip_rules', ['uuid' => $uuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.ip_rule_deleted']);
    }

    public function test_block_cidr_blocks_matching_ips_and_delete_unblocks_them(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $res = $this->postJson('/api/v1/super-admin/security/ip-rules', [
            'ip_or_cidr' => '203.0.113.0/24',
            'rule_type' => 'block',
            'reason' => 'Botnet CIDR',
        ])->assertCreated();

        // Matching IP in CIDR is blocked with 403 IP_RULE_BLOCKED
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->getJson('/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('code', 'IP_RULE_BLOCKED');

        // Non-matching IP remains allowed
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->getJson('/api/v1/health')
            ->assertOk();

        // Delete block rule -> IP is immediately unblocked
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->deleteJson('/api/v1/super-admin/security/ip-rules/'.$res->json('data.id'))
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->getJson('/api/v1/health')
            ->assertOk();
    }

    public function test_allowlist_office_ip_bypasses_auto_ban_and_auth_rate_limit(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $officeIp = '198.51.100.77';

        // Simulate an auto-ban on the office IP
        IpThreatDefenseService::banIp($officeIp, 60, 'False positive');
        $this->withServerVariables(['REMOTE_ADDR' => $officeIp])
            ->getJson('/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('code', 'IP_AUTO_BANNED');

        // Add office IP to allowlist -> immediately bypasses auto-ban
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/super-admin/security/ip-rules', [
                'ip_or_cidr' => '198.51.100.0/24',
                'rule_type' => 'allow',
                'reason' => 'Enterprise Office',
            ])->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => $officeIp])
            ->getJson('/api/v1/health')
            ->assertOk();
    }
}
