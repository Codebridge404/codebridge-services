<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
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

        return [
            'id' => data_get($this->resource, 'id'),
            'uuid' => data_get($this->resource, 'uuid'),
            'tenant_id' => data_get($this->resource, 'tenant_id'),
            'outlet_id' => data_get($this->resource, 'outlet_id'),
            'outlet_uuid' => data_get($this->resource, 'outlet_uuid'),
            'name' => data_get($this->resource, 'name'),
            'email' => data_get($this->resource, 'email'),
            'phone' => data_get($this->resource, 'phone'),
            'avatar_url' => data_get($this->resource, 'avatar_url'),
            'role' => data_get($this->resource, 'role'),
            'role_id' => data_get($this->resource, 'role_id') ?? $roleRecord?->id,
            'role_name' => $roleRecord?->name ?? data_get($this->resource, 'role_name'),
            'role_slug' => $roleRecord?->slug ?? $roleSlug,
            'parent_role' => $parentRole,
            'permissions' => $permissions,
            'assigned_modules' => $assignedModules,
            'pin_code' => data_get($this->resource, 'pin_code'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'two_factor_enabled' => (bool) data_get($this->resource, 'two_factor_enabled', false),
            'outlet_name' => data_get($this->resource, 'outlet_name'),
            'tenant_name' => data_get($this->resource, 'tenant_name'),
            'company_name' => data_get($this->resource, 'company_name'),
            'tenant_logo_url' => data_get($this->resource, 'tenant_logo_url'),
            'logo_url' => data_get($this->resource, 'logo_url'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
