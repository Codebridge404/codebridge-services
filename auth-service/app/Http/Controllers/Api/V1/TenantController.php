<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-05
 * Task: BD-0138
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\RegisterTenantRequest;
use App\Http\Requests\Tenant\UpdateSubscriptionRequest;
use App\Http\Requests\Tenant\UpdateTenantModulesRequest;
use App\Http\Requests\Tenant\UpdateTenantProfileRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\Tenant\TenantResource;
use App\Support\SchemaGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [BD-0003] Multi-Tenant Organization Provisioning & Management Controller.
 *
 * @ticket BD-0003
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/TenantController.php
 * @related-model App\Models\Tenant, App\Models\TenantSubscription, App\Models\Outlet
 * @entry-point POST /api/v1/tenants/register, GET /api/v1/tenants/my, GET /api/v1/tenants/{id}/settings
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Self-service organization registration with slug auto-generation.
 * 2. Automated provisioning of 14-day trial in tenant_subscriptions table.
 * 3. Primary outlet creation (Main Branch) and default terminal REG-01.
 * 4. Tenant switcher context resolver and subscription quota management.
 */
class TenantController extends Controller
{
    /**
     * Authenticated Self-Service Tenant Registration.
     * POST /api/v1/tenants/register
     */
    public function register(RegisterTenantRequest $request)
    {
        $authUser = auth('sanctum')->user() ?? $request->user();
        abort_unless($authUser, 401, 'Authentication required to register a tenant workspace.');

        if (!$authUser->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'User account is deactivated.',
            ], 401);
        }

        if (method_exists($authUser, 'isLockedOut') && $authUser->isLockedOut()) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => 'User account is temporarily locked out.',
            ], 401);
        }

        $token = method_exists($authUser, 'currentAccessToken') ? $authUser->currentAccessToken() : null;
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        $isPos = $request->attributes->get('is_pos_session', false)
            || (in_array('pos:access', $abilities, true) && !in_array('*', $abilities, true))
            || ($token && method_exists($token, 'can') && $token->can('pos:access') && !$token->can('*'));
        if ($isPos) {
            abort(403, 'This endpoint requires a full account session.');
        }

        // Prevent authenticated user from provisioning on behalf of another user
        foreach (['owner_user_id', 'owner_id', 'user_id'] as $ownerField) {
            if ($request->filled($ownerField) && (string) $request->input($ownerField) !== (string) $authUser->id) {
                abort(403, 'Authenticated user cannot provision a workspace for another account.');
            }
        }
        if ($request->filled('owner_email') && strtolower(trim((string) $request->input('owner_email'))) !== strtolower(trim((string) $authUser->email))) {
            abort(403, 'Authenticated user cannot provision a workspace for another account.');
        }

        $clientTier = $request->client_tier ?? 'business_runner';
        $baseSlug = Str::slug($request->name) ?: ('tenant-' . Str::lower(Str::random(6)));

        $limits = match ($clientTier) {
            'enterprise_org'  => ['max_outlets' => 50, 'max_registers' => 200, 'max_users' => 500],
            'business_runner' => ['max_outlets' => 5,  'max_registers' => 15,  'max_users' => 50],
            default           => ['max_outlets' => 1,  'max_registers' => 2,   'max_users' => 5],
        };

        $planMap = [
            'free_personal'   => ['plan' => 'Personal Solopreneur Free', 'price' => 0],
            'business_runner' => ['plan' => 'Business Runner Pro',       'price' => 49.00],
            'enterprise_org'  => ['plan' => 'Enterprise Organization',   'price' => 199.00],
        ];

        $planInfo = $planMap[$clientTier];

        $result = DB::transaction(function () use ($request, $authUser, $clientTier, $baseSlug, $limits, $planInfo) {
            $slug = $baseSlug;
            while (DB::table('tenants')->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . Str::lower(Str::random(4));
            }

            // Store organization contact email; owner is derived strictly from the authenticated user
            $contactEmail = $request->email ?: ($authUser->email ?? ($slug . '@codebridges.app'));
            $tenantUuid = (string) Str::uuid();

            $id = DB::table('tenants')->insertGetId([
                'uuid'          => $tenantUuid,
                'owner_user_id' => $authUser->id,
                'name'          => $request->name,
                'slug'          => $slug,
                'company_code'  => 'CB-' . strtoupper(Str::random(6)),
                'client_tier'   => $clientTier,
                'status'        => 'trial',
                'email'         => $contactEmail,
                'phone'         => $request->phone,
                'address'       => $request->address,
                'country'       => $request->country ?? 'KH',
                'currency'      => 'USD',
                'enabled_modules' => json_encode([]),
                'max_outlets'   => $limits['max_outlets'],
                'max_registers' => $limits['max_registers'],
                'max_users'     => $limits['max_users'],
                'trial_ends_at' => now()->addDays(14),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('tenant_subscriptions')->insert([
                'tenant_id'     => $id,
                'plan_name'     => $planInfo['plan'],
                'billing_cycle' => 'monthly',
                'price'         => $planInfo['price'],
                'currency'      => 'USD',
                'status'        => 'pending',
                'starts_at'     => now(),
                'expires_at'    => now()->addDays(14),
                'notes'         => '14-day free trial period.',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // Automatically provision Default Primary Store Outlet & Register for this tenant
            $outletCode = 'STR-' . strtoupper(Str::random(4));
            $outletUuid = (string) Str::uuid();
            $outletId = DB::table('outlets')->insertGetId([
                'uuid'           => $outletUuid,
                'tenant_id'      => $id,
                'name'           => $request->name . ' - Main Store',
                'code'           => $outletCode,
                'phone'          => $request->phone,
                'address'        => $request->address,
                'receipt_header' => 'Welcome to ' . $request->name,
                'receipt_footer' => 'Thank you for shopping with us!',
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            if (SchemaGuard::hasTable('registers')) {
                DB::table('registers')->insert([
                    'outlet_id'  => $outletId,
                    'name'       => 'Register #1 (Main)',
                    'code'       => 'REG-01',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Insert active membership in tenant_users for authenticated owner
            if (SchemaGuard::hasTable('tenant_users')) {
                DB::table('tenant_users')->updateOrInsert(
                    ['tenant_id' => $id, 'user_id' => $authUser->id],
                    [
                        'status' => 'active',
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $tenantUserId = DB::table('tenant_users')
                    ->where('tenant_id', $id)
                    ->where('user_id', $authUser->id)
                    ->value('id');

                if ($tenantUserId && SchemaGuard::hasTable('tenant_user_outlets')) {
                    DB::table('tenant_user_outlets')->updateOrInsert(
                        ['tenant_user_id' => $tenantUserId, 'outlet_uuid' => $outletUuid],
                        ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }

            // Link organization role scoped strictly to this tenant without touching roles in other tenants or super_admin
            if (SchemaGuard::hasTable('user_roles')) {
                $roleId = DB::table('roles')->where('slug', 'organization')->value('id');
                if (!$roleId) {
                    $roleId = DB::table('roles')->whereIn('slug', ['admin', 'owner', 'tenant_admin'])->value('id');
                }
                if (!$roleId) {
                    $roleId = DB::table('roles')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'name' => 'Organization Owner',
                        'slug' => 'organization',
                        'parent_role' => 'organization',
                        'description' => 'Primary Tenant Organization Profile and Legal Account Holder with full workspace authority.',
                        'is_system' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('user_roles')->updateOrInsert(
                    ['tenant_id' => $id, 'user_id' => $authUser->id, 'role_id' => $roleId],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            // Update legacy users table columns only if they exist
            $legacyUpdates = ['updated_at' => now()];
            if (SchemaGuard::hasColumn('users', 'tenant_id')) {
                $legacyUpdates['tenant_id'] = $id;
            }
            if (SchemaGuard::hasColumn('users', 'outlet_id')) {
                $legacyUpdates['outlet_id'] = $outletId;
            }
            if (SchemaGuard::hasColumn('users', 'role') && !\App\Services\UserAuthorizationService::isSuperAdmin($authUser)) {
                $legacyUpdates['role'] = 'organization';
            }
            if (count($legacyUpdates) > 1) {
                DB::table('users')->where('id', $authUser->id)->update($legacyUpdates);
            }

            $userModel = \App\Models\User::findOrFail($authUser->id);
            $userModel->pendingTenantId = $id;
            $userModel->pendingOutletUuid = $outletUuid;
            $updatedUser = (new \App\Http\Resources\User\UserResource($userModel))->toArray($request);

            /** @var \App\Services\JwtService $jwtService */
            $jwtService = app(\App\Services\JwtService::class);
            $deviceName = $request->device_name ?? 'web_session';
            $tokenResult = $userModel->createToken($deviceName, ['*'], now()->addSeconds($jwtService->getRefreshTtlSeconds()));
            $jwtService->bindSession($tokenResult->accessToken);
            $newJwtToken = $jwtService->generateTokenForUser($userModel, ['sid' => (string) $tokenResult->accessToken->id]);
            $newRefreshToken = $jwtService->generateRefreshTokenForUser($userModel, ['sid' => (string) $tokenResult->accessToken->id]);

            $request->attributes->set('auth_user_id', $authUser->id);
            $request->attributes->set('auth_tenant_id', $id);

            $this->recordAuditLog(
                action: 'tenant.registered',
                module: 'tenant',
                entityType: 'tenant',
                entityId: (string) $id,
                details: "Registered tenant workspace '{$request->name}' for user #{$authUser->id}",
                userId: $authUser->id,
                userName: $authUser->name,
                tenantId: $id
            );

            $tenantRecord = DB::table('tenants')->where('id', $id)->first();

            return [
                'user' => $updatedUser,
                'access_token' => $newJwtToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => $jwtService->getTtlSeconds(),
                'data' => array_merge(
                    (array) $tenantRecord,
                    [
                        'outlet_id' => $outletId,
                        'outlet_uuid' => $outletUuid,
                        'outlet_name' => $request->name . ' - Main Store',
                        'outlet_code' => $outletCode,
                    ]
                ),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Tenant workspace registered successfully! Your 14-day trial has started.',
            'user' => $result['user'],
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $result['expires_in'],
            'data' => $result['data'],
        ], 201);
    }

    /**
     * Super Admin: List all tenants.
     * GET /api/v1/super-admin/tenants
     */
    public function index(Request $request)
    {
        $query = DB::table('tenants')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('client_tier')) {
            $query->where('client_tier', $request->client_tier);
        }

        if ($request->filled('q')) {
            $q = SchemaGuard::escapeLike(trim((string) $request->q));
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%$q%")
                    ->orWhere('email', 'like', "%$q%")
                    ->orWhere('slug', 'like', "%$q%");
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage);
        $tenants = collect($paginator->items());
        $tenantIds = $tenants->pluck('id')->all();

        // Batch-load subscriptions, counts, and candidate owners in constant query count
        $subscriptionsByTenant = empty($tenantIds)
            ? collect()
            : DB::table('tenant_subscriptions')
                ->whereIn('tenant_id', $tenantIds)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('tenant_id');

        $usersCountByTenant = collect();
        if (!empty($tenantIds)) {
            try {
                $usersCountByTenant = DB::table('tenant_users')
                    ->whereIn('tenant_id', $tenantIds)
                    ->where('status', 'active')
                    ->groupBy('tenant_id')
                    ->selectRaw('tenant_id, COUNT(*) as aggregate_count')
                    ->pluck('aggregate_count', 'tenant_id');
            } catch (\Throwable) {
                $usersCountByTenant = collect();
            }
        }

        $outletsCountByTenant = collect();
        if (!empty($tenantIds)) {
            try {
                $outletsCountByTenant = DB::table('outlets')
                    ->whereIn('tenant_id', $tenantIds)
                    ->groupBy('tenant_id')
                    ->selectRaw('tenant_id, COUNT(*) as aggregate_count')
                    ->pluck('aggregate_count', 'tenant_id');
            } catch (\Throwable) {
                $outletsCountByTenant = collect();
            }
        }

        $ownerUserIds = $tenants->pluck('owner_user_id')->filter()->unique()->values()->all();
        $explicitOwnersByUser = empty($ownerUserIds)
            ? collect()
            : DB::table('users as u')
                ->leftJoin('user_roles as ur', function ($j) {
                    $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true);
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->whereIn('u.id', $ownerUserIds)
                ->select(
                    'u.id',
                    'u.name',
                    'u.email',
                    'u.phone',
                    'ur.tenant_id as role_tenant_id',
                    DB::raw("COALESCE(r.slug, 'organization') as role"),
                    'u.is_active',
                    'u.created_at'
                )
                ->get()
                ->groupBy('id');

        $memberOwnersByTenant = empty($tenantIds)
            ? collect()
            : DB::table('tenant_users as tu')
                ->join('users as u', 'tu.user_id', '=', 'u.id')
                ->leftJoin('user_roles as ur', function ($j) {
                    $j->on('u.id', '=', 'ur.user_id')
                        ->where('ur.is_active', true)
                        ->on('ur.tenant_id', '=', 'tu.tenant_id');
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->whereIn('tu.tenant_id', $tenantIds)
                ->where('tu.status', 'active')
                ->select(
                    'tu.tenant_id',
                    'u.id',
                    'u.name',
                    'u.email',
                    'u.phone',
                    DB::raw("COALESCE(r.slug, 'organization') as role"),
                    'u.is_active',
                    'u.created_at'
                )
                ->orderBy('u.created_at', 'asc')
                ->get()
                ->groupBy('tenant_id');

        foreach ($tenants as $tenant) {
            $tenant->owner = null;
            $tenant->subscription = $subscriptionsByTenant->get($tenant->id)?->first();
            $tenant->subscriptions = $tenant->subscription ? [$tenant->subscription] : [];

            if (!empty($tenant->owner_user_id)) {
                $candidates = $explicitOwnersByUser->get($tenant->owner_user_id, collect());
                $matched = $candidates->firstWhere('role_tenant_id', $tenant->id) ?? $candidates->first();
                if ($matched) {
                    $ownerObj = clone $matched;
                    unset($ownerObj->role_tenant_id);
                    $tenant->owner = $ownerObj;
                }
            }

            if (!$tenant->owner) {
                $members = $memberOwnersByTenant->get($tenant->id, collect());
                $adminMember = $members->first(fn ($m) => in_array($m->role, ['organization', 'admin', 'administrator', 'owner'], true));
                $chosen = $adminMember ?? $members->first();
                if ($chosen) {
                    $ownerObj = clone $chosen;
                    unset($ownerObj->tenant_id);
                    $tenant->owner = $ownerObj;
                }
            }

            $tenant->users_count = (int) ($usersCountByTenant[$tenant->id] ?? 0);
            $tenant->outlets_count = (int) ($outletsCountByTenant[$tenant->id] ?? 0);
        }

        $statsRow = DB::table('tenants')
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial_count, SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count, SUM(CASE WHEN client_tier = 'enterprise_org' THEN 1 ELSE 0 END) as enterprise_org_count, SUM(CASE WHEN client_tier = 'business_runner' THEN 1 ELSE 0 END) as business_runner_count, SUM(CASE WHEN client_tier = 'free_personal' THEN 1 ELSE 0 END) as free_personal_count")
            ->first();

        $stats = [
            'total'          => (int) ($statsRow->total ?? 0),
            'active'         => (int) ($statsRow->active_count ?? 0),
            'trial'          => (int) ($statsRow->trial_count ?? 0),
            'suspended'      => (int) ($statsRow->suspended_count ?? 0),
            'enterprise_org' => (int) ($statsRow->enterprise_org_count ?? 0),
            'business_runner'=> (int) ($statsRow->business_runner_count ?? 0),
            'free_personal'  => (int) ($statsRow->free_personal_count ?? 0),
        ];

        return response()->json([
            'success' => true,
            'data'    => TenantResource::collection($tenants),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'stats'   => $stats,
        ]);
    }

    /**
     * Super Admin: View single tenant detail.
     * GET /api/v1/super-admin/tenants/{id}
     */
    public function show($id)
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
        }

        $tenant->subscriptions = DB::table('tenant_subscriptions')
            ->where('tenant_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        $tenant->owner = null;
        if (!empty($tenant->owner_user_id)) {
            $tenant->owner = DB::table('users as u')
                ->leftJoin('user_roles as ur', function ($j) use ($id) {
                    $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $id);
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->where('u.id', $tenant->owner_user_id)
                ->select('u.id', 'u.name', 'u.email', 'u.phone', DB::raw("COALESCE(r.slug, 'organization') as role"), 'u.is_active', 'u.created_at')
                ->first();
        }

        if (!$tenant->owner) {
            $tenant->owner = DB::table('users as u')
                ->join('tenant_users as tu', function ($j) use ($id) {
                    $j->on('u.id', '=', 'tu.user_id')->where('tu.tenant_id', $id)->where('tu.status', 'active');
                })
                ->leftJoin('user_roles as ur', function ($j) use ($id) {
                    $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $id);
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->where(function ($q) {
                    $q->whereIn('r.slug', ['organization', 'admin', 'administrator', 'owner']);
                })
                ->select('u.id', 'u.name', 'u.email', 'u.phone', DB::raw("COALESCE(r.slug, 'organization') as role"), 'u.is_active', 'u.created_at')
                ->first()
                ?? DB::table('users as u')
                    ->join('tenant_users as tu', function ($j) use ($id) {
                        $j->on('u.id', '=', 'tu.user_id')->where('tu.tenant_id', $id)->where('tu.status', 'active');
                    })
                    ->leftJoin('user_roles as ur', function ($j) use ($id) {
                        $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $id);
                    })
                    ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                    ->select('u.id', 'u.name', 'u.email', 'u.phone', DB::raw("COALESCE(r.slug, 'organization') as role"), 'u.is_active', 'u.created_at')
                    ->first();
        }

        $tenant->users_count = DB::table('tenant_users')->where('tenant_id', $id)->where('status', 'active')->count();
        try {
            $tenant->outlets_count = DB::table('outlets')->where('tenant_id', $id)->count();
        } catch (\Throwable $e) {
            $tenant->outlets_count = 1;
        }

        return response()->json([
            'success' => true,
            'data'    => new TenantResource($tenant),
        ]);
    }

    /**
     * Super Admin: Update tenant organization details and owner.
     * PUT /api/v1/super-admin/tenants/{id}
     */
    public function update(UpdateTenantRequest $request, $id)
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
        }

        $updateData = [];
        foreach (['name', 'client_tier', 'status', 'email', 'phone', 'address', 'max_outlets', 'max_registers', 'max_users', 'trial_ends_at'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }
        $updateData['updated_at'] = now();

        DB::table('tenants')->where('id', $id)->update($updateData);

        // Update Owner details if provided
        if ($request->filled('owner_id')) {
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $id, 'user_id' => $request->owner_id],
                ['status' => 'active', 'updated_at' => now()]
            );
            DB::table('tenants')->where('id', $id)->update(['owner_user_id' => $request->owner_id]);

            $owner = DB::table('users as u')
                ->join('tenant_users as tu', function ($j) use ($id) {
                    $j->on('u.id', '=', 'tu.user_id')->where('tu.tenant_id', $id)->where('tu.status', 'active');
                })
                ->leftJoin('user_roles as ur', function ($j) use ($id) {
                    $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $id);
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->where(function ($q) {
                    $q->whereIn('r.slug', ['organization', 'admin', 'administrator', 'owner']);
                })
                ->select('u.id', 'u.name', 'u.email', 'u.phone', 'u.is_active')
                ->first();

            if ($owner) {
                $ownerUpdates = [];
                if ($request->filled('owner_name')) $ownerUpdates['name'] = $request->owner_name;
                if ($request->filled('owner_email')) $ownerUpdates['email'] = $request->owner_email;
                if ($request->filled('owner_phone')) $ownerUpdates['phone'] = $request->owner_phone;
                $ownerUpdates['updated_at'] = now();

                DB::table('users')->where('id', $owner->id)->update($ownerUpdates);
            } else if ($request->filled('owner_email')) {
                $orgRoleId = DB::table('roles')->whereIn('slug', ['organization', 'admin'])->value('id');
                $existingUser = DB::table('users')->where('email', $request->owner_email)->first();
                if ($existingUser) {
                    $ownerUpdate = [
                        'name'      => $request->owner_name ?? $existingUser->name,
                        'phone'     => $request->owner_phone ?? $existingUser->phone,
                        'updated_at'=> now(),
                    ];
                    if (SchemaGuard::hasColumn('users', 'tenant_id')) {
                        $ownerUpdate['tenant_id'] = $id;
                    }
                    if (SchemaGuard::hasColumn('users', 'role')) {
                        $ownerUpdate['role'] = 'organization';
                    }
                    DB::table('users')->where('id', $existingUser->id)->update($ownerUpdate);
                    $ownerId = $existingUser->id;
                } else {
                    $ownerInsert = [
                        'uuid'       => (string) \Illuminate\Support\Str::uuid(),
                        'name'       => $request->owner_name ?? 'Store Owner',
                        'email'      => $request->owner_email,
                        'phone'      => $request->owner_phone,
                        'password'   => Hash::make('password'),
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (SchemaGuard::hasColumn('users', 'tenant_id')) {
                        $ownerInsert['tenant_id'] = $id;
                    }
                    if (SchemaGuard::hasColumn('users', 'role')) {
                        $ownerInsert['role'] = 'organization';
                    }
                    $ownerId = DB::table('users')->insertGetId($ownerInsert);
                }

                DB::table('tenant_users')->updateOrInsert(
                    ['tenant_id' => $id, 'user_id' => $ownerId],
                    ['status' => 'active', 'updated_at' => now()]
                );

                if ($orgRoleId) {
                    DB::table('user_roles')->updateOrInsert(
                        ['tenant_id' => $id, 'user_id' => $ownerId, 'role_id' => $orgRoleId],
                        [
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }

        $this->recordAuditLog(
            action: 'tenant.updated',
            module: 'tenant',
            entityType: 'tenant',
            entityId: (string) $id,
            details: "Super admin updated tenant {$id} organization details" . ($request->filled('owner_id') || $request->filled('owner_email') ? ' and assigned owner' : ''),
            userId: $request->user()?->id,
            userName: $request->user()?->name,
            tenantId: (int) $id
        );

        return response()->json([
            'success' => true,
            'message' => 'Tenant organization and owner details updated successfully.',
            'data'    => DB::table('tenants')->where('id', $id)->first(),
        ]);
    }

    /**
     * Super Admin: Delete tenant organization.
     * DELETE /api/v1/super-admin/tenants/{id}
     */
    public function destroy($id)
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
        }

        if (SchemaGuard::hasColumn('users', 'tenant_id')) {
            DB::table('users')->where('tenant_id', $id)->update(['tenant_id' => null]);
        }
        DB::table('tenant_users')->where('tenant_id', $id)->delete();
        DB::table('tenant_subscriptions')->where('tenant_id', $id)->delete();
        DB::table('tenants')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => "Tenant organization '{$tenant->name}' has been deleted successfully.",
        ]);
    }

    /**
     * Super Admin: Update tenant subscription plan.
     * PUT /api/v1/super-admin/tenants/{id}/subscription
     */
    public function updateSubscription(UpdateSubscriptionRequest $request, $id)
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
        }

        DB::table('tenant_subscriptions')->insert([
            'tenant_id'     => $id,
            'plan_name'     => $request->plan_name,
            'billing_cycle' => $request->billing_cycle,
            'price'         => $request->price,
            'currency'      => 'USD',
            'status'        => 'active',
            'starts_at'     => now(),
            'expires_at'    => $request->expires_at ?? now()->addMonth(),
            'notes'         => $request->notes,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $updateData = ['status' => 'active', 'updated_at' => now()];
        if ($request->filled('client_tier')) {
            $limits = match ($request->client_tier) {
                'enterprise_org'  => ['max_outlets' => 50, 'max_registers' => 200, 'max_users' => 500],
                'business_runner' => ['max_outlets' => 5,  'max_registers' => 15,  'max_users' => 50],
                default           => ['max_outlets' => 1,  'max_registers' => 2,   'max_users' => 5],
            };
            $updateData = array_merge($updateData, $limits, ['client_tier' => $request->client_tier]);
        }

        DB::table('tenants')->where('id', $id)->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Tenant subscription updated successfully.',
        ]);
    }

    /**
     * Super Admin: Suspend or activate tenant account.
     * POST /api/v1/super-admin/tenants/{id}/suspend
     */
    public function suspend(Request $request, $id)
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant not found.'], 404);
        }

        $newStatus = $tenant->status === 'suspended' ? 'active' : 'suspended';

        DB::table('tenants')->where('id', $id)->update([
            'status'     => $newStatus,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Tenant account has been " . ($newStatus === 'suspended' ? 'suspended' : 'reactivated') . " successfully.",
            'status'  => $newStatus,
        ]);
    }

    /**
     * Get Real-time Subscription Quota Usage for active tenant.
     * GET /api/v1/tenants/quota-usage
     */
    public function quotaUsage(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;
        $activeWorkspace = $request->header('X-Tenant-Workspace');

        $tenantQuery = DB::table('tenants');
        if ($tenantId) {
            $tenantQuery->where('id', $tenantId);
        } elseif (!empty($activeWorkspace) && $activeWorkspace !== 'No Organization Yet! Please Create') {
            $tenantQuery->where('name', $activeWorkspace)->orWhere('slug', $activeWorkspace);
        }

        $tenant = $tenantQuery->first() ?? DB::table('tenants')->first();

        $tier = $tenant->client_tier ?? 'free_personal';
        $maxUsers = $tenant->max_users ?? ($tier === 'enterprise_org' ? 500 : ($tier === 'business_runner' ? 50 : 5));
        $maxOutlets = $tenant->max_outlets ?? ($tier === 'enterprise_org' ? 50 : ($tier === 'business_runner' ? 5 : 1));
        $maxRegisters = $tenant->max_registers ?? ($tier === 'enterprise_org' ? 200 : ($tier === 'business_runner' ? 15 : 2));

        $usersCount = ($tenant && SchemaGuard::hasTable('tenant_users')) ? DB::table('tenant_users as tu')
            ->join('users as u', 'tu.user_id', '=', 'u.id')
            ->leftJoin('user_roles as ur', function ($j) use ($tenant) {
                $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $tenant->id);
            })
            ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
            ->where('tu.tenant_id', $tenant->id)
            ->where('tu.status', 'active')
            ->where(function ($q) {
                $q->whereNull('r.slug')->orWhere('r.slug', '!=', 'super_admin');
            })
            ->distinct()
            ->count('u.id') : 0;
        $outletsCount = ($tenant && SchemaGuard::hasTable('outlets')) ? DB::table('outlets')->where('tenant_id', $tenant->id)->count() : 0;
        $registersCount = ($tenant && SchemaGuard::hasTable('registers')) ? DB::table('registers')->join('outlets', 'registers.outlet_id', '=', 'outlets.id')->where('outlets.tenant_id', $tenant->id)->count() : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'tenant' => [
                    'id' => $tenant->id ?? null,
                    'name' => $tenant->name ?? 'Default Workspace',
                    'slug' => $tenant->slug ?? 'default',
                    'client_tier' => $tier,
                    'status' => $tenant->status ?? 'active',
                    'trial_ends_at' => $tenant->trial_ends_at ?? null,
                ],
                'quotas' => [
                    'users' => [
                        'used' => $usersCount,
                        'limit' => $maxUsers,
                        'percentage' => $maxUsers > 0 ? round(($usersCount / $maxUsers) * 100, 1) : 0,
                        'is_exceeded' => $usersCount >= $maxUsers,
                    ],
                    'outlets' => [
                        'used' => $outletsCount,
                        'limit' => $maxOutlets,
                        'percentage' => $maxOutlets > 0 ? round(($outletsCount / $maxOutlets) * 100, 1) : 0,
                        'is_exceeded' => $outletsCount >= $maxOutlets,
                    ],
                    'registers' => [
                        'used' => $registersCount,
                        'limit' => $maxRegisters,
                        'percentage' => $maxRegisters > 0 ? round(($registersCount / $maxRegisters) * 100, 1) : 0,
                        'is_exceeded' => $registersCount >= $maxRegisters,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get enabled modules for the active organization workspace.
     * GET /api/v1/tenants/modules
     */
    public function getModules(Request $request)
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => true,
                'modules' => [],
            ]);
        }

        $rawModules = $tenant->enabled_modules ?? null;
        $modules = is_string($rawModules) ? json_decode($rawModules, true) : (is_array($rawModules) ? $rawModules : []);

        if (!is_array($modules)) {
            $modules = [];
        }

        return response()->json([
            'success'     => true,
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => $modules,
        ]);
    }

    /**
     * Update enabled modules for the active organization workspace in cloud database.
     * PUT /api/v1/tenants/modules
     */
    public function updateModules(UpdateTenantModulesRequest $request)
    {

        $tenant = $this->resolveTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Organization workspace not found.',
            ], 404);
        }

        DB::table('tenants')->where('id', $tenant->id)->update([
            'enabled_modules' => json_encode($request->modules),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'success'     => true,
            'message'     => 'Organization modules saved to cloud database successfully!',
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => $request->modules,
        ]);
    }

    /**
     * Get active tenant workspace profile for current tenant/user.
     * GET /api/v1/tenants/profile
     */
    public function getProfile(Request $request)
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Workspace not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $tenant->id,
                'name'           => $tenant->name,
                'legal_name'     => $tenant->legal_name ?? null,
                'slug'           => $tenant->slug,
                'company_code'   => $tenant->company_code ?? null,
                'logo_url'       => $tenant->logo_url ?? null,
                'receipt_footer' => $tenant->receipt_footer ?? null,
                'email'          => $tenant->email ?? null,
                'phone'          => $tenant->phone ?? null,
                'address'        => $tenant->address ?? null,
                'country'        => $tenant->country ?? 'KH',
                'currency'       => $tenant->currency ?? 'USD',
                'client_tier'    => $tenant->client_tier ?? 'free_personal',
                'status'         => $tenant->status ?? 'active',
                'business_type'  => $tenant->business_type ?? 'retail_mart',
                'business_profile' => \App\Services\PosBusinessProfileService::getProfile($tenant->business_type ?? 'retail_mart'),
                'enabled_modules'=> is_string($tenant->enabled_modules) ? json_decode($tenant->enabled_modules, true) : ($tenant->enabled_modules ?? []),
                'payment_settings' => is_string($tenant->payment_settings ?? null) ? json_decode($tenant->payment_settings, true) : ($tenant->payment_settings ?? null),
            ],
        ]);
    }

    /**
     * Update active tenant organization profile (branding, name, logo, contact).
     * PUT|POST /api/v1/tenants/profile
     */
    public function updateProfile(UpdateTenantProfileRequest $request)
    {
        abort_unless(\App\Services\TenantAccess::isAdmin($request->user()), 403);
        $tenant = $this->resolveTenant($request);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Workspace not found.',
            ], 404);
        }

        $updateData = [];

        if ($request->has('name') && filled($request->name)) {
            $updateData['name'] = $request->name;
        }
        if ($request->has('legal_name')) {
            $updateData['legal_name'] = $request->legal_name;
        }
        if ($request->has('business_type') && filled($request->business_type)) {
            $updateData['business_type'] = $request->business_type;
        }
        if ($request->has('receipt_footer')) {
            $updateData['receipt_footer'] = $request->receipt_footer;
        }
        if ($request->has('phone')) {
            $updateData['phone'] = $request->phone;
        }
        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }
        if ($request->has('address')) {
            $updateData['address'] = $request->address;
        }
        if ($request->has('country')) {
            $updateData['country'] = $request->country;
        }
        if ($request->has('currency')) {
            $updateData['currency'] = $request->currency;
        }
        if ($request->has('payment_settings')) {
            $settingsVal = $request->input('payment_settings');
            if (is_string($settingsVal)) {
                $decoded = json_decode($settingsVal, true);
                $updateData['payment_settings'] = $decoded !== null ? json_encode($decoded) : $settingsVal;
            } else {
                $updateData['payment_settings'] = json_encode($settingsVal);
            }
        }

        // Handle uploaded file or direct logo URL / Base64 Data URL (stored to MinIO S3 WebP)
        if ($request->hasFile('logo')) {
            try {
                /** @var \App\Services\ImageService $imageService */
                $imageService = app(\App\Services\ImageService::class);
                $updateData['logo_url'] = $imageService->storeLogo($request->file('logo'), $tenant->id, $tenant->logo_url ?? null);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            } catch (\Throwable $e) {
                Log::warning('[TenantController] Logo upload failed: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store organization logo.',
                ], 500);
            }
        } elseif ($request->has('logo_url') && !empty($request->logo_url)) {
            $logoUrlInput = (string) $request->logo_url;
            if (!\App\Services\ImageService::isSafeImageUrlOrDataUri($logoUrlInput)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unsupported or unsafe organization logo URL/data URI format.',
                ], 422);
            }
            if (stripos(trim($logoUrlInput), 'data:') === 0) {
                try {
                    /** @var \App\Services\ImageService $imageService */
                    $imageService = app(\App\Services\ImageService::class);
                    $updateData['logo_url'] = $imageService->storeLogo($logoUrlInput, $tenant->id, $tenant->logo_url ?? null);
                } catch (\InvalidArgumentException $e) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ], 422);
                } catch (\Throwable $e) {
                    Log::warning('[TenantController] Base64 logo upload failed: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process organization logo data.',
                    ], 422);
                }
            } else {
                $updateData['logo_url'] = $logoUrlInput;
            }
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = now();
            DB::table('tenants')->where('id', $tenant->id)->update($updateData);
        }

        $updatedTenant = DB::table('tenants')->where('id', $tenant->id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Organization branding & profile updated successfully.',
            'data'    => [
                'id'             => $updatedTenant->id,
                'name'           => $updatedTenant->name,
                'legal_name'     => $updatedTenant->legal_name ?? null,
                'slug'           => $updatedTenant->slug,
                'logo_url'       => $updatedTenant->logo_url ?? null,
                'receipt_footer' => $updatedTenant->receipt_footer ?? null,
                'email'          => $updatedTenant->email ?? null,
                'phone'          => $updatedTenant->phone ?? null,
                'address'        => $updatedTenant->address ?? null,
                'country'        => $updatedTenant->country ?? 'KH',
                'currency'       => $updatedTenant->currency ?? 'USD',
                'client_tier'    => $updatedTenant->client_tier ?? 'free_personal',
                'status'         => $updatedTenant->status ?? 'active',
                'payment_settings' => is_string($updatedTenant->payment_settings ?? null) ? json_decode($updatedTenant->payment_settings, true) : ($updatedTenant->payment_settings ?? null),
            ],
        ]);
    }

    /**
     * Helper to resolve active tenant from request headers, user session, or parameters.
     */
    protected function resolveTenant(Request $request)
    {
        $user = $request->user();
        if ($user && !\App\Services\UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            $tenantId = $user->tenant_id;
            if (!$tenantId && $request->filled('tenant_id')) {
                $requestedId = (int) $request->tenant_id;
                $isPermitted = DB::table('tenant_users')
                    ->where('tenant_id', $requestedId)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->exists();
                if ($isPermitted) {
                    $tenantId = $requestedId;
                }
            }
            if ($request->filled('tenant_id')) {
                abort_unless((int) $request->tenant_id === (int) $tenantId, 403, 'Unauthorized tenant access.');
            }
            abort_unless($tenantId, 403, 'An active workspace membership is required.');
            return DB::table('tenants')->where('id', $tenantId)->first();
        }

        // 1. By direct tenant_id parameter
        if ($request->filled('tenant_id')) {
            $t = DB::table('tenants')->where('id', $request->tenant_id)->first();
            if ($t) return $t;
        }

        // 2. By X-Tenant-Workspace header or org_name parameter
        $orgName = $request->header('X-Tenant-Workspace') ?: $request->query('org_name') ?: $request->input('org_name');
        if ($orgName && $orgName !== 'No Organization Yet! Please Create' && $orgName !== 'Guest Workspace') {
            $t = DB::table('tenants')
                ->where('name', $orgName)
                ->orWhere('slug', Str::slug($orgName))
                ->first();
            if ($t) return $t;
        }

        // 3. By authenticated user's tenant_id
        if ($request->user() && $request->user()->tenant_id) {
            $t = DB::table('tenants')->where('id', $request->user()->tenant_id)->first();
            if ($t) return $t;
        }

        // 4. Fallback to primary enterprise tenant
        return DB::table('tenants')->where('status', '!=', 'suspended')->first();
    }

    /**
     * Get platform subscription plans with dynamic tenant usage counts
     * GET /api/v1/super-admin/subscription-plans
     */
    public function subscriptionPlans()
    {
        $stats = [
            'enterprise_org'  => DB::table('tenants')->where('client_tier', 'enterprise_org')->count(),
            'business_runner' => DB::table('tenants')->where('client_tier', 'business_runner')->count(),
            'free_personal'   => DB::table('tenants')->where('client_tier', 'free_personal')->count(),
        ];

        $plans = [
            [
                'id' => 'modular',
                'tier_code' => 'modular_single',
                'name' => 'A-La-Carte Single Module',
                'priceMonthly' => 19,
                'priceAnnual' => 190,
                'maxOutlets' => 1,
                'maxRegisters' => 2,
                'maxStaff' => 10,
                'modulesIncluded' => ['Select Any 1 Independent Module (POS, Inventory, HRM, CRM, or Finance)'],
                'activeTenantsCount' => 0,
            ],
            [
                'id' => 'starter',
                'tier_code' => 'free_personal',
                'name' => 'Starter Business',
                'priceMonthly' => 29,
                'priceAnnual' => 290,
                'maxOutlets' => 1,
                'maxRegisters' => 2,
                'maxStaff' => 5,
                'modulesIncluded' => ['POS System', 'Basic Inventory'],
                'activeTenantsCount' => $stats['free_personal'],
            ],
            [
                'id' => 'growth',
                'tier_code' => 'business_runner',
                'name' => 'Growth & Multi-Outlet',
                'priceMonthly' => 79,
                'priceAnnual' => 790,
                'popular' => true,
                'maxOutlets' => 5,
                'maxRegisters' => 15,
                'maxStaff' => 25,
                'modulesIncluded' => ['POS System', 'Advanced Inventory', 'Staff & HRM', 'Kitchen KDS', 'Customer CFD'],
                'activeTenantsCount' => $stats['business_runner'],
            ],
            [
                'id' => 'enterprise',
                'tier_code' => 'enterprise_org',
                'name' => 'Enterprise Scale',
                'priceMonthly' => 199,
                'priceAnnual' => 1990,
                'maxOutlets' => 50,
                'maxRegisters' => 200,
                'maxStaff' => 100,
                'modulesIncluded' => ['POS System', 'All 8 Ecosystem Services', 'Custom Domain', 'Dedicated Database', '24/7 SLA Support'],
                'activeTenantsCount' => $stats['enterprise_org'],
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }
}


