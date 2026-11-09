<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrossTenantRoleIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private mixed $originalAuthDbConfig = null;
    private mixed $originalVerifyViaHttp = false;
    private mixed $originalAuthUrl = null;
    private mixed $originalAuthAppKey = null;

    protected int $tenantAId = 101;
    protected int $tenantBId = 202;
    protected int $adminRoleId = 1;
    protected int $cashierRoleId = 2;
    protected int $supervisorRoleId = 3;
    protected int $superAdminRoleId = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAuthDbConfig = config('database.connections.auth_db');
        $this->originalVerifyViaHttp = config('services.auth.verify_via_http', false);
        $this->originalAuthUrl = config('services.auth.url');
        $this->originalAuthAppKey = config('services.auth.app_key');

        Config::set('database.connections.auth_db', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('auth_db');
        DB::reconnect('auth_db');

        $this->createAuthSchema();
        $this->seedTenantsAndRoles();
    }

    protected function tearDown(): void
    {
        Config::set('services.auth.verify_via_http', $this->originalVerifyViaHttp);
        Config::set('services.auth.url', $this->originalAuthUrl);
        Config::set('services.auth.app_key', $this->originalAuthAppKey);
        Config::set('database.connections.auth_db', $this->originalAuthDbConfig);
        DB::purge('auth_db');

        parent::tearDown();
    }

    private function createAuthSchema(): void
    {
        $schema = Schema::connection('auth_db');

        // Exact codebridge_auth ERD schema: NO role, tenant_id, or pin_code columns on users.
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        $schema->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->timestamps();
        });

        $schema->create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $schema->create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('user_pos_pins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('pin_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function seedTenantsAndRoles(): void
    {
        $auth = DB::connection('auth_db');

        $auth->table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'name' => 'Tenant Alpha Cafe',
                'slug' => 'tenant-alpha',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantBId,
                'name' => 'Tenant Beta Bistro',
                'slug' => 'tenant-beta',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $auth->table('roles')->insert([
            [
                'id' => $this->adminRoleId,
                'tenant_id' => null,
                'company_id' => null,
                'name' => 'Admin',
                'slug' => 'admin',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->cashierRoleId,
                'tenant_id' => null,
                'company_id' => null,
                'name' => 'Cashier',
                'slug' => 'cashier',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->supervisorRoleId,
                'tenant_id' => null,
                'company_id' => null,
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->superAdminRoleId,
                'tenant_id' => null,
                'company_id' => null,
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createAuthUser(int $id, string $email, string $name = 'Multi Tenant User', bool $isActive = true): int
    {
        DB::connection('auth_db')->table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('SecretPass123!'),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function addMembership(int $userId, int $tenantId, string $status = 'active'): void
    {
        DB::connection('auth_db')->table('tenant_users')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignRole(int $userId, int $roleId, ?int $tenantId, bool $isActive = true): void
    {
        DB::connection('auth_db')->table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'tenant_id' => $tenantId,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Mint a real HS256 JWT signed with the configured shared secret.
     *
     * @param  array<string, mixed>  $extraClaims
     */
    private function mintJwt(int $userId, ?int $tenantId, string $claimedRole = 'cashier', array $extraClaims = []): string
    {
        $payload = array_merge([
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'sub' => (string) $userId,
            'name' => 'Multi Tenant User',
            'email' => "user{$userId}@example.com",
            'role' => $claimedRole,
            'iat' => time(),
            'exp' => time() + 3600,
            'token_use' => 'access',
            'jti' => 'jwt-' . uniqid('', true),
        ], $extraClaims);

        if ($tenantId !== null && !array_key_exists('tenant_id', $extraClaims)) {
            $payload['tenant_id'] = $tenantId;
        }

        return JWT::encode($payload, (string) config('jwt.secret'), 'HS256');
    }

    public static function roleInsertionOrdersProvider(): array
    {
        return [
            'admin in A inserted before cashier in B' => ['admin_first'],
            'cashier in B inserted before admin in A' => ['cashier_first'],
        ];
    }

    #[DataProvider('roleInsertionOrdersProvider')]
    public function test_admin_in_a_and_cashier_in_b_receives_403_for_admin_product_mutations_in_b(string $insertionOrder): void
    {
        $userId = $this->createAuthUser(10, 'dual.role@example.com');
        $this->addMembership($userId, $this->tenantAId, 'active');
        $this->addMembership($userId, $this->tenantBId, 'active');

        if ($insertionOrder === 'admin_first') {
            $this->assignRole($userId, $this->adminRoleId, $this->tenantAId, true);
            $this->assignRole($userId, $this->cashierRoleId, $this->tenantBId, true);
        } else {
            $this->assignRole($userId, $this->cashierRoleId, $this->tenantBId, true);
            $this->assignRole($userId, $this->adminRoleId, $this->tenantAId, true);
        }

        Product::create([
            'tenant_id' => $this->tenantAId,
            'name' => 'Tenant A Espresso',
            'sku' => 'TA-ESP-001',
            'selling_price' => 3.50,
            'cost_price' => 1.00,
            'is_active' => true,
        ]);

        $tenantBProduct = Product::create([
            'tenant_id' => $this->tenantBId,
            'name' => 'Tenant B Original Product',
            'sku' => 'TB-ORIG-001',
            'selling_price' => 25.00,
            'cost_price' => 10.00,
            'is_active' => true,
        ]);

        $initialProductCount = DB::table('products')->count();

        // Issue real signed JWT scoped to Tenant B — even claiming stale 'role' => 'admin' in JWT claims.
        $tenantBToken = $this->mintJwt($userId, $this->tenantBId, 'admin');

        $createResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'Unauthorized Tenant B Product',
            'sku' => 'TB-HACK-001',
            'selling_price' => 15.00,
        ]);
        $createResp->assertStatus(403);

        $bulkResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->postJson('/api/v1/products/bulk', [
            'items' => [
                ['name' => 'Bulk Hack', 'sku' => 'TB-BULK-001', 'selling_price' => 9.00],
            ],
        ]);
        $bulkResp->assertStatus(403);

        $updatePutResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->putJson("/api/v1/products/{$tenantBProduct->id}", [
            'name' => 'Tampered Product B',
            'selling_price' => 1.00,
        ]);
        $updatePutResp->assertStatus(403);

        $updatePostResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->postJson("/api/v1/products/{$tenantBProduct->id}", [
            'name' => 'Tampered Product B POST',
        ]);
        $updatePostResp->assertStatus(403);

        $imageResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->postJson("/api/v1/products/{$tenantBProduct->id}/image", [
            'image_url' => 'https://example.com/tampered.png',
        ]);
        $imageResp->assertStatus(403);

        $deleteResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->deleteJson("/api/v1/products/{$tenantBProduct->id}");
        $deleteResp->assertStatus(403);

        $categoryResp = $this->withHeaders([
            'Authorization' => "Bearer {$tenantBToken}",
        ])->postJson('/api/v1/categories', [
            'name' => 'Blocked Category',
        ]);
        $categoryResp->assertStatus(403);

        // Verify zero product rows were added, modified, or deactivated
        $this->assertSame($initialProductCount, DB::table('products')->count());
        $this->assertDatabaseHas('products', [
            'id' => $tenantBProduct->id,
            'tenant_id' => $this->tenantBId,
            'name' => 'Tenant B Original Product',
            'selling_price' => 25.00,
            'is_active' => 1,
        ]);

        // Same user with real Tenant A JWT CAN create a product inside Tenant A, but STILL cannot mutate Tenant B
        $tenantAToken = $this->mintJwt($userId, $this->tenantAId, 'admin');

        $allowedCreate = $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'Tenant A Matcha',
            'sku' => 'TA-MAT-002',
            'selling_price' => 4.25,
        ]);
        $allowedCreate->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenantAId,
            'sku' => 'TA-MAT-002',
        ]);

        // Tenant A admin token attempting to mutate Tenant B product still receives 403
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
        ])->putJson("/api/v1/products/{$tenantBProduct->id}", [
            'name' => 'Cross Tenant Edit',
        ])->assertStatus(403);

        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
        ])->deleteJson("/api/v1/products/{$tenantBProduct->id}")->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id' => $tenantBProduct->id,
            'name' => 'Tenant B Original Product',
            'selling_price' => 25.00,
            'is_active' => 1,
        ]);
    }

    public function test_tenant_spoofing_conflicts_and_missing_tenant_context_are_blocked(): void
    {
        $userId = $this->createAuthUser(11, 'spoof.guard@example.com');
        $this->addMembership($userId, $this->tenantAId, 'active');
        $this->addMembership($userId, $this->tenantBId, 'active');
        $this->assignRole($userId, $this->adminRoleId, $this->tenantAId, true);
        $this->assignRole($userId, $this->adminRoleId, $this->tenantBId, true);

        $tenantAToken = $this->mintJwt($userId, $this->tenantAId, 'admin');
        $tenantlessToken = $this->mintJwt($userId, null, 'admin');

        // Header mismatch vs JWT tenant claim
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => (string) $this->tenantBId,
        ])->postJson('/api/v1/products', [
            'name' => 'Header Override',
            'sku' => 'HDR-OVR-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // Query mismatch vs JWT tenant claim
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
        ])->postJson("/api/v1/products?tenant_id={$this->tenantBId}", [
            'name' => 'Query Override',
            'sku' => 'QRY-OVR-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // Header vs Query conflict
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => (string) $this->tenantAId,
        ])->postJson("/api/v1/products?tenant_id={$this->tenantBId}", [
            'name' => 'Conflict Override',
            'sku' => 'CFL-OVR-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // Non-platform admin passing wildcard all
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => 'all',
        ])->postJson('/api/v1/products', [
            'name' => 'All Wildcard',
            'sku' => 'ALL-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // Malformed tenant identifier
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => '../etc/passwd',
        ])->postJson('/api/v1/products', [
            'name' => 'Path Traversal',
            'sku' => 'BAD-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // Non-platform JWT without tenant_id or header
        $this->withHeaders([
            'Authorization' => "Bearer {$tenantlessToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'No Tenant',
            'sku' => 'NONE-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_suspended_membership_inactive_tenant_or_inactive_role_blocks_mutations(): void
    {
        // Case 1: Suspended membership in Tenant B (even though active admin in Tenant A and role row in B is admin)
        $suspendedMemberId = $this->createAuthUser(12, 'suspended.member@example.com');
        $this->addMembership($suspendedMemberId, $this->tenantAId, 'active');
        $this->addMembership($suspendedMemberId, $this->tenantBId, 'suspended');
        $this->assignRole($suspendedMemberId, $this->adminRoleId, $this->tenantAId, true);
        $this->assignRole($suspendedMemberId, $this->adminRoleId, $this->tenantBId, true);

        $suspendedMemberToken = $this->mintJwt($suspendedMemberId, $this->tenantBId, 'admin');
        $this->withHeaders([
            'Authorization' => "Bearer {$suspendedMemberToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'Suspended Member Product',
            'sku' => 'SUSP-M-1',
            'selling_price' => 10.00,
        ])->assertStatus(403);

        // Case 2: Deactivated role row in Tenant B while active admin in Tenant A
        $inactiveRoleUserId = $this->createAuthUser(13, 'inactive.role@example.com');
        $this->addMembership($inactiveRoleUserId, $this->tenantAId, 'active');
        $this->addMembership($inactiveRoleUserId, $this->tenantBId, 'active');
        $this->assignRole($inactiveRoleUserId, $this->adminRoleId, $this->tenantAId, true);
        $this->assignRole($inactiveRoleUserId, $this->adminRoleId, $this->tenantBId, false);

        $inactiveRoleToken = $this->mintJwt($inactiveRoleUserId, $this->tenantBId, 'admin');
        $this->withHeaders([
            'Authorization' => "Bearer {$inactiveRoleToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'Inactive Role Product',
            'sku' => 'INACT-R-1',
            'selling_price' => 10.00,
        ])->assertStatus(403);

        // Case 3: Suspended tenant
        DB::connection('auth_db')->table('tenants')->where('id', $this->tenantBId)->update(['status' => 'suspended']);
        $suspendedTenantUserId = $this->createAuthUser(14, 'suspended.tenant@example.com');
        $this->addMembership($suspendedTenantUserId, $this->tenantBId, 'active');
        $this->assignRole($suspendedTenantUserId, $this->adminRoleId, $this->tenantBId, true);

        $suspendedTenantToken = $this->mintJwt($suspendedTenantUserId, $this->tenantBId, 'admin');
        $this->withHeaders([
            'Authorization' => "Bearer {$suspendedTenantToken}",
        ])->postJson('/api/v1/products', [
            'name' => 'Suspended Tenant Product',
            'sku' => 'SUSP-T-1',
            'selling_price' => 10.00,
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_platform_super_admin_is_explicitly_distinguished_from_tenant_scoped_super_admin(): void
    {
        $tenantBProduct = Product::create([
            'tenant_id' => $this->tenantBId,
            'name' => 'Tenant B Item',
            'sku' => 'TB-SA-001',
            'selling_price' => 12.00,
            'is_active' => true,
        ]);

        // 1. User holding super_admin ONLY inside Tenant A (user_roles.tenant_id = $tenantAId), and cashier in Tenant B
        $tenantScopedSuperId = $this->createAuthUser(15, 'scoped.super@example.com');
        $this->addMembership($tenantScopedSuperId, $this->tenantAId, 'active');
        $this->addMembership($tenantScopedSuperId, $this->tenantBId, 'active');
        $this->assignRole($tenantScopedSuperId, $this->superAdminRoleId, $this->tenantAId, true);
        $this->assignRole($tenantScopedSuperId, $this->cashierRoleId, $this->tenantBId, true);

        $scopedTokenA = $this->mintJwt($tenantScopedSuperId, $this->tenantAId, 'super_admin');
        $scopedTokenB = $this->mintJwt($tenantScopedSuperId, $this->tenantBId, 'super_admin');

        // Can create in Tenant A
        $this->withHeaders([
            'Authorization' => "Bearer {$scopedTokenA}",
        ])->postJson('/api/v1/products', [
            'name' => 'Tenant A Super Product',
            'sku' => 'TA-SA-001',
            'selling_price' => 19.00,
        ])->assertStatus(201);

        // Tenant-scoped super_admin in A CANNOT cross into Tenant B or query all
        $this->withHeaders([
            'Authorization' => "Bearer {$scopedTokenA}",
        ])->putJson("/api/v1/products/{$tenantBProduct->id}", [
            'name' => 'Hacked By Scoped Super',
        ])->assertStatus(403);

        $this->withHeaders([
            'Authorization' => "Bearer {$scopedTokenA}",
            'X-Tenant-Id' => 'all',
        ])->getJson('/api/v1/products')->assertStatus(403);

        // In Tenant B, they are only a cashier
        $this->withHeaders([
            'Authorization' => "Bearer {$scopedTokenB}",
        ])->postJson('/api/v1/products', [
            'name' => 'Blocked Tenant B Create',
            'sku' => 'TB-BLK-1',
            'selling_price' => 5.00,
        ])->assertStatus(403);

        // 2. Explicit platform-wide super_admin (user_roles.tenant_id IS NULL & roles.tenant_id IS NULL)
        $platformSuperId = $this->createAuthUser(16, 'platform.super@example.com');
        $this->assignRole($platformSuperId, $this->superAdminRoleId, null, true);

        $platformToken = $this->mintJwt($platformSuperId, null, 'super_admin');

        $this->withHeaders([
            'Authorization' => "Bearer {$platformToken}",
            'X-Tenant-Id' => (string) $this->tenantBId,
        ])->postJson('/api/v1/products', [
            'name' => 'Platform Managed Tenant B Product',
            'sku' => 'PB-SA-002',
            'selling_price' => 22.00,
        ])->assertStatus(201);

        $this->withHeaders([
            'Authorization' => "Bearer {$platformToken}",
        ])->putJson("/api/v1/products/{$tenantBProduct->id}", [
            'name' => 'Updated By Platform Super',
        ])->assertOk();

        $allResp = $this->withHeaders([
            'Authorization' => "Bearer {$platformToken}",
            'X-Tenant-Id' => 'all',
        ])->getJson('/api/v1/products');
        $allResp->assertOk();
        $this->assertGreaterThanOrEqual(3, count($allResp->json('data')));
    }

    public function test_auth_http_authorize_context_contract_enforcement_and_fail_closed_behavior(): void
    {
        Config::set('services.auth.verify_via_http', true);
        Config::set('services.auth.url', 'https://auth.internal.test/api/v1');
        Config::set('services.auth.app_key', 'ops_internal_app_key');

        $userId = 77;
        $tokenB = $this->mintJwt($userId, $this->tenantBId, 'admin');

        // 1. Auth contract reports role=cashier for Tenant B -> 403 even if JWT had stale admin claim
        Http::fake([
            'https://auth.internal.test/api/v1/auth/authorize-context' => Http::sequence()
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => $userId,
                        'tenant_id' => $this->tenantBId,
                        'tenant_status' => 'active',
                        'membership_status' => 'active',
                        'role' => 'cashier',
                        'roles' => ['cashier'],
                        'is_super_admin' => false,
                        'is_platform_super_admin' => false,
                        'permissions' => ['sales.create'],
                    ],
                ], 200)
                ->push([
                    'authorized' => false,
                    'code' => 'TENANT_CONTEXT_MISMATCH',
                    'message' => 'Tenant context mismatch.',
                ], 403)
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => $userId,
                        'tenant_id' => $this->tenantAId,
                        'tenant_status' => 'active',
                        'membership_status' => 'active',
                        'role' => 'admin',
                        'roles' => ['admin'],
                        'is_super_admin' => false,
                        'is_platform_super_admin' => false,
                        'permissions' => ['*'],
                    ],
                ], 200)
                ->push(['message' => 'Internal server error'], 500),
        ]);

        // Call 1: cashier in B -> 403
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-App-Key' => 'client_web_admin_cb2026',
            'X-Tenant-Id' => (string) $this->tenantBId,
        ])->postJson('/api/v1/products', [
            'name' => 'Blocked By HTTP Contract',
            'sku' => 'HTTP-BLK-1',
            'selling_price' => 11.00,
        ])->assertStatus(403);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://auth.internal.test/api/v1/auth/authorize-context'
                && $request->hasHeader('Authorization')
                && $request->header('X-App-Key')[0] === 'client_web_admin_cb2026'
                && $request->header('X-Tenant-Id')[0] === (string) $this->tenantBId;
        });

        // Call 2: Auth rejects tenant mismatch -> 403
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-Id' => (string) $this->tenantAId,
        ])->postJson('/api/v1/products', [
            'name' => 'Mismatch HTTP Contract',
            'sku' => 'HTTP-MIS-1',
            'selling_price' => 11.00,
        ])->assertStatus(403);

        // Call 3: Tenant A JWT with Auth contract admin -> 201
        $tokenA = $this->mintJwt($userId, $this->tenantAId, 'admin');
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
        ])->postJson('/api/v1/products', [
            'name' => 'Allowed By HTTP Contract',
            'sku' => 'HTTP-OK-1',
            'selling_price' => 11.00,
        ])->assertStatus(201);

        // Call 4: Auth outage (500) -> fails closed with 401 and zero mutations
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
        ])->postJson('/api/v1/products', [
            'name' => 'Blocked On Outage',
            'sku' => 'HTTP-ERR-1',
            'selling_price' => 11.00,
        ])->assertStatus(401);

        $this->assertDatabaseHas('products', ['sku' => 'HTTP-OK-1']);
        $this->assertDatabaseMissing('products', ['sku' => 'HTTP-BLK-1']);
        $this->assertDatabaseMissing('products', ['sku' => 'HTTP-ERR-1']);
    }

    public function test_user_model_role_accessor_scopes_strictly_by_tenant_regardless_of_insertion_order(): void
    {
        $userAdminFirst = $this->createAuthUser(21, 'model.a.first@example.com');
        $this->addMembership($userAdminFirst, $this->tenantAId, 'active');
        $this->addMembership($userAdminFirst, $this->tenantBId, 'active');
        $this->assignRole($userAdminFirst, $this->adminRoleId, $this->tenantAId, true);
        $this->assignRole($userAdminFirst, $this->cashierRoleId, $this->tenantBId, true);

        $userCashierFirst = $this->createAuthUser(22, 'model.b.first@example.com');
        $this->addMembership($userCashierFirst, $this->tenantAId, 'active');
        $this->addMembership($userCashierFirst, $this->tenantBId, 'active');
        $this->assignRole($userCashierFirst, $this->cashierRoleId, $this->tenantBId, true);
        $this->assignRole($userCashierFirst, $this->adminRoleId, $this->tenantAId, true);

        foreach ([$userAdminFirst, $userCashierFirst] as $uid) {
            $modelForB = User::findOrFail($uid);
            $modelForB->pendingTenantId = (string) $this->tenantBId;
            $this->assertSame('cashier', $modelForB->role);
            $this->assertSame(['cashier'], $modelForB->getScopedRoles((string) $this->tenantBId));
            $this->assertFalse($modelForB->isPlatformSuperAdmin());

            $modelForA = User::findOrFail($uid);
            $modelForA->pendingTenantId = (string) $this->tenantAId;
            $this->assertSame('admin', $modelForA->role);
            $this->assertSame(['admin'], $modelForA->getScopedRoles((string) $this->tenantAId));
        }
    }

    public function test_cross_tenant_supervisor_pin_cannot_authorize_shift_variance_in_another_tenant(): void
    {
        $cashierBId = $this->createAuthUser(31, 'cashier.b@example.com');
        $this->addMembership($cashierBId, $this->tenantBId, 'active');
        $this->assignRole($cashierBId, $this->cashierRoleId, $this->tenantBId, true);

        $supervisorAId = $this->createAuthUser(32, 'supervisor.a@example.com');
        $this->addMembership($supervisorAId, $this->tenantAId, 'active');
        $this->assignRole($supervisorAId, $this->supervisorRoleId, $this->tenantAId, true);

        $supervisorBId = $this->createAuthUser(33, 'supervisor.b@example.com');
        $this->addMembership($supervisorBId, $this->tenantBId, 'active');
        $this->assignRole($supervisorBId, $this->supervisorRoleId, $this->tenantBId, true);

        DB::connection('auth_db')->table('user_pos_pins')->insert([
            [
                'user_id' => $supervisorAId,
                'tenant_id' => $this->tenantAId,
                'pin_hash' => hash('sha256', '9999'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $supervisorBId,
                'tenant_id' => $this->tenantBId,
                'pin_hash' => hash('sha256', '8888'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $cashierTokenB = $this->mintJwt($cashierBId, $this->tenantBId, 'cashier');

        $this->withHeaders([
            'Authorization' => "Bearer {$cashierTokenB}",
        ])->postJson('/api/v1/shifts/open', [
            'opening_float' => 100.00,
        ])->assertStatus(201);

        // Tenant A supervisor PIN ('9999') MUST NOT authorize shift close in Tenant B
        $this->withHeaders([
            'Authorization' => "Bearer {$cashierTokenB}",
        ])->postJson('/api/v1/shifts/close', [
            'counted_cash' => 80.00,
            'supervisor_pin' => '9999',
        ])->assertStatus(403);

        // Tenant B supervisor PIN ('8888') succeeds
        $this->withHeaders([
            'Authorization' => "Bearer {$cashierTokenB}",
        ])->postJson('/api/v1/shifts/close', [
            'counted_cash' => 80.00,
            'supervisor_pin' => '8888',
        ])->assertOk();
    }
}

