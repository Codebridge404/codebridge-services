<?php

namespace App\Services;

use App\Models\User;
use App\Support\SchemaGuard;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Centralized Auth-contract consumer and tenant-scoped RBAC resolver for Operations Service.
 *
 * Ensures every permission and role lookup is explicitly bound to a verified tenant context
 * and active membership, handles platform-wide super_admin explicitly, and delegates to Auth's
 * GET /api/v1/auth/authorize-context contract when configured or required.
 */
class TenantAuthorizationService
{
    public const ADMIN_ROLE_SLUGS = [
        'organization',
        'organization_owner',
        'owner',
        'admin',
        'administrator',
        'tenant_admin',
    ];

    public const ADMIN_ALIAS_EQUIVALENTS = [
        'organization',
        'organization_owner',
        'owner',
        'admin',
        'administrator',
    ];

    public const ROLE_PRIORITY = [
        'super_admin' => 1,
        'organization' => 2,
        'organization_owner' => 3,
        'owner' => 4,
        'admin' => 5,
        'administrator' => 6,
        'tenant_admin' => 7,
        'outlet_manager' => 8,
        'supervisor' => 9,
        'store_manager' => 10,
        'accountant' => 11,
        'inventory_clerk' => 12,
        'cashier' => 13,
        'meeting_organizer' => 14,
        'employee' => 15,
        'user' => 16,
    ];

    /**
     * Resolve the database connection that holds Auth tables (users, tenants, tenant_users, roles, user_roles).
     */
    public static function authConnectionName(): string
    {
        $defaultConn = (string) config('database.default', 'mysql');
        $authConn = config('database.connections.auth_db');

        if ($defaultConn === 'sqlite') {
            if (is_array($authConn) && ($authConn['driver'] ?? null) === 'sqlite') {
                return 'auth_db';
            }

            return $defaultConn;
        }

        return is_array($authConn) ? 'auth_db' : $defaultConn;
    }

