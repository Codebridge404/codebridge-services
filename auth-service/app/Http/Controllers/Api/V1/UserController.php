<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $role = $request->query('role');
        $search = $request->query('q') ?? $request->query('search');
        $scope = $request->query('scope', 'staff');

        $query = DB::table('users as u')
            ->leftJoin('outlets as o', 'u.outlet_id', '=', 'o.id')
            ->leftJoin('roles as r', 'u.role_id', '=', 'r.id');

        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            $query->where('u.tenant_id', $currentUser->tenant_id);
        }
        if (!empty($role) && $role !== 'all') {
            $query->where(function ($rq) use ($role) {
                $rq->where('u.role', $role)->orWhere('r.slug', $role);
            });
        }
        if ($scope !== 'all' && !$request->has('include_customers')) {
            $query->whereNotIn('u.role', ['customer', 'client', 'guest']);
        }
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        $users = $query->select(
            'u.id', 'u.tenant_id', 'u.name', 'u.email', 'u.phone',
            'u.role', 'u.role_id', 'r.name as role_name', 'r.slug as role_slug',
            'u.outlet_id', 'u.pin_code', 'u.is_active',
            'u.created_at', 'o.name as outlet_name'
        )->orderBy('u.created_at', 'desc')->get();

        return response()->json(['status' => 'success', 'data' => $users]);
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

    private function getAllowedRoles(): array
    {
        $dynamic = DB::table('roles')->pluck('slug')->toArray();
        return array_unique(array_merge([
            'super_admin', 'organization', 'employee', 'admin', 'administrator', 'outlet_manager',
            'cashier', 'inventory_clerk', 'accountant', 'meeting_organizer', 'supervisor', 'user'
        ], $dynamic));
    }

    private function resolveRoleAndId($role, $roleId = null): array
    {
        if (in_array($role, ['admin', 'organization', 'administrator', 'owner'])) {
            $rId = $roleId ?? DB::table('roles')->whereIn('slug', ['organization', 'admin'])->value('id');
            return ['organization', $rId];
        }
        if ($role === 'super_admin') {
            $rId = $roleId ?? DB::table('roles')->where('slug', 'super_admin')->value('id');
            return ['super_admin', $rId];
        }
        $rId = $roleId ?: (DB::table('roles')->where('slug', $role)->value('id') ?? DB::table('roles')->where('slug', 'employee')->value('id'));
        return ['employee', $rId];
    }

    private function checkQuota($currentUser, $tenantId): ?string
    {
        if ($currentUser && $currentUser->role !== 'super_admin' && $tenantId) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->first();
            $maxUsers = $tenant->max_users ?? 50;
            $count = DB::table('users')->where('tenant_id', $tenantId)->count();
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
            ->leftJoin('tenants as t', 'u.tenant_id', '=', 't.id')
            ->where(function ($w) use ($q) {
                $w->where('u.email', 'like', "%{$q}%")->orWhere('u.name', 'like', "%{$q}%");
            })
            ->select('u.id', 'u.name', 'u.email', 'u.role', 'u.tenant_id', 't.name as tenant_name')
            ->limit(10)->get()
            ->map(function ($u) use ($currentTenantId) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'is_already_member' => ($u->tenant_id && $u->tenant_id == $currentTenantId),
                    'tenant_name' => $u->tenant_name,
                ];
            });

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function attach(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'email' => 'nullable|email',
            'role' => ['required', 'string', Rule::in($this->getAllowedRoles())],
            'role_id' => 'nullable|integer',
            'outlet_id' => 'nullable|string',
            'pin_code' => 'nullable|string|max:10',
        ]);

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

        if ($targetUser->tenant_id && $targetUser->tenant_id == $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'This user is already a member of your workspace.'], 422);
        }

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        [$parentRole, $subRoleId] = $this->resolveRoleAndId($validated['role'], $validated['role_id'] ?? null);

        DB::table('users')->where('id', $targetUser->id)->update([
            'tenant_id' => $tenantId,
            'role' => $parentRole,
            'role_id' => $subRoleId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'pin_code' => $validated['pin_code'] ?? null,
            'is_active' => true,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "{$targetUser->name} ({$targetUser->email}) is now assigned to your workspace!",
            'data' => ['id' => $targetUser->id],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => ['required', 'string', Rule::in($this->getAllowedRoles())],
            'role_id' => 'nullable|integer',
            'outlet_id' => 'nullable|string',
            'pin_code' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:30',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        [$parentRole, $subRoleId] = $this->resolveRoleAndId($validated['role'], $validated['role_id'] ?? null);

        $userId = DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'pin_code' => $validated['pin_code'] ?? null,
            'role' => $parentRole,
            'role_id' => $subRoleId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'User account created successfully.', 'data' => ['id' => $userId]], 201);
    }

    public function invite(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', 'string', Rule::in($this->getAllowedRoles())],
            'outlet_id' => 'nullable|string',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $existing = DB::table('users')->where('email', $validated['email'])->first();
        if ($existing && $existing->tenant_id && $existing->tenant_id == $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'A user with this email already belongs to your workspace.'], 422);
        }

        if ($quotaErr = $this->checkQuota($currentUser, $tenantId)) {
            return response()->json(['status' => 'error', 'code' => 'QUOTA_EXCEEDED', 'message' => $quotaErr], 403);
        }

        $inviteToken = Str::random(48);
        DB::table('user_invitations')->where('email', $validated['email'])->whereNull('accepted_at')->delete();

        $inviteId = DB::table('user_invitations')->insertGetId([
            'tenant_id' => $tenantId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'email' => $validated['email'],
            'role' => $validated['role'],
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
                'role' => $validated['role'],
                'token' => $inviteToken,
                'invite_url' => "/accept-invite?token={$inviteToken}",
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'phone' => 'sometimes|nullable|string|max:30',
            'password' => 'nullable|string|min:6',
            'role' => ['sometimes', 'string', Rule::in($this->getAllowedRoles())],
            'role_id' => 'sometimes|nullable|integer',
            'outlet_id' => 'sometimes|nullable|string',
            'pin_code' => 'sometimes|nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $updateData = [];
        foreach (['name', 'email', 'phone', 'role', 'role_id', 'outlet_id', 'pin_code', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) $updateData[$field] = $validated[$field];
        }
        if (!empty($updateData['role']) || !empty($updateData['role_id'])) {
            [$pRole, $rId] = $this->resolveRoleAndId($updateData['role'] ?? 'employee', $updateData['role_id'] ?? null);
            $updateData['role'] = $pRole;
            if ($rId) $updateData['role_id'] = $rId;
        }
        if (!empty($validated['password'])) $updateData['password'] = Hash::make($validated['password']);
        $updateData['updated_at'] = now();

        DB::table('users')->where('id', $id)->update($updateData);
        return response()->json(['status' => 'success', 'message' => 'User account updated successfully.']);
    }

    public function resetPassword(Request $request, $id)
    {
        $validated = $request->validate(['new_password' => 'required|string|min:6']);
        DB::table('users')->where('id', $id)->update(['password' => Hash::make($validated['new_password']), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'User password reset successfully.']);
    }

    public function verifyPin(Request $request)
    {
        $validated = $request->validate(['pin_code' => 'required|string']);
        $supervisor = DB::table('users')->where('pin_code', $validated['pin_code'])->where('is_active', true)
            ->whereIn('role', ['super_admin', 'organization', 'admin', 'outlet_manager', 'supervisor'])->first();

        if (!$supervisor) {
            return response()->json(['status' => 'error', 'message' => 'Invalid authorization PIN code or unauthorized role.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'PIN authorized successfully.',
            'data' => ['user_id' => $supervisor->id, 'user_name' => $supervisor->name, 'role' => $supervisor->role],
        ]);
    }

    public function destroy($id)
    {
        DB::table('users')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'User account deactivated.']);
    }
}
