<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Auth\UserDevice;
use App\Models\Organization\Tenant;
use App\Services\DeviceSessionService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceSessionGroupingTest extends TestCase
{
    use RefreshDatabase;

    private string $macUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    private string $iphoneUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    public function test_one_account_logs_in_on_mac_and_iphone_with_different_identifiers_and_ips(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $macRes = $this->withHeaders([
            'User-Agent' => $this->macUa,
            'X-Device-Id' => 'mac-install-uuid-1111-2222-3333',
        ])->withServerVariables([
            'REMOTE_ADDR' => '187.14.114.16',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $iphoneRes = $this->withHeaders([
            'User-Agent' => $this->iphoneUa,
            'X-Device-Id' => 'iphone-install-uuid-4444-5555-6666',
        ])->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.25',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $macToken = $macRes->json('access_token');
        $iphoneToken = $iphoneRes->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$macToken)->getJson('/api/v1/me')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$iphoneToken)->getJson('/api/v1/me')->assertOk();

        $this->assertDatabaseCount('user_devices', 2);
        $this->assertDatabaseCount('user_sessions', 2);

        $devices = $this->withHeader('Authorization', 'Bearer '.$macToken)
            ->withServerVariables(['REMOTE_ADDR' => '187.14.114.16'])
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $devices);
        $macDevice = collect($devices)->firstWhere('device_name', 'Mac Desktop');
        $iphoneDevice = collect($devices)->firstWhere('device_name', 'iPhone');

        $this->assertNotNull($macDevice);
        $this->assertNotNull($iphoneDevice);
        $this->assertEquals($user->email, $macDevice['account_email']);
        $this->assertEquals('Active', $macDevice['status']);
        $this->assertEquals('187.14.114.16', $macDevice['last_ip_address']);
        $this->assertTrue($macDevice['is_current']);
        $this->assertFalse($iphoneDevice['is_current']);
        $this->assertEquals('187.14.114.16', $macDevice['ip_address']);
        $this->assertEquals('192.0.2.25', $iphoneDevice['ip_address']);
        $this->assertTrue($macDevice['sessions'][0]['is_current']);
        $this->assertFalse($iphoneDevice['sessions'][0]['is_current']);
    }

    public function test_same_installation_relogin_with_changed_ip_updates_single_device_and_retains_login_history(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        // First login on Mac (web cookie issued automatically)
        $res1 = $this->withHeaders([
            'User-Agent' => $this->macUa,
        ])->withServerVariables([
            'REMOTE_ADDR' => '187.14.114.16',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $cookie = $res1->getCookie(DeviceSessionService::DEVICE_COOKIE_NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        // Second login on same Mac browser installation from a new network IP
        $res2 = $this->withCredentials()
            ->withHeaders([
                'User-Agent' => $this->macUa,
            ])->withUnencryptedCookie(
                DeviceSessionService::DEVICE_COOKIE_NAME,
                $cookie->getValue()
            )->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.88',
            ])->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ])->assertOk();

        // Still ONE device record, TWO separate login sessions
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseCount('user_sessions', 2);

        $devices = $this->withHeader('Authorization', 'Bearer '.$res2->json('access_token'))
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $devices);
        $this->assertEquals('198.51.100.88', $devices[0]['last_ip']);
        $this->assertEquals(2, $devices[0]['active_sessions_count']);
        $this->assertCount(2, $devices[0]['sessions']);

        $loginIps = collect($devices[0]['sessions'])->pluck('login_ip')->all();
        $this->assertContains('187.14.114.16', $loginIps);
        $this->assertContains('198.51.100.88', $loginIps);
    }

    public function test_different_identifiers_with_same_ip_remain_separate_devices(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $sharedIp = '203.0.113.50';

        $res1 = $this->withHeaders([
            'User-Agent' => $this->macUa,
            'X-Device-Id' => 'browser-chrome-install-aaaa1111',
        ])->withServerVariables(['REMOTE_ADDR' => $sharedIp])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $this->withHeaders([
            'User-Agent' => $this->macUa,
            'X-Device-Id' => 'browser-safari-install-bbbb2222',
        ])->withServerVariables(['REMOTE_ADDR' => $sharedIp])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $this->assertDatabaseCount('user_devices', 2);

        $devices = $this->withHeader('Authorization', 'Bearer '.$res1->json('access_token'))
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $devices);
        $this->assertCount(1, collect($devices)->where('is_current', true));
        $this->assertCount(1, collect($devices)->where('is_current', false));
    }

    public function test_active_session_ip_change_including_ipv6_updates_only_that_device_and_trusted_proxy(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $macRes = $this->withHeaders([
            'User-Agent' => $this->macUa,
            'X-Device-Id' => 'mac-install-ipv6-test-1111',
        ])->withServerVariables([
            'REMOTE_ADDR' => '187.14.114.16',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $iphoneRes = $this->withHeaders([
            'User-Agent' => $this->iphoneUa,
            'X-Device-Id' => 'iphone-install-ipv6-test-2222',
        ])->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.25',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        // Mac switches to an IPv6 network behind a trusted proxy (10.42.0.1)
        $this->withHeaders([
            'Authorization' => 'Bearer '.$macRes->json('access_token'),
        ])->withServerVariables([
            'REMOTE_ADDR' => '10.42.0.1',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::42, 10.42.0.1',
        ])->getJson('/api/v1/me')->assertOk();

        // Untrusted client-supplied X-Forwarded-For from non-proxy IP must NOT override REMOTE_ADDR
        $this->withHeaders([
            'Authorization' => 'Bearer '.$iphoneRes->json('access_token'),
        ])->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.25',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ])->getJson('/api/v1/me')->assertOk();

        $devices = $this->withHeader('Authorization', 'Bearer '.$macRes->json('access_token'))
            ->withServerVariables([
                'REMOTE_ADDR' => '10.42.0.1',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::42, 10.42.0.1',
            ])
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $macDevice = collect($devices)->firstWhere('device_name', 'Mac Desktop');
        $iphoneDevice = collect($devices)->firstWhere('device_name', 'iPhone');

        $this->assertEquals('2001:db8::42', $macDevice['last_ip']);
        $this->assertEquals('187.14.114.16', $macDevice['sessions'][0]['login_ip']);
        $this->assertEquals('2001:db8::42', $macDevice['sessions'][0]['ip_address']);
        $this->assertEquals('192.0.2.25', $iphoneDevice['last_ip']);
    }

    public function test_user_cannot_list_or_revoke_another_users_devices_across_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a-dev']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b-dev']);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'password' => Hash::make('Password123!')]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'password' => Hash::make('Password123!')]);

        $resA = $this->withHeader('X-Device-Id', 'user-a-device-11111111')
            ->postJson('/api/v1/auth/login', ['email' => $userA->email, 'password' => 'Password123!'])
            ->assertOk();

        $resB = $this->withHeader('X-Device-Id', 'user-b-device-22222222')
            ->postJson('/api/v1/auth/login', ['email' => $userB->email, 'password' => 'Password123!'])
            ->assertOk();

        $deviceUuidA = $resA->json('device_id');

        // User B listing sees only User B's device
        $devicesB = $this->withHeader('Authorization', 'Bearer '.$resB->json('access_token'))
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $devicesB);
        $this->assertNotEquals($deviceUuidA, $devicesB[0]['id']);

        // User B attempting to revoke User A's device gets 404
        $this->withHeader('Authorization', 'Bearer '.$resB->json('access_token'))
            ->deleteJson('/api/v1/auth/sessions/'.$deviceUuidA)
            ->assertNotFound();

        // User A remains authenticated
        $this->withHeader('Authorization', 'Bearer '.$resA->json('access_token'))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_revoking_one_device_rejects_its_access_and_refresh_credentials_while_other_remains_active(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        // Two sessions on Mac, one session on iPhone
        $mac1 = $this->withHeaders(['User-Agent' => $this->macUa, 'X-Device-Id' => 'mac-device-revoke-1111'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertOk();
        $mac2 = $this->withHeaders(['User-Agent' => $this->macUa, 'X-Device-Id' => 'mac-device-revoke-1111'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertOk();
        $iphone = $this->withHeaders(['User-Agent' => $this->iphoneUa, 'X-Device-Id' => 'iphone-device-keep-2222'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertOk();

        $iphoneDeviceId = $iphone->json('device_id');

        // Mac revokes iPhone device
        $this->withHeader('Authorization', 'Bearer '.$mac1->json('access_token'))
            ->deleteJson('/api/v1/auth/sessions/'.$iphoneDeviceId)
            ->assertOk();

        // iPhone access token and refresh token are rejected
        $this->withHeader('Authorization', 'Bearer '.$iphone->json('access_token'))
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $iphone->json('refresh_token'),
        ])->assertUnauthorized();

        // Mac sessions remain authenticated
        $this->withHeader('Authorization', 'Bearer '.$mac1->json('access_token'))->getJson('/api/v1/me')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$mac2->json('access_token'))->getJson('/api/v1/me')->assertOk();
    }

    public function test_cleared_identifier_creates_new_device_and_failed_auth_or_incomplete_mfa_creates_none(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecretKey(32);

        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'two_factor_enabled' => true,
        ]);
        $twoFactor->storeSecret($user, $secret);
        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $twoFactor->storeRecoveryCodes($user, $recoveryCodes);

        // Failed password
        $this->withHeader('X-Device-Id', 'failed-pwd-device-1111')
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPassword!',
            ])->assertStatus(422);

        $this->assertDatabaseCount('user_devices', 0);
        $this->assertDatabaseCount('user_sessions', 0);

        // Incomplete MFA (returns 403 challenge_id, no device or session registered yet)
        $challenge = $this->withHeader('X-Device-Id', 'mfa-pending-device-2222')
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ])->assertStatus(403);

        $this->assertNotEmpty($challenge->json('challenge_id'));
        $this->assertDatabaseCount('user_devices', 0);
        $this->assertDatabaseCount('user_sessions', 0);

        // Complete MFA registers device
        $code = $twoFactor->calculateCode($secret);
        $this->withHeader('X-Device-Id', 'mfa-verified-device-3333')
            ->postJson('/api/v1/auth/two-factor/challenge', [
                'challenge_id' => $challenge->json('challenge_id'),
                'code' => $code,
            ])->assertOk();

        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseCount('user_sessions', 1);

        // Cleared / new identifier creates a second device (using recovery code)
        $challenge2 = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertStatus(403);

        $this->withHeader('X-Device-Id', 'cleared-new-device-4444')
            ->postJson('/api/v1/auth/two-factor/challenge', [
                'challenge_id' => $challenge2->json('challenge_id'),
                'code' => $recoveryCodes[0],
            ])->assertOk();

        $this->assertDatabaseCount('user_devices', 2);
    }

    public function test_concurrent_device_registration_enforces_single_device_per_identifier(): void
    {
        $user = User::factory()->create();
        $service = app(DeviceSessionService::class);
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '187.14.114.16',
            'HTTP_USER_AGENT' => $this->macUa,
        ]);

        $d1 = $service->findOrCreateDevice($user, 'concurrent-install-id-9999', $request);
        $d2 = $service->findOrCreateDevice($user, 'concurrent-install-id-9999', $request);

        $this->assertEquals($d1->id, $d2->id);
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertNull(UserDevice::find($d1->id)->toArray()['identifier_hash'] ?? null);
    }

    public function test_ip_change_updates_current_ip_and_re_resolves_location_while_preserving_login_ip(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
        ]);

        // 1. Login from London VPN
        $loginResponse = $this->withHeaders([
            'User-Agent' => $this->macUa,
            'X-Device-Id' => 'vpn-switch-device-1111',
            'CF-IPCity' => 'London',
            'CF-Region' => 'England',
            'CF-IPCountry' => 'GB',
        ])->withServerVariables(['REMOTE_ADDR' => '187.13.136.64'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ])->assertOk();

        $token = $loginResponse->json('access_token') ?? $loginResponse->json('data.access_token');

        // Verify initial London location
        $this->withToken($token)
            ->withHeaders([
                'X-Device-Id' => 'vpn-switch-device-1111',
                'CF-IPCity' => 'London',
                'CF-Region' => 'England',
                'CF-IPCountry' => 'GB',
            ])->withServerVariables(['REMOTE_ADDR' => '187.13.136.64'])
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.last_ip', '187.13.136.64')
            ->assertJsonPath('data.0.location', 'London, England, GB')
            ->assertJsonPath('data.0.sessions.0.login_ip', '187.13.136.64');

        // 2. Disconnect VPN and send next request from Cambodia IP
        $this->withToken($token)
            ->withHeaders([
                'X-Device-Id' => 'vpn-switch-device-1111',
                'CF-IPCity' => 'Phnom Penh',
                'CF-Region' => 'Phnom Penh',
                'CF-IPCountry' => 'KH',
            ])->withServerVariables(['REMOTE_ADDR' => '103.10.10.10'])
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.last_ip', '103.10.10.10')
            ->assertJsonPath('data.0.location', 'Phnom Penh, KH')
            ->assertJsonPath('data.0.sessions.0.login_ip', '187.13.136.64')
            ->assertJsonPath('data.0.sessions.0.ip_address', '103.10.10.10');
    }
}
