<?php

namespace Tests\Unit;

use App\Models\ApiClient;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SessionFamilyTokenTest extends TestCase
{
    use RefreshDatabase;

    protected ApiClient $client;
    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = ApiClient::create([
            'name' => 'Test POS Client',
            'client_key' => 'client_test_key_pos',
            'client_type' => 'public',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();
        $this->tenant = Tenant::create([
            'name' => 'Sunny Cafe Test',
            'slug' => 'sunny-cafe-test-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $this->user->id,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Test ApiClient model auto UUID and scopes.
     */
    public function test_api_client_creation_and_scopes(): void
    {
        $this->assertNotEmpty($this->client->uuid);
        $this->assertEquals(36, strlen($this->client->uuid));

        $inactiveClient = ApiClient::create([
            'name' => 'Inactive Client',
            'client_key' => 'client_inactive',
            'client_type' => 'confidential',
            'is_active' => false,
        ]);

        $activeClients = ApiClient::active()->pluck('client_key')->toArray();
        $this->assertContains('client_test_key_pos', $activeClients);
        $this->assertNotContains('client_inactive', $activeClients);
    }

    /**
     * Test UserSession creation, UUID generation, and relationships.
     */
    public function test_user_session_creation_and_relationships(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'device_uuid' => 'dev-uuid-1234',
            'device_name' => 'iPad POS Terminal',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'CodeBridgesPOS/1.0',
            'permissions' => ['pos.orders.create', 'pos.payments.process'],
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->assertNotEmpty($session->uuid);
        $this->assertEquals(36, strlen($session->uuid));
        $this->assertTrue($session->isActive());
        $this->assertEquals($this->user->id, $session->user->id);
        $this->assertEquals($this->client->id, $session->client->id);
        $this->assertEquals($this->tenant->id, $session->tenant->id);
        $this->assertEquals(['pos.orders.create', 'pos.payments.process'], $session->permissions);
    }

    /**
     * Test RefreshToken credential generation and format compliance.
     */
    public function test_refresh_token_credential_format_and_hashing(): void
    {
        $pair = RefreshToken::generateCredential();

        $this->assertStringStartsWith('rt_', $pair['credential']);
        $this->assertEquals(32, strlen($pair['token_id']));
        $this->assertEquals(64, strlen($pair['token_hash']));
        $this->assertEquals(hash('sha256', $pair['credential']), $pair['token_hash']);

        $parsed = RefreshToken::parseCredential($pair['credential']);
        $this->assertNotNull($parsed);
        $this->assertEquals($pair['token_id'], $parsed['token_id']);
        $this->assertEquals(64, strlen($parsed['secret']));

        // Malformed credentials should fail parsing
        $this->assertNull(RefreshToken::parseCredential('invalid_token'));
        $this->assertNull(RefreshToken::parseCredential('rt_short_secret'));
    }

    /**
     * Test Root Token Issuance for UserSession.
     */
    public function test_root_refresh_token_issuance(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['orders.read'],
            'expires_at' => Carbon::now()->addDays(14),
        ]);

        $issued = RefreshToken::issueRoot($session);

        $token = $issued['token'];
        $credential = $issued['credential'];

        $this->assertInstanceOf(RefreshToken::class, $token);
        $this->assertNull($token->parent_token_id);
        $this->assertEquals($session->id, $token->session_id);
        $this->assertTrue($token->isValid());
        $this->assertEquals(hash('sha256', $credential), $token->token_hash);
        // Expiration capped to session ceiling
        $this->assertTrue($token->expires_at->lte($session->expires_at));
    }

    /**
     * Test normal atomic rotation: consumes parent and issues single successor.
     */
    public function test_normal_refresh_token_rotation(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['pos.cashier'],
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $root = RefreshToken::issueRoot($session);
        $rootToken = $root['token'];
        $rootCred = $root['credential'];

        // Perform rotation
        $rotated = RefreshToken::rotate($rootCred);

        $successor = $rotated['token'];
        $successorCred = $rotated['credential'];

        // Refresh root token from DB
        $rootToken->refresh();
        $this->assertNotNull($rootToken->consumed_at);
        $this->assertFalse($rootToken->isValid());

        // Validate successor
        $this->assertInstanceOf(RefreshToken::class, $successor);
        $this->assertEquals($rootToken->id, $successor->parent_token_id);
        $this->assertEquals($session->id, $successor->session_id);
        $this->assertTrue($successor->isValid());
        $this->assertNull($successor->consumed_at);
        $this->assertEquals(hash('sha256', $successorCred), $successor->token_hash);

        // Successor can rotate again
        $rotatedAgain = RefreshToken::rotate($successorCred);
        $secondSuccessor = $rotatedAgain['token'];

        $successor->refresh();
        $this->assertNotNull($successor->consumed_at);
        $this->assertEquals($successor->id, $secondSuccessor->parent_token_id);
    }

    /**
     * Test REPLAY ATTACK DETECTION:
     * When a previously consumed token is replayed, the entire session family
     * is immediately revoked, preventing token theft / replay hijacking.
     */
    public function test_replay_attack_revokes_entire_session_family(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['pos.cashier'],
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $root = RefreshToken::issueRoot($session);
        $rootCred = $root['credential'];

        // Step 1: Legitimate client rotates root token to successor
        $rotation1 = RefreshToken::rotate($rootCred);
        $successorCred = $rotation1['credential'];

        // Step 2: Attacker intercepts and attempts to replay the already consumed root token
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refresh token was already consumed. Entire session family revoked.');

        try {
            RefreshToken::rotate($rootCred);
        } finally {
            // Verify session family revocation
            $session->refresh();
            $this->assertNotNull($session->revoked_at);
            $this->assertEquals('replay_attack_detected', $session->revocation_reason);
            $this->assertFalse($session->isActive());

            // Successor token should also be revoked as part of the family
            $rotation1['token']->refresh();
            $this->assertNotNull($rotation1['token']->revoked_at);
        }
    }

    /**
     * Test rotation rejection on expired or revoked sessions.
     */
    public function test_rotation_rejected_on_expired_session(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['pos.cashier'],
            'expires_at' => Carbon::now()->subMinute(), // already expired
        ]);

        $pair = RefreshToken::generateCredential();
        $token = RefreshToken::create([
            'session_id' => $session->id,
            'parent_token_id' => null,
            'token_id' => $pair['token_id'],
            'token_hash' => $pair['token_hash'],
            'expires_at' => Carbon::now()->subMinute(),
            'created_at' => Carbon::now()->subHour(),
        ]);

        $this->expectException(RuntimeException::class);
        RefreshToken::rotate($pair['credential']);
    }

    /**
     * Test UserSession::revoke() cascades to all active refresh tokens in the family.
     */
    public function test_user_session_manual_revocation(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['admin.access'],
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        $root = RefreshToken::issueRoot($session);

        $session->revoke('user_logged_out');

        $session->refresh();
        $this->assertNotNull($session->revoked_at);
        $this->assertEquals('user_logged_out', $session->revocation_reason);
        $this->assertFalse($session->isActive());

        $root['token']->refresh();
        $this->assertNotNull($root['token']->revoked_at);
    }

    /**
     * Test unique constraint on parent_token_id prevents two successors from branching.
     */
    public function test_unique_parent_prevents_dual_successors(): void
    {
        $session = UserSession::create([
            'user_id' => $this->user->id,
            'api_client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'permissions' => ['pos.cashier'],
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $root = RefreshToken::issueRoot($session);
        $rootToken = $root['token'];

        // First successor
        $pair1 = RefreshToken::generateCredential();
        RefreshToken::create([
            'session_id' => $session->id,
            'parent_token_id' => $rootToken->id,
            'token_id' => $pair1['token_id'],
            'token_hash' => $pair1['token_hash'],
            'expires_at' => Carbon::now()->addDays(7),
            'created_at' => Carbon::now(),
        ]);

        // Attempt second successor with same parent_token_id
        $this->expectException(\Illuminate\Database\QueryException::class);
        $pair2 = RefreshToken::generateCredential();
        RefreshToken::create([
            'session_id' => $session->id,
            'parent_token_id' => $rootToken->id, // Duplicate parent!
            'token_id' => $pair2['token_id'],
            'token_hash' => $pair2['token_hash'],
            'expires_at' => Carbon::now()->addDays(7),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Test ApiClientSeeder correctly provisions default enterprise clients.
     */
    public function test_api_client_seeder_provisions_default_clients(): void
    {
        $this->seed(\Database\Seeders\ApiClientSeeder::class);

        $webAdmin = ApiClient::where('client_key', 'client_web_admin_cb2026')->first();
        $posFlutter = ApiClient::where('client_key', 'client_pos_flutter_cb2026')->first();
        $mobileRunner = ApiClient::where('client_key', 'client_mobile_runner_cb2026')->first();

        $this->assertNotNull($webAdmin);
        $this->assertEquals('CodeBridges Web Admin', $webAdmin->name);
        $this->assertEquals('first_party', $webAdmin->client_type);
        $this->assertTrue($webAdmin->is_active);

        $this->assertNotNull($posFlutter);
        $this->assertEquals('CodeBridges POS Flutter Terminal', $posFlutter->name);
        $this->assertEquals('public', $posFlutter->client_type);

        $this->assertNotNull($mobileRunner);
        $this->assertEquals('CodeBridges Mobile Runner', $mobileRunner->name);
        $this->assertEquals('public', $mobileRunner->client_type);
    }
}
