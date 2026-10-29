<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AttachUserRequest;
use App\Http\Requests\User\InviteUserRequest;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\VerifyPinRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * [BD-0002] Multi-Tenant User Management & Role Assignment Controller.
 *
 * @ticket BD-0002
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/UserController.php
 * @related-model App\Models\User, App\Models\UserRole, App\Models\Outlet
 * @entry-point GET /api/v1/users, POST /api/v1/users, PUT /api/v1/users/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Queries users joined with user_roles pivot and outlets table by outlet_uuid.
 * 2. Enforces multi-tenant tenant_id scoping for organization admins.
 * 3. Provisions new staff with hashed password, personal 4-digit PIN, and assigned role.
 * 4. Updates roles seamlessly via the user_roles pivot table.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $role = $request->query('role');
        $search = $request->query('q') ?? $request->query('search');
        $scope = $request->query('scope', 'staff');

        $query = DB::table('users as u')
            ->leftJoin('tenant_users as tu', function ($join) {
                $join->on('u.id', '=', 'tu.user_id');
            })
            ->leftJoin('tenant_user_outlets as tuo', function ($join) {
                $join->on('tu.id', '=', 'tuo.tenant_user_id');
            })
            ->leftJoin('outlets as o', function ($join) {
                $join->on('tuo.outlet_uuid', '=', 'o.uuid');
            })
            ->leftJoin('user_roles as ur', function ($join) {
                $join->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true);
            })
            ->leftJoin('roles as r', function ($join) {
                $join->on('ur.role_id', '=', 'r.id');
            });

        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            $query->where('tu.tenant_id', $currentUser->tenant_id);
        }
        if (!empty($role) && $role !== 'all') {
            $query->where(function ($rq) use ($role) {
                $rq->where('r.slug', $role);
            });
        }
        if ($scope !== 'all' && !$request->has('include_customers')) {
            $query->where(function ($q) {
                $q->whereNull('r.slug')->orWhereNotIn('r.slug', ['customer', 'client', 'guest']);
            });
        }
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        $users = $query->select(
            'u.id', 'u.uuid',
            DB::raw('COALESCE(tu.tenant_id, NULL) as tenant_id'),
            'u.name', 'u.email', 'u.phone',
            DB::raw('COALESCE(r.slug, "user") as role'),
            DB::raw('r.id as role_id'),
            DB::raw('r.name as role_name'),
            DB::raw('r.slug as role_slug'),
            DB::raw('COALESCE(o.id, NULL) as outlet_id'),
            DB::raw('COALESCE(tuo.outlet_uuid, NULL) as outlet_uuid'),
            DB::raw('NULL as pin_code'),
            'u.is_active',
            'u.created_at', 'o.name as outlet_name'
        )->distinct()->orderBy('u.created_at', 'desc')->get();

        return response()->json(['status' => 'success', 'data' => UserResource::collection($users)]);
    }

    public function permissions()
    {
        return response()->json(['status' => 'success', 'data' => [
            'super_admin' => ['name' => 'Super Admin', 'capabilities' => ['sales.create', 'sales.discount', 'sales.void', 'sales.refund', 'catalog.manage', 'inventory.adjust', 'reports.view', 'users.manage', 'settings.manage', 'meetings.manage']],
            'organization' => ['name' => 'Organization Admin', 'capabilities' => ['sales.create', 'sales.discount', 'sales.void', 'sales.refund', 'catalog.manage', 'inventory.adjust', 'reports.view', 'users.manage', 'meetings.manage', 'hrm.manage']],
            'employee' => ['name' => 'Employees', 'capabilities' => ['catalog.view', 'meetings.view', 'profile.manage']],
            'cashier' => ['name' => 'Cashier', 'capabilities' => ['sales.create', 'receipts.print', 'shifts.manage']],
            'inventory_clerk' => ['name' => 'Stock Clerk', 'capabilities' => ['catalog.manage', 'inventory.adjust', 'inventory.transfer', 'inventory.count']],
            'accountant' => ['name' => 'Finance Accountant', 'capabilities' => ['reports.view', 'reconciliation.manage', 'expenses.view']],
            'meeting_organizer' => ['name' => 'Meeting Coordinator', 'capabilities' => ['meetings.view', 'meetings.manage', 'meetings.rooms', 'meetings.minutes']],
            'outlet_manager' => ['name' => 'Store Manager', 'capabilities' => ['sales.create', 'sales.discount', 'sales.void', 'catalog.view', 'inventory.adjust', 'reports.view']],
        ]]);
    }

    public static function getAllowedRoles(): array
    {
        try {
            $dynamic = DB::table('roles')->pluck('slug')->toArray();
        } catch (\Throwable $e) {
            $dynamic = [];
        }
        return array_unique(array_merge([
            'super_admin', 'organization', 'employee', 'admin', 'administrator', 'outlet_manager',
            'cashier', 'inventory_clerk', 'accountant', 'meeting_organizer', 'supervisor', 'user'
        ], $dynamic));
    }

    private function resolveRoleAndId($role, $roleId = null, $currentUser = null): array
    {
        if ($role === 'super_admin') {
            if (!$currentUser || $currentUser->role !== 'super_admin') {
                // Non-super_admin cannot grant super_admin privileges
                $role = 'employee';
            } else {
                $rId = $roleId ?? DB::table('roles')->where('slug', 'super_admin')->value('id');
                return ['super_admin', $rId];
            }
        }
        if (in_array($role, ['admin', 'organization', 'administrator', 'owner'])) {
            $rId = $roleId ?? DB::table('roles')->whereIn('slug', ['organization', 'admin'])->value('id');
            return ['organization', $rId];
        }
        $rId = $roleId ?: (DB::table('roles')->where('slug', $role)->value('id') ?? DB::table('roles')->where('slug', 'employee')->value('id'));
        return ['employee', $rId];
    }

    private function checkQuota($currentUser, $tenantId): ?string
    {
        if ($currentUser && $currentUser->role !== 'super_admin' && $tenantId) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->first();
            $maxUsers = $tenant->max_users ?? 50;
            $count = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('status', 'active')->count();
            if ($count >= $maxUsers) {
                return "User limit reached ({$count}/{$maxUsers}) for your subscription tier.";
            }
        }
        return null;
    }

    public function lookup(Request $request)
    {
        $q = trim($request->query('q', ''));
        if (strlen($q) < 2) return response()->json(['status' => 'success', 'data' => []]);

        $currentUser = $request->user();
        $currentTenantId = $currentUser?->tenant_id;

        $users = DB::table('users as u')
            ->leftJoin('tenant_users as tu', function ($j) {
                $j->on('u.id', '=', 'tu.user_id');
            })
            ->leftJoin('tenants as t', 'tu.tenant_id', '=', 't.id')
            ->where(function ($w) use ($q) {
                $w->where('u.email', 'like', "%{$q}%")->orWhere('u.name', 'like', "%{$q}%");
            })
            ->select('u.id', 'u.name', 'u.email', DB::raw('COALESCE(tu.tenant_id, NULL) as tenant_id'), 't.name as tenant_name')
            ->limit(10)->get()
            ->map(function ($u) use ($currentTenantId) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => 'employee',
                    'is_already_member' => ($u->tenant_id && $u->tenant_id == $currentTenantId),
                    'tenant_name' => $u->tenant_name,
                ];
            });

        return response()->json(['status' => 'success', 'data' => UserResource::collection($users)]);
    }

    public function attach(AttachUserRequest $request)
    {
        $validated = $request->validated();

        $query = DB::table('users');
        if (!empty($validated['user_id'])) {
            $query->where('id', $validated['user_id']);
        } elseif (!empty($validated['email'])) {
            $query->where('email', $validated['email']);
        } else {
            return response()->json(['status' => 'error', 'message' => 'User ID or email is required.'], 422);
        }

        $targetUser = $query->first();
        if (!$targetUser) return response()->json(['status' => 'error', 'message' => 'User account not found.'], 404);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $isMember = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $targetUser->id)
            ->exists();
        if ($isMember) {
            return response()->json(['status' => 'error', 'message' => 'This user is already a member of your workspace.'], 422);
        }

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        [$parentRole, $subRoleId] = $this->resolveRoleAndId($validated['role'], $validated['role_id'] ?? null, $currentUser);

        $outletUuid = null;
        if (!empty($validated['outlet_id'])) {
            $outletUuid = \App\Models\Outlet::where('id', $validated['outlet_id'])->orWhere('uuid', $validated['outlet_id'])->value('uuid');
        }

        DB::table('users')->where('id', $targetUser->id)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('tenant_users')->updateOrInsert(
            ['tenant_id' => $tenantId, 'user_id' => $targetUser->id],
            [
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $tuId = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $targetUser->id)->value('id');

        if ($outletUuid && $tuId) {
            DB::table('tenant_user_outlets')->updateOrInsert(
                ['tenant_user_id' => $tuId, 'outlet_uuid' => $outletUuid],
                ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if (!empty($validated['pin_code'])) {
            DB::table('user_pos_pins')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $targetUser->id],
                [
                    'pin_hash' => hash('sha256', (string) $validated['pin_code']),
                    'failed_attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if ($subRoleId) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $targetUser->id, 'role_id' => $subRoleId],
                [
                    'tenant_id' => $tenantId,
                    'is_active' => true,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => "{$targetUser->name} ({$targetUser->email}) is now assigned to your workspace!",
            'data' => ['id' => $targetUser->id],
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        [$parentRole, $subRoleId] = $this->resolveRoleAndId($validated['role'], $validated['role_id'] ?? null, $currentUser);

        $outletUuid = null;
        if (!empty($validated['outlet_id'])) {
            $outletUuid = \App\Models\Outlet::where('id', $validated['outlet_id'])->orWhere('uuid', $validated['outlet_id'])->value('uuid');
        }

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($tenantId) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'active',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tuId = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('id');

            if ($outletUuid && $tuId) {
                DB::table('tenant_user_outlets')->insert([
                    'tenant_user_id' => $tuId,
                    'outlet_uuid' => $outletUuid,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!empty($validated['pin_code'])) {
                DB::table('user_pos_pins')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'pin_hash' => hash('sha256', (string) $validated['pin_code']),
                    'failed_attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($subRoleId) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $userId, 'role_id' => $subRoleId],
                [
                    'tenant_id' => $tenantId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['status' => 'success', 'message' => 'User account created successfully.', 'data' => ['id' => $userId]], 201);
    }

    public function invite(InviteUserRequest $request)
    {
        $validated = $request->validated();

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $existing = DB::table('users')->where('email', $validated['email'])->first();
        if ($existing && $tenantId) {
            $isAlreadyMember = DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $existing->id)
                ->where('status', 'active')
                ->exists();
            if ($isAlreadyMember) {
                return response()->json(['status' => 'error', 'message' => 'A user with this email already belongs to your workspace.'], 422);
            }
        }

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        // Prevent non-super_admin from inviting super_admin role
        $roleToInvite = $validated['role'];
        if ($roleToInvite === 'super_admin' && (!$currentUser || $currentUser->role !== 'super_admin')) {
            $roleToInvite = 'employee';
        }

        $inviteToken = Str::random(48);
        DB::table('user_invitations')->where('email', $validated['email'])->whereNull('accepted_at')->delete();

        $inviteId = DB::table('user_invitations')->insertGetId([
            'tenant_id' => $tenantId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'email' => $validated['email'],
            'role' => $roleToInvite,
            'token' => $inviteToken,
            'invited_by' => $currentUser?->id,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff invitation link generated successfully.',
            'data' => [
                'id' => $inviteId,
                'email' => $validated['email'],
                'role' => $roleToInvite,
                'token' => $inviteToken,
                'invite_url' => "/accept-invite?token={$inviteToken}",
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ],
        ], 201);
    }

    public function update(UpdateUserRequest $request, $id)
    {
        $currentUser = $request->user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            return response()->json(['status' => 'error', 'message' => 'User account not found.'], 404);
        }

        // Enforce tenant boundary & protect super admin accounts
        if ($currentUser && $currentUser->role !== 'super_admin') {
            if ($targetUser->tenant_id != $currentUser->tenant_id || $targetUser->role === 'super_admin') {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized access to user account.'], 403);
            }
        }

        $validated = $request->validated();

        $updateData = [];
        foreach (['name', 'email', 'phone', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) $updateData[$field] = $validated[$field];
        }

        if (array_key_exists('pin_code', $validated)) {
            $effectiveTenantId = $targetUser->tenant_id ?? $currentUser?->tenant_id ?? 1;
            DB::table('user_pos_pins')->updateOrInsert(
                ['tenant_id' => $effectiveTenantId, 'user_id' => $id],
                [
                    'pin_hash' => hash('sha256', (string) $validated['pin_code']),
                    'failed_attempts' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (!empty($validated['outlet_id'])) {
            $outletUuid = \App\Models\Outlet::where('id', $validated['outlet_id'])->orWhere('uuid', $validated['outlet_id'])->value('uuid');
            if ($outletUuid) {
                $effectiveTenantId = $targetUser->tenant_id ?? $currentUser?->tenant_id ?? 1;
                $tuId = DB::table('tenant_users')->where('tenant_id', $effectiveTenantId)->where('user_id', $id)->value('id');
                if ($tuId) {
                    DB::table('tenant_user_outlets')->updateOrInsert(
                        ['tenant_user_id' => $tuId, 'outlet_uuid' => $outletUuid],
                        ['is_primary' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }

        if (!empty($validated['role']) || !empty($validated['role_id'])) {
            [$pRole, $rId] = $this->resolveRoleAndId($validated['role'] ?? 'employee', $validated['role_id'] ?? null, $currentUser);
            if ($rId) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $id, 'role_id' => $rId],
                    [
                        'tenant_id' => $currentUser?->tenant_id,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]
                );
            }
        }

        if (!empty($validated['password'])) $updateData['password'] = Hash::make($validated['password']);
        $updateData['updated_at'] = now();

        DB::table('users')->where('id', $id)->update($updateData);
        return response()->json(['status' => 'success', 'message' => 'User account updated successfully.']);
    }

    public function resetPassword(ResetPasswordRequest $request, $id)
    {
        $currentUser = $request->user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            return response()->json(['status' => 'error', 'message' => 'User account not found.'], 404);
        }

        if ($currentUser && $currentUser->role !== 'super_admin') {
            if ($targetUser->tenant_id != $currentUser->tenant_id || $targetUser->role === 'super_admin') {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized access to user account.'], 403);
            }
        }

        $validated = $request->validated();
        DB::table('users')->where('id', $id)->update(['password' => Hash::make($validated['new_password']), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'User password reset successfully.']);
    }

    public function verifyPin(VerifyPinRequest $request)
    {
        $validated = $request->validated();
        $currentUser = $request->user();

        $pin = $validated['pin_code'];
        $pinHash = hash('sha256', $pin);

        $query = DB::table('users as u')
            ->join('user_pos_pins as upp', 'upp.user_id', '=', 'u.id')
            ->join('tenant_users as tu', 'tu.user_id', '=', 'u.id')
            ->leftJoin('user_roles as ur', function ($j) {
                $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true);
            })
            ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
            ->where(function ($w) use ($pin, $pinHash) {
                $w->where('upp.pin_hash', $pinHash)->orWhere('upp.pin_hash', $pin);
            })
            ->where('u.is_active', true)
            ->where(function ($q) {
                $allowed = ['super_admin', 'organization', 'admin', 'outlet_manager', 'supervisor'];
                $q->whereIn('r.slug', $allowed);
            });

        // Enforce tenant boundary: PIN must belong to current tenant (unless super_admin)
        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            $query->where('tu.tenant_id', $currentUser->tenant_id);
        }

        $supervisor = $query->select('u.id', 'u.name', DB::raw('COALESCE(r.slug, "employee") as role'))
            ->first();

        if (!$supervisor) {
            return response()->json(['status' => 'error', 'message' => 'Invalid authorization PIN code or unauthorized role.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'PIN authorized successfully.',
            'data' => ['user_id' => $supervisor->id, 'user_name' => $supervisor->name, 'role' => $supervisor->role],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        $targetUser = User::find($id);
        if (!$targetUser) {
            return response()->json(['status' => 'error', 'message' => 'User account not found.'], 404);
        }

        if ($currentUser && $currentUser->role !== 'super_admin') {
            if ($targetUser->tenant_id != $currentUser->tenant_id || $targetUser->role === 'super_admin') {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized access to user account.'], 403);
            }
        }

        DB::table('users')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'User account deactivated.']);
    }
}
