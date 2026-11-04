<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActiveSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test sessions list returns complete metadata:
     * id, device_name, browser, platform, ip_address, is_current, last_used_at, location
     */
    public function test_sessions_list_returns_full_metadata(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        // Login with macOS Chrome User-Agent
        $macUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        $loginRes = $this->withServerVariables([
            'HTTP_USER_AGENT' => $macUa,
            'REMOTE_ADDR' => '198.51.100.42',
            'HTTP_CF_IPCITY' => 'Bangkok',
            'HTTP_CF_IPCOUNTRY' => 'Thailand',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $token = $loginRes->json('access_token');

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk();

        $res->assertJsonStructure([
            'status',
            'data' => [
                '*' => [
                    'id',
                    'device_name',
                    'browser',
                    'platform',
                    'ip_address',
                    'is_current',
                    'last_used_at',
                    'created_at',
                ],
            ],
            'session_inactivity_days',
            'available_inactivity_options',
        ]);

        $session = $res->json('data.0');
        $this->assertEquals('Mac Desktop', $session['device_name']);
        $this->assertStringContainsString('Chrome', $session['browser']);
        $this->assertEquals('macOS', $session['platform']);
        $this->assertEquals('198.51.100.42', $session['ip_address']);
        $this->assertTrue($session['is_current']);
        $this->assertEquals('Bangkok, Thailand', $session['location']);
        $this->assertNotNull($session['last_used_at']);
    }

    /**
     * Test real-IP preservation across proxy chain (Nginx/Kubernetes trusted proxy).
     * Prevents internal pod IP (10.42.0.1) from overwriting visitor IP.
     */
    public function test_real_ip_preservation_through_trusted_proxies(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        // Request forwarded through Kubernetes Ingress (10.42.0.1) with client real IP in X-Forwarded-For
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.42.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195, 10.42.0.1',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $token = $response->json('access_token');

        $sessionRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withServerVariables([
                'REMOTE_ADDR' => '10.42.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.195, 10.42.0.1',
            ])
            ->getJson('/api/v1/auth/sessions')
            ->assertOk();

        $currentSession = collect($sessionRes->json('data'))->firstWhere('is_current', true);
        $this->assertNotNull($currentSession);
        $this->assertEquals('203.0.113.195', $currentSession['ip_address'], 'Real IP should be 203.0.113.195, not internal proxy 10.42.0.1');
    }

    /**
     * Test two devices with the same IP remain separate session records.
     */
    public function test_two_devices_with_same_ip_are_separate_records(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $ip = '198.51.100.99';

        // Device 1: iPhone
        $iphoneUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
        $res1 = $this->withServerVariables([
            'HTTP_USER_AGENT' => $iphoneUa,
            'REMOTE_ADDR' => $ip,
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        // Device 2: Mac Desktop
        $macUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36';
        $res2 = $this->withServerVariables([
            'HTTP_USER_AGENT' => $macUa,
            'REMOTE_ADDR' => $ip,
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $token2 = $res2->json('access_token');

        $sessions = $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $sessions);
        $this->assertNotEquals($sessions[0]['id'], $sessions[1]['id']);
        $this->assertEquals($ip, $sessions[0]['ip_address']);
        $this->assertEquals($ip, $sessions[1]['ip_address']);

        $currentSession = collect($sessions)->firstWhere('is_current', true);
        $otherSession = collect($sessions)->firstWhere('is_current', false);

        $this->assertEquals('Mac Desktop', $currentSession['device_name']);
        $this->assertEquals('iPhone', $otherSession['device_name']);
    }

    /**
     * Test terminating a single session via DELETE /api/v1/auth/sessions/{id}.
     */
    public function test_terminate_one_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $res1 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $res2 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();

        $token1 = $res1->json('access_token');
        $token2 = $res2->json('access_token');
        $jwt = app(JwtService::class);
        $sid1 = $jwt->verify($token1)['sid'];

        // Terminate session 1 from device 2
        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->deleteJson('/api/v1/auth/sessions/' . $sid1)
            ->assertOk();

        // Device 1 should now be unauthorized
        $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        // Device 2 remains active
        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /**
     * Test "Terminate all other sessions" via POST /api/v1/auth/logout-all-devices
     * with { "include_current": false }.
     */
    public function test_terminate_all_other_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $res1 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $res2 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $res3 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();

        $token1 = $res1->json('access_token');
        $token2 = $res2->json('access_token');
        $token3 = $res3->json('access_token');

        // From device 3, terminate all other devices
        $terminateRes = $this->withHeader('Authorization', 'Bearer ' . $token3)
            ->postJson('/api/v1/auth/logout-all-devices', ['include_current' => false])
            ->assertOk();

        $this->assertEquals(2, $terminateRes->json('revoked_count'));

        // Device 1 and 2 are revoked
        $this->withHeader('Authorization', 'Bearer ' . $token1)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer ' . $token2)->getJson('/api/v1/me')->assertUnauthorized();

        // Device 3 remains authenticated
        $this->withHeader('Authorization', 'Bearer ' . $token3)->getJson('/api/v1/me')->assertOk();
    }

    /**
     * Test heartbeat endpoint POST /api/v1/auth/sessions/ping.
     */
    public function test_session_heartbeat_ping(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $loginRes = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $token = $loginRes->json('access_token');

        $pingRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/sessions/ping')
            ->assertOk();

        $pingRes->assertJson([
            'status' => 'success',
            'data' => [
                'is_active' => true,
            ],
        ]);
        $this->assertNotEmpty($pingRes->json('data.last_used_at'));
    }

    /**
     * Test session inactivity timeout settings (GET/PUT /api/v1/auth/sessions/settings).
     */
    public function test_session_inactivity_settings(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $loginRes = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $token = $loginRes->json('access_token');

        // GET default
        $getRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/sessions/settings')
            ->assertOk();

        $this->assertEquals(180, $getRes->json('data.session_inactivity_days'));
        $this->assertEquals([7, 30, 90, 180, 365], $getRes->json('data.available_options'));

        // PUT invalid value rejected
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/sessions/settings', ['session_inactivity_days' => 45])
            ->assertStatus(422);

        // PUT valid value (30 days)
        $putRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/sessions/settings', ['session_inactivity_days' => 30])
            ->assertOk();

        $this->assertEquals(30, $putRes->json('data.session_inactivity_days'));
        $this->assertEquals(30, $user->fresh()->session_inactivity_days);
    }

    /**
     * Test inactive session auto-termination upon authentication & scheduled cleanup.
     */
    public function test_inactive_session_auto_rejected_and_cleaned_up(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'session_inactivity_days' => 7, // 1 week policy
        ]);

        $loginRes = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $token = $loginRes->json('access_token');
        $refreshToken = $loginRes->json('refresh_token');
        $jwt = app(JwtService::class);
        $sid = $jwt->verify($token)['sid'];

        // Artificially age the session to 8 days ago
        DB::table('personal_access_tokens')
            ->where('id', $sid)
            ->update([
                'last_used_at' => Carbon::now()->subDays(8),
                'created_at' => Carbon::now()->subDays(8),
            ]);

        // Accessing /api/v1/me should be rejected due to inactivity timeout
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJson([
                'code' => 'SESSION_EXPIRED',
            ]);

        // Refresh should also be rejected
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertUnauthorized();

        // Recreate expired session record to test CLI cleanup command
        $token2 = $user->createToken('OldDevice');
        DB::table('personal_access_tokens')->where('id', $token2->accessToken->id)->update([
            'last_used_at' => Carbon::now()->subDays(20),
            'created_at' => Carbon::now()->subDays(20),
        ]);

        $this->artisan('sessions:cleanup-inactive')
            ->expectsOutputToContain('Inactivity session cleanup completed!')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token2->accessToken->id]);
    }
}
