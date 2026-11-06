<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     uuid: string|null,
     *     tenant_id: int|null,
     *     tenant_name: string|null,
     *     company_name: string|null,
     *     outlet_uuid: string|null,
     *     name: string,
     *     email: string,
     *     phone: string|null,
     *     avatar_url: string|null,
     *     role: string,
     *     is_active: bool,
     *     email_verified_at: string|null,
     *     is_email_verified: bool,
     *     two_factor_enabled: bool,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $roleSlug = data_get($this->resource, 'role_slug') ?: data_get($this->resource, 'role');
        $roleId = data_get($this->resource, 'role_id');

        $roleRecord = null;
        if ($roleId) {
            $roleRecord = \Illuminate\Support\Facades\DB::table('roles')->where('id', $roleId)->first();
        } elseif ($roleSlug) {
            $roleRecord = \Illuminate\Support\Facades\DB::table('roles')->where('slug', $roleSlug)->first();
        }

        // Resolve parent_role (super_admin, organization, employee)
        $parentRole = $roleRecord?->parent_role;
        if (!$parentRole) {
            if ($roleSlug === 'super_admin') {
                $parentRole = 'super_admin';
            } elseif (in_array($roleSlug, ['organization', 'admin', 'administrator', 'owner'])) {
                $parentRole = 'organization';
            } else {
                $parentRole = 'employee';
            }
        }

        // Resolve granular permissions
        $permissions = [];
        if ($parentRole === 'super_admin' || $parentRole === 'organization') {
            $permissions = ['*'];
        } elseif ($roleRecord) {
            $permissions = \Illuminate\Support\Facades\DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $roleRecord->id)
                ->pluck('permissions.name')
                ->toArray();
        }

        // Map assigned modules for frontline operations
        $assignedModules = [];
        if ($parentRole === 'super_admin' || $parentRole === 'organization') {
            $assignedModules = ['pos', 'inventory', 'finance', 'meetings', 'hrm', 'crm', 'bill-subscription'];
        } else {
            $hasPos = in_array('pos.checkout', $permissions) || str_contains($roleSlug ?? '', 'cashier') || in_array($roleSlug, ['outlet_manager', 'supervisor']);
            $hasInventory = in_array('inventory.view', $permissions) || str_contains($roleSlug ?? '', 'inventory') || str_contains($roleSlug ?? '', 'clerk') || in_array($roleSlug, ['outlet_manager', 'supervisor']);
            $hasFinance = in_array('finance.reconciliation', $permissions) || in_array('finance.expenses', $permissions) || str_contains($roleSlug ?? '', 'accountant');
            $hasMeetings = in_array('meetings.view', $permissions) || in_array('meetings.manage', $permissions) || str_contains($roleSlug ?? '', 'meeting');
            $hasHrm = in_array('hrm.employees.view', $permissions) || in_array('hrm.employees.manage', $permissions) || str_contains($roleSlug ?? '', 'hr');

            foreach ($permissions as $p) {
                if (str_starts_with($p, 'pos.')) $hasPos = true;
                if (str_starts_with($p, 'catalog.')) { $hasPos = true; $hasInventory = true; }
                if (str_starts_with($p, 'inventory.')) $hasInventory = true;
                if (str_starts_with($p, 'finance.')) $hasFinance = true;
                if (str_starts_with($p, 'meetings.')) $hasMeetings = true;
                if (str_starts_with($p, 'hrm.')) $hasHrm = true;
            }

            if ($hasPos) $assignedModules[] = 'pos';
            if ($hasInventory) $assignedModules[] = 'inventory';
            if ($hasFinance) $assignedModules[] = 'finance';
            if ($hasMeetings) $assignedModules[] = 'meetings';
            if ($hasHrm) $assignedModules[] = 'hrm';

            $assignedModules = array_values(array_unique($assignedModules));
        }

        $tenantId = data_get($this->resource, 'tenant_id');
        $tenantName = data_get($this->resource, 'tenant_name') ?: data_get($this->resource, 'company_name');
        if (!$tenantName && $tenantId) {
            $tenantRecord = \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenantId)->first();
            $tenantName = $tenantRecord?->name;
        }

        $id = data_get($this->resource, 'id');
        $uuid = data_get($this->resource, 'uuid');
        $outletUuid = data_get($this->resource, 'outlet_uuid');
        $name = data_get($this->resource, 'name');
        $email = data_get($this->resource, 'email');
        $phone = data_get($this->resource, 'phone');
        $avatarUrl = data_get($this->resource, 'avatar_url');
        $role = data_get($this->resource, 'role');
        $emailVerifiedAt = data_get($this->resource, 'email_verified_at');
        $createdAt = data_get($this->resource, 'created_at');
        $updatedAt = data_get($this->resource, 'updated_at');

        return [
            'id' => (int) ($id ?? 1),
            'uuid' => $uuid !== null ? (string) $uuid : null,
            'tenant_id' => $tenantId !== null ? (int) $tenantId : null,
            'tenant_name' => $tenantName !== null ? (string) $tenantName : null,
            'company_name' => $tenantName !== null ? (string) $tenantName : null,
            'outlet_uuid' => $outletUuid !== null ? (string) $outletUuid : null,
            'name' => (string) ($name ?? 'Staff User'),
            'email' => (string) ($email ?? 'staff@sunnycafe.kh'),
            'phone' => $phone !== null ? (string) $phone : null,
            'avatar_url' => $avatarUrl !== null ? (string) $avatarUrl : null,
            'role' => (string) ($role ?? 'employee'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'email_verified_at' => $emailVerifiedAt !== null ? (string) $emailVerifiedAt : null,
            'is_email_verified' => !is_null($emailVerifiedAt),
            'two_factor_enabled' => (bool) data_get($this->resource, 'two_factor_enabled', false),
            'created_at' => $createdAt !== null ? (string) $createdAt : null,
            'updated_at' => $updatedAt !== null ? (string) $updatedAt : null,
        ];
    }
}