    /**
     * Determine whether RBAC pivot tables exist on the resolved auth connection.
     */
    public static function hasRbacTables(?string $conn = null): bool
    {
        $connection = $conn ?? self::authConnectionName();

        try {
            return SchemaGuard::hasTable('user_roles', $connection)
                && SchemaGuard::hasTable('roles', $connection);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Determine whether tenant membership tables exist on the resolved auth connection.
     */
    public static function hasMembershipTables(?string $conn = null): bool
    {
        $connection = $conn ?? self::authConnectionName();

        try {
            return SchemaGuard::hasTable('tenant_users', $connection)
                && SchemaGuard::hasTable('tenants', $connection);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Deterministically sort role slugs so authorization never depends on DB row insertion order.
     *
     * @param  array<int, mixed>  $slugs
     * @return list<string>
     */
    public static function sortRoleSlugs(array $slugs): array
    {
        $normalized = [];
        foreach ($slugs as $slug) {
            if (is_string($slug)) {
                $trimmed = trim($slug);
                if ($trimmed !== '') {
                    $normalized[] = $trimmed;
                }
            }
        }

        $unique = array_values(array_unique($normalized));
        usort($unique, static function (string $a, string $b): int {
            $pa = self::ROLE_PRIORITY[$a] ?? 100;
            $pb = self::ROLE_PRIORITY[$b] ?? 100;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp($a, $b);
        });

        return $unique;
    }

    /**
     * Normalize a tenant identifier to a canonical non-empty string, rejecting invalid values or wildcard strings.
     */
    public static function normalizeTenantId(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === '0' || strcasecmp($trimmed, 'all') === 0 || strcasecmp($trimmed, 'null') === 0) {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Explicitly parse X-Tenant-Id header / tenant_id query parameter.
     *
     * @return array{raw: ?string, scope: ?string, invalid: bool, conflict: bool}
     */
    public static function parseRequestedTenant(Request $request): array
    {
        $headerVal = $request->header('X-Tenant-Id');
        $queryVal = $request->query('tenant_id');

        if ($queryVal !== null && !is_scalar($queryVal)) {
            return ['raw' => null, 'scope' => null, 'invalid' => true, 'conflict' => false];
        }

        $headerTrimmed = is_string($headerVal) ? trim($headerVal) : null;
        $headerTrimmed = ($headerTrimmed === '') ? null : $headerTrimmed;

        $queryTrimmed = $queryVal !== null ? trim((string) $queryVal) : null;
        $queryTrimmed = ($queryTrimmed === '') ? null : $queryTrimmed;

        if ($headerTrimmed !== null && $queryTrimmed !== null && $headerTrimmed !== $queryTrimmed) {
            return ['raw' => $headerTrimmed, 'scope' => null, 'invalid' => false, 'conflict' => true];
        }

        $raw = $headerTrimmed ?? $queryTrimmed;
        if ($raw === null) {
            return ['raw' => null, 'scope' => null, 'invalid' => false, 'conflict' => false];
        }

        if ($raw === 'all') {
            return ['raw' => 'all', 'scope' => 'all', 'invalid' => false, 'conflict' => false];
        }

        $normalized = self::normalizeTenantId($raw);
        if ($normalized === null) {
            return ['raw' => $raw, 'scope' => null, 'invalid' => true, 'conflict' => false];
        }

        return ['raw' => $raw, 'scope' => $normalized, 'invalid' => false, 'conflict' => false];
    }

    /**
     * Explicitly check whether user holds the platform-wide super_admin role (user_roles.tenant_id IS NULL && roles.tenant_id IS NULL).
     */
    public static function hasPlatformSuperAdminRole($user, ?string $conn = null): bool
    {
        if (!$user || !is_object($user)) {
            return false;
        }

        if (isset($user->is_active) && !(bool) $user->is_active) {
            return false;
        }

        if (isset($user->isPlatformSuperAdmin) && $user->isPlatformSuperAdmin === true) {
            return true;
        }

        $connection = $conn ?? self::authConnectionName();

        if (self::hasRbacTables($connection)) {
            if (empty($user->id)) {
                return false;
            }

            try {
                $query = DB::connection($connection)
                    ->table('user_roles as ur')
                    ->join('roles as r', 'ur.role_id', '=', 'r.id')
                    ->where('ur.user_id', $user->id)
                    ->whereNull('ur.tenant_id')
                    ->where('r.slug', 'super_admin');

                if (SchemaGuard::hasColumn('user_roles', 'is_active', $connection)) {
                    $query->where('ur.is_active', true);
                }
                if (SchemaGuard::hasColumn('roles', 'tenant_id', $connection)) {
                    $query->whereNull('r.tenant_id');
                }
                if (SchemaGuard::hasColumn('roles', 'company_id', $connection)) {
                    $query->whereNull('r.company_id');
                }
                if (SchemaGuard::hasColumn('roles', 'is_active', $connection)) {
                    $query->where(function ($q) {
                        $q->where('r.is_active', true)->orWhereNull('r.is_active');
                    });
                }

                return $query->exists();
            } catch (\Throwable) {
                return false;
            }
        }

        if (config('services.auth.verify_via_http', false)) {
            return !empty($user->isPlatformSuperAdmin);
        }

        // Fallback strictly for schema-less SQLite test environments without RBAC tables
        if (config('database.default') !== 'sqlite') {
            return false;
        }

        $rawAttributes = method_exists($user, 'getAttributes') ? $user->getAttributes() : [];
        $rawRole = $rawAttributes['role'] ?? ($user->pendingRole ?? null);
        $rawTenant = self::normalizeTenantId($user->pendingTenantId ?? ($rawAttributes['tenant_id'] ?? null));

        return $rawRole === 'super_admin' && $rawTenant === null;
    }

    /**
     * Check whether the user holds super_admin (either platform-wide OR scoped strictly within $tenantId).
     */
    public static function isSuperAdmin($user, ?string $tenantId = null, ?string $conn = null): bool
    {
        if (self::hasPlatformSuperAdminRole($user, $conn)) {
            return true;
        }

        if (!$user || !is_object($user)) {
            return false;
        }

        if (isset($user->is_active) && !(bool) $user->is_active) {
            return false;
        }

        $rawAttributes = method_exists($user, 'getAttributes') ? $user->getAttributes() : [];
        $effectiveTenant = self::normalizeTenantId($tenantId ?? ($user->pendingTenantId ?? ($rawAttributes['tenant_id'] ?? null)));
        $connection = $conn ?? self::authConnectionName();

        if ($effectiveTenant !== null && !empty($user->id) && self::hasRbacTables($connection)) {
            try {
                $query = DB::connection($connection)
                    ->table('user_roles as ur')
                    ->join('roles as r', 'ur.role_id', '=', 'r.id')
                    ->where('ur.user_id', $user->id)
                    ->where('ur.tenant_id', $effectiveTenant)
                    ->where('r.slug', 'super_admin');

                if (SchemaGuard::hasColumn('user_roles', 'is_active', $connection)) {
                    $query->where('ur.is_active', true);
                }
                if (SchemaGuard::hasColumn('roles', 'tenant_id', $connection)) {
                    $query->whereNull('r.tenant_id');
                }
                if (SchemaGuard::hasColumn('roles', 'is_active', $connection)) {
                    $query->where(function ($q) {
                        $q->where('r.is_active', true)->orWhereNull('r.is_active');
                    });
                }

                return $query->exists();
            } catch (\Throwable) {
                return false;
            }
        }

        if (isset($user->resolvedSuperAdmin) && $user->resolvedSuperAdmin === true) {
            return true;
        }

        if (!self::hasRbacTables($connection) && config('database.default') === 'sqlite') {
            $rawRole = $rawAttributes['role'] ?? ($user->pendingRole ?? null);
            return $rawRole === 'super_admin';
        }

        return false;
    }

    /**
     * Fetch user's active role slugs strictly scoped to the verified tenant (plus platform-wide super_admin if applicable).
     * Never performs an unscoped query across user_roles.
     *
     * @return list<string>
     */
    public static function getScopedRolesForUser($user, ?string $tenantId = null, ?string $conn = null): array
    {
        if (!$user || !is_object($user) || empty($user->id)) {
            return [];
        }

        $connection = $conn ?? self::authConnectionName();
        $roles = [];

        if (self::hasPlatformSuperAdminRole($user, $connection)) {
            $roles[] = 'super_admin';
        }

        $normalizedTenant = self::normalizeTenantId($tenantId);
        if ($normalizedTenant === null || !self::hasRbacTables($connection)) {
            return self::sortRoleSlugs($roles);
        }

        $query = DB::connection($connection)
            ->table('user_roles as ur')
            ->join('roles as r', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $user->id)
            ->where('ur.tenant_id', $normalizedTenant);

        if (SchemaGuard::hasColumn('user_roles', 'is_active', $connection)) {
            $query->where('ur.is_active', true);
        }

        if (SchemaGuard::hasColumn('roles', 'is_active', $connection)) {
            $query->where(function ($q) {
                $q->where('r.is_active', true)->orWhereNull('r.is_active');
            });
        }

        $hasRoleTenant = SchemaGuard::hasColumn('roles', 'tenant_id', $connection);
        $hasRoleCompany = SchemaGuard::hasColumn('roles', 'company_id', $connection);

        if ($hasRoleTenant || $hasRoleCompany) {
            $query->where(function ($q) use ($normalizedTenant, $hasRoleTenant, $hasRoleCompany) {
                if ($hasRoleTenant && $hasRoleCompany) {
                    $q->where(function ($sysQ) {
                        $sysQ->whereNull('r.tenant_id')->whereNull('r.company_id');
                    })->orWhere('r.tenant_id', $normalizedTenant)
                      ->orWhere('r.company_id', $normalizedTenant);
                } elseif ($hasRoleTenant) {
                    $q->whereNull('r.tenant_id')->orWhere('r.tenant_id', $normalizedTenant);
                } else {
                    $q->whereNull('r.company_id')->orWhere('r.company_id', $normalizedTenant);
                }
            });
        }

        $scopedSlugs = $query->pluck('r.slug')->all();

        return self::sortRoleSlugs(array_merge($roles, $scopedSlugs));
    }

    /**
     * Resolve and verify the caller's tenant-scoped authorization context through one Auth-owned contract.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     * @return array<string, mixed>
     */
    public static function resolveAuthorizationContext(
        Request $request,
        $user = null,
        ?array $jwtPayload = null,
        bool $requireTenant = true
    ): array {
        $parsedRequest = self::parseRequestedTenant($request);
        if ($parsedRequest['conflict']) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_MISMATCH',
                'message' => 'Conflicting tenant context supplied between header and query parameter.',
            ];
        }
        if ($parsedRequest['invalid']) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'INVALID_TENANT_CONTEXT',
                'message' => 'Invalid tenant identifier supplied.',
            ];
        }

        // Reuse verified Auth context if already attached to the request and satisfies requireTenant constraint
        $cached = $request->attributes->get('auth_context');
        if (is_array($cached) && !empty($cached['authorized'])) {
            $cachedUserId = (string) ($cached['user_id'] ?? '');
            $currentUserId = $user && !empty($user->id) ? (string) $user->id : ($jwtPayload['sub'] ?? '');
            if ($cachedUserId === $currentUserId || $currentUserId === '') {
                $cachedScope = $cached['requested_tenant_scope'] ?? null;
                $cachedTenant = $cached['tenant_id'] ?? null;
                $isPlatformCached = !empty($cached['is_platform_super_admin']);
                if (!$requireTenant || $cachedTenant !== null || $cachedScope === 'all' || $isPlatformCached) {
                    return $cached;
                }

                if (($cached['source'] ?? null) !== 'sqlite_stateless_test') {
                    return [
                        'authorized' => false,
                        'status' => 403,
                        'code' => 'TENANT_CONTEXT_REQUIRED',
                        'message' => 'An active workspace membership is required.',
                    ];
                }
            }
        }

        $connection = self::authConnectionName();
        $hasRbac = self::hasRbacTables($connection);
        $hasMemberships = self::hasMembershipTables($connection);
        $token = $request->bearerToken();
        $verifyViaHttp = (bool) config('services.auth.verify_via_http', false);

        // Path A: Remote Auth-owned GET /api/v1/auth/authorize-context contract
        if ($verifyViaHttp || (config('database.default') !== 'sqlite' && (!$hasRbac || !$hasMemberships))) {
            if (!empty($token)) {
                $contract = app(AuthServiceClient::class)->authorizeContext(
                    $token,
                    $parsedRequest['raw'],
                    $request->header('X-App-Key')
                );

                if ($contract === null) {
                    return [
                        'authorized' => false,
                        'status' => 401,
                        'code' => 'AUTH_SERVICE_UNAVAILABLE',
                        'message' => 'Unable to verify tenant authorization context with Auth service.',
                    ];
                }

                if (empty($contract['authorized'])) {
                    return $contract;
                }

                $data = is_array($contract['data'] ?? null) ? $contract['data'] : [];
                $contractUserId = (int) ($data['user_id'] ?? 0);
                if ($jwtPayload !== null && !empty($jwtPayload['sub']) && $contractUserId > 0 && (int) $jwtPayload['sub'] !== $contractUserId) {
                    return [
                        'authorized' => false,
                        'status' => 401,
                        'code' => 'TOKEN_IDENTITY_MISMATCH',
                        'message' => 'Token subject does not match Auth authorization contract.',
                    ];
                }

                $tenantId = self::normalizeTenantId($data['tenant_id'] ?? null);
                $scope = ($data['requested_tenant_scope'] ?? null) === 'all' ? 'all' : null;
                $roles = self::sortRoleSlugs(is_array($data['roles'] ?? null) ? $data['roles'] : [($data['role'] ?? 'user')]);
                if (empty($roles)) {
                    $roles = ['user'];
                }

                $isPlatform = (bool) ($data['is_platform_super_admin'] ?? false);
                if ($requireTenant && $tenantId === null && $scope !== 'all' && !$isPlatform) {
                    return [
                        'authorized' => false,
                        'status' => 403,
                        'code' => 'TENANT_CONTEXT_REQUIRED',
                        'message' => 'An active workspace membership is required.',
                    ];
                }

                return [
                    'authorized' => true,
                    'status' => 200,
                    'code' => 'OK',
                    'message' => 'Authorized.',
                    'user_id' => $contractUserId ?: (int) ($user?->id ?? $jwtPayload['sub'] ?? 0),
                    'tenant_id' => $tenantId,
                    'tenant_status' => $data['tenant_status'] ?? null,
                    'requested_tenant_scope' => $scope,
                    'membership_status' => $data['membership_status'] ?? null,
                    'outlet_id' => isset($data['outlet_id']) ? (int) $data['outlet_id'] : null,
                    'outlet_uuid' => $data['outlet_uuid'] ?? null,
                    'role' => $data['role'] ?? $roles[0],
                    'roles' => $roles,
                    'is_super_admin' => (bool) ($data['is_super_admin'] ?? false),
                    'is_platform_super_admin' => (bool) ($data['is_platform_super_admin'] ?? false),
                    'permissions' => is_array($data['permissions'] ?? null) ? $data['permissions'] : [],
                    'source' => 'auth_http_contract',
                ];
            }

            if (config('database.default') !== 'sqlite' && (!$hasRbac || !$hasMemberships)) {
                return [
                    'authorized' => false,
                    'status' => 401,
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication token required to verify tenant authorization.',
                ];
            }
        }

        // Validate JWT tenant claim if payload was provided
        $claimTenantId = null;
        if ($jwtPayload !== null && array_key_exists('tenant_id', $jwtPayload) && $jwtPayload['tenant_id'] !== null && $jwtPayload['tenant_id'] !== '') {
            $claimTenantId = self::normalizeTenantId($jwtPayload['tenant_id']);
            if ($claimTenantId === null) {
                return [
                    'authorized' => false,
                    'status' => 403,
                    'code' => 'INVALID_TENANT_CONTEXT',
                    'message' => 'Token contains an invalid tenant identifier.',
                ];
            }
        }

        // Path B: Authoritative Auth DB contract enforcement (MySQL auth_db or full RBAC SQLite schema)
        if ($hasRbac || $hasMemberships) {
            return self::resolveFromAuthDatabase($user, $jwtPayload, $parsedRequest, $claimTenantId, $requireTenant, $connection, $hasRbac, $hasMemberships);
        }

        // Path C: Schema-less SQLite test harness ONLY (never permitted on production MySQL)
        if (config('database.default') !== 'sqlite') {
            return [
                'authorized' => false,
                'status' => 401,
                'code' => 'AUTH_CONTRACT_UNAVAILABLE',
                'message' => 'Authorization tables are unavailable.',
            ];
        }

        return self::resolveForStatelessTest($user, $jwtPayload, $parsedRequest, $claimTenantId, $requireTenant);
    }

    /**
     * Authoritative Auth DB verification mirroring Auth Service's UserAuthorizationService contract.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     * @param  array{raw: ?string, scope: ?string, invalid: bool, conflict: bool}  $parsedRequest
     * @return array<string, mixed>
     */
    protected static function resolveFromAuthDatabase(
        $user,
        ?array $jwtPayload,
        array $parsedRequest,
        ?string $claimTenantId,
        bool $requireTenant,
        string $connection,
        bool $hasRbac,
        bool $hasMemberships
    ): array {
        $subjectId = $user?->id ?? ($jwtPayload['sub'] ?? null);
        if (empty($subjectId)) {
            return [
                'authorized' => false,
                'status' => 401,
                'code' => 'UNAUTHENTICATED',
                'message' => 'Unauthenticated.',
            ];
        }

        $dbUser = null;
        try {
            if (SchemaGuard::hasTable('users', $connection)) {
                $dbUser = DB::connection($connection)->table('users')->where('id', $subjectId)->first();
            }
        } catch (\Throwable) {
            return [
                'authorized' => false,
                'status' => 401,
                'code' => 'AUTH_DB_UNAVAILABLE',
                'message' => 'Unable to verify user identity against Auth store.',
            ];
        }

        if (!$dbUser) {
            return [
                'authorized' => false,
                'status' => 401,
                'code' => 'USER_NOT_FOUND',
                'message' => 'User account does not exist or has been deleted.',
            ];
        }

        if (property_exists($dbUser, 'is_active') && !(bool) $dbUser->is_active) {
            return [
                'authorized' => false,
                'status' => 401,
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'User account is deactivated.',
            ];
        }

        if (property_exists($dbUser, 'locked_until') && !empty($dbUser->locked_until)) {
            try {
                if (Carbon::parse($dbUser->locked_until)->isFuture()) {
                    return [
                        'authorized' => false,
                        'status' => 401,
                        'code' => 'ACCOUNT_LOCKED_OUT',
                        'message' => 'User account is temporarily locked out.',
                    ];
                }
            } catch (\Throwable) {}
        }

        $subject = (object) [
            'id' => $dbUser->id,
            'is_active' => $dbUser->is_active ?? true,
        ];

        $isPlatformSuperAdmin = self::hasPlatformSuperAdminRole($subject, $connection);
        $requestedScope = $parsedRequest['scope'];
        $requestedTenantId = ($requestedScope !== null && $requestedScope !== 'all') ? $requestedScope : null;

        if ($requestedScope === 'all' && !$isPlatformSuperAdmin) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_MISMATCH',
                'message' => 'Access denied: Cross-tenant queries are restricted to super administrators.',
            ];
        }

        $rawAttributes = ($user && method_exists($user, 'getAttributes')) ? $user->getAttributes() : [];
        $explicitUserTenant = $jwtPayload !== null
            ? $claimTenantId
            : self::normalizeTenantId($user?->pendingTenantId ?? ($rawAttributes['tenant_id'] ?? null));

        // If authenticated via Sanctum PAT without an explicit user tenant attribute or header,
        // deterministically resolve the caller's first active workspace membership (never when a JWT omitted tenant_id).
        if ($jwtPayload === null && $explicitUserTenant === null && $requestedTenantId === null && $hasMemberships) {
            $firstTenantId = DB::connection($connection)
                ->table('tenant_users')
                ->where('user_id', $dbUser->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('tenant_id');
            $explicitUserTenant = self::normalizeTenantId($firstTenantId);
        }

        // 1. Explicit Platform-Wide Super Admin handling
        if ($isPlatformSuperAdmin) {
            $effectiveTenantId = $requestedTenantId ?? $explicitUserTenant;
            $tenant = null;

            if ($requestedScope === 'all') {
                return [
                    'authorized' => true,
                    'status' => 200,
                    'code' => 'OK',
                    'message' => 'Authorized.',
                    'user_id' => (int) $dbUser->id,
                    'tenant_id' => null,
                    'tenant_status' => null,
                    'requested_tenant_scope' => 'all',
                    'membership_status' => 'platform',
                    'outlet_id' => null,
                    'outlet_uuid' => null,
                    'role' => 'super_admin',
                    'roles' => ['super_admin'],
                    'is_super_admin' => true,
                    'is_platform_super_admin' => true,
                    'permissions' => ['*'],
                    'db_user' => $dbUser,
                    'source' => 'auth_db',
                ];
            }

            if ($effectiveTenantId !== null && SchemaGuard::hasTable('tenants', $connection)) {
                $tenant = DB::connection($connection)->table('tenants')->where('id', $effectiveTenantId)->first();
                if ($requestedTenantId !== null && !$tenant) {
                    return [
                        'authorized' => false,
                        'status' => 404,
                        'code' => 'TENANT_NOT_FOUND',
                        'message' => 'Requested workspace tenant was not found.',
                    ];
                }
            }

            $roles = $effectiveTenantId !== null
                ? self::getScopedRolesForUser($subject, $effectiveTenantId, $connection)
                : ['super_admin'];

            if (!in_array('super_admin', $roles, true)) {
                $roles = self::sortRoleSlugs(array_merge(['super_admin'], $roles));
            }

            return [
                'authorized' => true,
                'status' => 200,
                'code' => 'OK',
                'message' => 'Authorized.',
                'user_id' => (int) $dbUser->id,
                'tenant_id' => $effectiveTenantId,
                'tenant_status' => $tenant->status ?? null,
                'requested_tenant_scope' => null,
                'membership_status' => 'platform',
                'outlet_id' => isset($jwtPayload['outlet_id']) ? (int) $jwtPayload['outlet_id'] : (isset($dbUser->outlet_id) ? (int) $dbUser->outlet_id : null),
                'outlet_uuid' => null,
                'role' => 'super_admin',
                'roles' => $roles,
                'is_super_admin' => true,
                'is_platform_super_admin' => true,
                'permissions' => ['*'],
                'db_user' => $dbUser,
                'source' => 'auth_db',
            ];
        }

        // 2. Reject client header/query overrides against verified token/session tenant binding
        if ($claimTenantId !== null && $requestedTenantId !== null && $requestedTenantId !== $claimTenantId) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_MISMATCH',
                'message' => 'Tenant context mismatch: authenticated token cannot access the requested workspace.',
            ];
        }

        if ($explicitUserTenant !== null && $requestedTenantId !== null && $requestedTenantId !== $explicitUserTenant) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_MISMATCH',
                'message' => 'Access denied: You cannot operate on or query another organization.',
            ];
        }

        $effectiveTenantId = $requestedTenantId ?? $explicitUserTenant;

        if ($effectiveTenantId === null) {
            if (!$requireTenant) {
                return [
                    'authorized' => true,
                    'status' => 200,
                    'code' => 'OK',
                    'message' => 'Authorized without tenant scope.',
                    'user_id' => (int) $dbUser->id,
                    'tenant_id' => null,
                    'tenant_status' => null,
                    'requested_tenant_scope' => null,
                    'membership_status' => null,
                    'outlet_id' => null,
                    'outlet_uuid' => null,
                    'role' => 'user',
                    'roles' => ['user'],
                    'is_super_admin' => false,
                    'is_platform_super_admin' => false,
                    'permissions' => [],
                    'db_user' => $dbUser,
                    'source' => 'auth_db',
                ];
            }

            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_REQUIRED',
                'message' => 'An active workspace membership is required.',
            ];
        }

        // 3. Verify tenant exists and is active/trial
        $tenant = null;
        if (SchemaGuard::hasTable('tenants', $connection)) {
            $tenant = DB::connection($connection)->table('tenants')->where('id', $effectiveTenantId)->first();
            if (!$tenant || (property_exists($tenant, 'status') && !in_array($tenant->status, ['active', 'trial'], true))) {
                return [
                    'authorized' => false,
                    'status' => 403,
                    'code' => 'TENANT_INACTIVE',
                    'message' => 'Workspace tenant is suspended or inactive.',
                ];
            }
        }

        // 4. Verify active membership in this exact tenant
        $membership = null;
        if ($hasMemberships && SchemaGuard::hasTable('tenant_users', $connection)) {
            $membership = DB::connection($connection)
                ->table('tenant_users')
                ->where('tenant_id', $effectiveTenantId)
                ->where('user_id', $dbUser->id)
                ->where('status', 'active')
                ->first();

            if (!$membership) {
                return [
                    'authorized' => false,
                    'status' => 403,
                    'code' => 'MEMBERSHIP_REQUIRED',
                    'message' => 'An active workspace membership is required.',
                ];
            }
        }

        // 5. Scope every role lookup strictly to $effectiveTenantId
        $roles = $hasRbac ? self::getScopedRolesForUser($subject, $effectiveTenantId, $connection) : [];
        if (empty($roles)) {
            if ($tenant && property_exists($tenant, 'owner_user_id') && (string) $tenant->owner_user_id === (string) $dbUser->id) {
                $roles = ['organization'];
            } else {
                $roles = ['user'];
            }
        }

        $primaryRole = $roles[0];
        $isTenantScopedSuperAdmin = in_array('super_admin', $roles, true);

        return [
            'authorized' => true,
            'status' => 200,
            'code' => 'OK',
            'message' => 'Authorized.',
            'user_id' => (int) $dbUser->id,
            'tenant_id' => $effectiveTenantId,
            'tenant_status' => $tenant->status ?? null,
            'requested_tenant_scope' => null,
            'membership_status' => $membership->status ?? null,
            'outlet_id' => isset($jwtPayload['outlet_id']) ? (int) $jwtPayload['outlet_id'] : (isset($dbUser->outlet_id) ? (int) $dbUser->outlet_id : null),
            'outlet_uuid' => null,
            'role' => $primaryRole,
            'roles' => $roles,
            'is_super_admin' => $isTenantScopedSuperAdmin,
            'is_platform_super_admin' => false,
            'permissions' => is_array($jwtPayload['permissions'] ?? null) ? $jwtPayload['permissions'] : [],
            'db_user' => $dbUser,
            'source' => 'auth_db',
        ];
    }

    /**
     * Deterministic fallback strictly for schema-less SQLite unit tests lacking auth_db RBAC tables.
     *
     * @param  array<string, mixed>|null  $jwtPayload
     * @param  array{raw: ?string, scope: ?string, invalid: bool, conflict: bool}  $parsedRequest
     * @return array<string, mixed>
     */
    protected static function resolveForStatelessTest(
        $user,
        ?array $jwtPayload,
        array $parsedRequest,
        ?string $claimTenantId,
        bool $requireTenant
    ): array {
        $rawAttributes = ($user && method_exists($user, 'getAttributes')) ? $user->getAttributes() : [];
        $rawRole = $jwtPayload['role'] ?? ($rawAttributes['role'] ?? ($user?->pendingRole ?? 'cashier'));
        if (!is_string($rawRole) || trim($rawRole) === '') {
            $rawRole = 'user';
        }
        $rawRole = trim($rawRole);

        $boundTenant = $claimTenantId ?? self::normalizeTenantId($user?->pendingTenantId ?? ($rawAttributes['tenant_id'] ?? null));
        $isPlatformSuperAdmin = $rawRole === 'super_admin' && $boundTenant === null;
        $isSuperAdmin = $rawRole === 'super_admin';

        $requestedScope = $parsedRequest['scope'];
        $requestedTenantId = ($requestedScope !== null && $requestedScope !== 'all') ? $requestedScope : null;

        if ($requestedScope === 'all') {
            if (!$isPlatformSuperAdmin && !$isSuperAdmin) {
                return [
                    'authorized' => false,
                    'status' => 403,
                    'code' => 'TENANT_CONTEXT_MISMATCH',
                    'message' => 'Access denied: Cross-tenant queries are restricted to super administrators.',
                ];
            }

            return [
                'authorized' => true,
                'status' => 200,
                'code' => 'OK',
                'message' => 'Authorized.',
                'user_id' => (int) ($user?->id ?? $jwtPayload['sub'] ?? 0),
                'tenant_id' => null,
                'tenant_status' => null,
                'requested_tenant_scope' => 'all',
                'membership_status' => 'platform',
                'outlet_id' => isset($jwtPayload['outlet_id']) ? (int) $jwtPayload['outlet_id'] : ($user?->outlet_id ?? null),
                'outlet_uuid' => null,
                'role' => 'super_admin',
                'roles' => ['super_admin'],
                'is_super_admin' => true,
                'is_platform_super_admin' => $isPlatformSuperAdmin,
                'permissions' => is_array($jwtPayload['permissions'] ?? null) ? $jwtPayload['permissions'] : ['*'],
                'source' => 'sqlite_stateless_test',
            ];
        }

        if ($boundTenant !== null && $requestedTenantId !== null && $requestedTenantId !== $boundTenant && !$isPlatformSuperAdmin) {
            return [
                'authorized' => false,
                'status' => 403,
                'code' => 'TENANT_CONTEXT_MISMATCH',
                'message' => 'Access denied: You cannot operate on or query another organization.',
            ];
        }

        $effectiveTenantId = $isPlatformSuperAdmin
            ? ($requestedTenantId ?? $boundTenant)
            : ($boundTenant ?? $requestedTenantId);

        if ($effectiveTenantId === null && $requireTenant && !$isPlatformSuperAdmin && !$isSuperAdmin) {
            // Preserve legacy test harness default tenant '1' solely when schema-less SQLite tests omit tenant_id
            $effectiveTenantId = '1';
        }

        $roles = self::sortRoleSlugs([$rawRole]);
        if (empty($roles)) {
            $roles = ['user'];
        }

        return [
            'authorized' => true,
            'status' => 200,
            'code' => 'OK',
            'message' => 'Authorized.',
            'user_id' => (int) ($user?->id ?? $jwtPayload['sub'] ?? 0),
            'tenant_id' => $effectiveTenantId,
            'tenant_status' => null,
            'requested_tenant_scope' => null,
            'membership_status' => null,
            'outlet_id' => isset($jwtPayload['outlet_id']) ? (int) $jwtPayload['outlet_id'] : ($user?->outlet_id ?? null),
            'outlet_uuid' => null,
            'role' => $roles[0],
            'roles' => $roles,
            'is_super_admin' => $isSuperAdmin,
            'is_platform_super_admin' => $isPlatformSuperAdmin,
            'permissions' => is_array($jwtPayload['permissions'] ?? null) ? $jwtPayload['permissions'] : [],
            'source' => 'sqlite_stateless_test',
        ];
    }

    /**
     * Evaluate whether a user's tenant-scoped roles grant access to any of the required endpoint roles.
     *
     * @param  array<int, string>  $scopedRoles
     * @param  array<int, string>  $requiredRoles
     */
    public static function roleGrantsAccess(array $scopedRoles, array $requiredRoles, bool $isSuperAdmin = false): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        $effectiveRoles = self::sortRoleSlugs($scopedRoles);

        if (!empty(array_intersect($effectiveRoles, self::ADMIN_ALIAS_EQUIVALENTS))) {
            $effectiveRoles = array_values(array_unique(array_merge($effectiveRoles, self::ADMIN_ALIAS_EQUIVALENTS)));
        }

        return !empty(array_intersect($effectiveRoles, $requiredRoles));
    }

    /**
     * Verify a supervisor override PIN strictly within the verified tenant scope.
     */
    public static function verifySupervisorPin(Request $request, string $pin, ?string $tenantId = null): bool
    {
        if (trim($pin) === '') {
            return false;
        }

        $jwtClaims = $request->attributes->get('jwt_claims');
        $context = self::resolveAuthorizationContext(
            $request,
            $request->user(),
            is_array($jwtClaims) ? $jwtClaims : null,
            false
        );

        if (empty($context['authorized'])) {
            return false;
        }

        $effectiveTenantId = $tenantId ?? ($context['tenant_id'] ?? null);

        if (config('services.auth.verify_via_http', false) && $request->bearerToken()) {
            return app(AuthServiceClient::class)->verifySupervisorPin(
                $pin,
                $request->bearerToken(),
                $request->header('X-App-Key'),
                $effectiveTenantId
            );
        }

        $supervisorSlugs = [
            'supervisor',
            'outlet_manager',
            'store_manager',
            'admin',
            'administrator',
            'organization',
            'organization_owner',
            'owner',
            'tenant_admin',
            'super_admin',
        ];

        $conn = self::authConnectionName();
        $hasRbac = self::hasRbacTables($conn);

        if ($hasRbac) {
            if (empty($effectiveTenantId) || $effectiveTenantId === 'all') {
                return false;
            }

            if (SchemaGuard::hasTable('user_pos_pins', $conn)) {
                $candidates = DB::connection($conn)
                    ->table('user_pos_pins as upp')
                    ->join('users as u', 'upp.user_id', '=', 'u.id')
                    ->join('user_roles as ur', 'u.id', '=', 'ur.user_id')
                    ->join('roles as r', 'ur.role_id', '=', 'r.id')
                    ->where('upp.tenant_id', $effectiveTenantId)
                    ->where('upp.is_active', true)
                    ->where('ur.tenant_id', $effectiveTenantId)
                    ->where('ur.is_active', true)
                    ->whereIn('r.slug', $supervisorSlugs)
                    ->when(SchemaGuard::hasColumn('users', 'is_active', $conn), fn ($q) => $q->where('u.is_active', true))
                    ->pluck('upp.pin_hash');

                foreach ($candidates as $hash) {
                    if (!is_string($hash) || $hash === '') {
                        continue;
                    }
                    if (str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2')) {
                        if (Hash::check($pin, $hash)) {
                            return true;
                        }
                    } elseif (hash_equals($hash, hash('sha256', $pin))) {
                        return true;
                    }
                }

                return false;
            }

            if (!SchemaGuard::hasColumn('users', 'pin_code', $conn)) {
                return false;
            }

            return DB::connection($conn)
                ->table('users as u')
                ->join('user_roles as ur', 'u.id', '=', 'ur.user_id')
                ->join('roles as r', 'ur.role_id', '=', 'r.id')
                ->where('ur.is_active', true)
                ->where('ur.tenant_id', $effectiveTenantId)
                ->where('u.pin_code', $pin)
                ->whereIn('r.slug', $supervisorSlugs)
                ->when(SchemaGuard::hasColumn('users', 'is_active', $conn), fn ($q) => $q->where('u.is_active', true))
                ->exists();
        }

        if (config('database.default') !== 'sqlite' || !SchemaGuard::hasColumn('users', 'pin_code')) {
            return false;
        }

        return DB::table('users')
            ->where('pin_code', $pin)
            ->whereIn('role', ['supervisor', 'outlet_manager', 'admin', 'super_admin'])
            ->exists();
    }
}
