<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateModulesRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0080] Per-Tenant Module Entitlements & Subscription Tiers Controller
 *
 * @ticket BD-0080
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/TenantModuleController.php
 * @related-model DB table tenant_modules, App\Models\Tenant
 * @entry-point TenantModuleController::index(), updateModule()
 * @routes
 *  - GET /api/v1/tenants/{tenant_id}/modules
 *  - PUT /api/v1/tenants/{tenant_id}/modules
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Subscription Verification): Inspects tenant subscription plan tier (Basic, Professional, Enterprise).
 * Step 2 (Module Entitlement Resolution): Queries tenant_modules table to verify active feature licenses.
 * Step 3 (Add-on Module Provisioning): Activates premium modules (KDS, Advanced Loyalty, Table Reservations).
 * Step 4 (Feature Gatekeeper Enforcement): Enforces API route access blocking if requested module is not licensed.
 * Step 5 (Expiration & Grace Period Handling): Manages automated module suspension upon subscription expiration.
 */
class TenantModuleController extends Controller
{
    /**
     * Get active module licenses for a tenant.
     * GET /api/v1/tenants/modules
     */
    public function index(Request $request)
    {
        $tenantId = $request->query('tenant_id');
        $orgName = $request->query('org_name');
        $authUser = $request->user();

        // Standalone user without tenant or unauthenticated
        if (!$authUser) {
            return response()->json([
                'success' => true,
                'modules' => [],
            ]);
        }

        $query = DB::table('tenants');

        if ($authUser->role === 'super_admin') {
            if ($tenantId) {
                $query->where('id', $tenantId);
            } elseif ($orgName) {
                $query->where('name', $orgName);
            } elseif ($authUser->tenant_id) {
                $query->where('id', $authUser->tenant_id);
            } else {
                return response()->json([
                    'success' => true,
                    'modules' => [],
                ]);
            }
        } else {
            // Non-super-admin is STRICTLY scoped to their own tenant
            if (!$authUser->tenant_id) {
                return response()->json([
                    'success'     => true,
                    'tenant_id'   => null,
                    'tenant_name' => null,
                    'modules'     => [],
                ]);
            }
            $query->where('id', $authUser->tenant_id);
        }

        $tenant = $query->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'modules' => [],
                'message' => 'Tenant not found.',
            ], 404);
        }

        $modules = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('tenant_modules')) {
            $modules = DB::table('tenant_modules')
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->pluck('module_id')
                ->toArray();
        }

        if (empty($modules)) {
            $rawModules = $tenant->enabled_modules;
            if (is_string($rawModules)) {
                $rawModules = json_decode($rawModules, true);
            }
            if (is_array($rawModules)) {
                $modules = $rawModules;
            }
        }

        // Expand with canonical aliases so frontend checks pass seamlessly
        $expanded = [];
        $reverseAliasMap = [
            'pos-management'       => ['pos', 'pos-management'],
            'inventory-suite'      => ['inventory', 'inventory-suite'],
            'financial-management' => ['finance', 'financial-management'],
            'staff-hrm'            => ['hrm', 'staff-hrm'],
            'crm-loyalty'          => ['crm', 'crm-loyalty'],
            'meeting-management'   => ['meetings', 'meeting-management'],
            'shop-storefront'      => ['shop', 'shop-storefront'],
            'bill-subscription'    => ['bill-subscription'],
        ];

        foreach ($modules as $mod) {
            if (isset($reverseAliasMap[$mod])) {
                foreach ($reverseAliasMap[$mod] as $alias) {
                    $expanded[] = $alias;
                }
            } else {
                $expanded[] = $mod;
            }
        }

        return response()->json([
            'success'     => true,
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => array_values(array_unique($expanded)),
        ]);
    }

    /**
     * Update module license entitlements for a tenant.
     * PUT /api/v1/tenants/modules
     */
    public function update(UpdateModulesRequest $request)
    {
        abort_unless(\App\Services\TenantAccess::isAdmin($request->user()), 403);

        $tenantId = $request->input('tenant_id');
        $orgName = $request->input('org_name');
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $query = DB::table('tenants');

        if ($authUser->role === 'super_admin') {
            if ($tenantId) {
                $query->where('id', $tenantId);
            } elseif ($orgName) {
                $query->where('name', $orgName);
            } elseif ($authUser->tenant_id) {
                $query->where('id', $authUser->tenant_id);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide a valid tenant_id or org_name.',
                ], 422);
            }
        } else {
            if (!$authUser->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active organization to update modules.',
                ], 403);
            }
            $query->where('id', $authUser->tenant_id);
        }

        $tenant = $query->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found to update module entitlements.',
            ], 404);
        }

        $rawModules = (array) $request->input('modules', []);

        // Canonical alias mapping to valid platform_modules IDs
        $aliasMap = [
            'pos'                  => 'pos-management',
            'pos-terminal'         => 'pos-management',
            'pos-management'       => 'pos-management',
            'kds'                  => 'pos-management',
            'cfd'                  => 'pos-management',
            'inventory'            => 'inventory-suite',
            'inventory-suite'      => 'inventory-suite',
            'operations-service'   => 'inventory-suite',
            'finance'              => 'financial-management',
            'financial-management' => 'financial-management',
            'accounting'           => 'financial-management',
            'hrm'                  => 'staff-hrm',
            'staff-hrm'            => 'staff-hrm',
            'crm'                  => 'crm-loyalty',
            'crm-loyalty'          => 'crm-loyalty',
            'meetings'             => 'meeting-management',
            'meeting'              => 'meeting-management',
            'meeting-management'   => 'meeting-management',
            'mms'                  => 'meeting-management',
            'shop'                 => 'shop-storefront',
            'shop-storefront'      => 'shop-storefront',
            'bill-subscription'    => 'bill-subscription',
            'subscription'         => 'bill-subscription',
        ];

        $validPlatformIds = DB::table('platform_modules')->where('is_active', true)->pluck('id')->all();

        $modules = [];
        foreach ($rawModules as $mod) {
            $resolved = $aliasMap[$mod] ?? $mod;
            if (in_array($resolved, $validPlatformIds, true)) {
                $modules[] = $resolved;
            }
        }
        $modules = array_values(array_unique($modules));

        if ($authUser->role !== 'super_admin') {
            $subscription = DB::table('tenant_subscriptions')
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) {
                    $q->where('status', 'active')
                      ->orWhere('status', 'pending')
                      ->orWhere('status', 'trial');
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('id')
                ->first();

            $planModuleCount = 0;
            if ($subscription?->plan_id) {
                $planModuleCount = DB::table('subscription_plan_modules')->where('plan_id', $subscription->plan_id)->count();
            }

            // Only enforce restriction if subscription plan explicitly defines restricted modules AND tenant is not on trial
            $isTrial = ($tenant->status === 'trial') || ($tenant->trial_ends_at && \Carbon\Carbon::parse($tenant->trial_ends_at)->isFuture());
            if (!$isTrial && $planModuleCount > 0) {
                $allowed = DB::table('subscription_plan_modules')->where('plan_id', $subscription->plan_id)->pluck('module_id')->all();
                $allowed = array_merge($allowed, DB::table('platform_modules')->where('is_core', true)->where('is_active', true)->pluck('id')->all());
                abort_if(array_diff($modules, $allowed), 403, 'The subscription does not include these modules.');
            }
        }

        DB::transaction(function () use ($tenant, $modules, $authUser) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'enabled_modules' => json_encode($modules),
                'updated_at'      => now(),
            ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('tenant_modules')) {
                DB::table('tenant_modules')->where('tenant_id', $tenant->id)->update(['is_active' => false]);
                foreach ($modules as $modId) {
                    DB::table('tenant_modules')->updateOrInsert(
                        ['tenant_id' => $tenant->id, 'module_id' => $modId],
                        [
                            'is_active'    => true,
                            'activated_by' => $authUser->id,
                            'activated_at' => now(),
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]
                    );
                }
            }
        });

        // Record audit event
        if ($authUser && class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $authUser->id,
                $authUser->name,
                'update_tenant_module_licenses',
                'tenants',
                $request->ip(),
                [
                    'tenant_id'   => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'modules'     => $modules,
                ]
            );
        }

        // Expand for frontend response
        $expanded = [];
        $reverseAliasMap = [
            'pos-management'       => ['pos', 'pos-management'],
            'inventory-suite'      => ['inventory', 'inventory-suite'],
            'financial-management' => ['finance', 'financial-management'],
            'staff-hrm'            => ['hrm', 'staff-hrm'],
            'crm-loyalty'          => ['crm', 'crm-loyalty'],
            'meeting-management'   => ['meetings', 'meeting-management'],
            'shop-storefront'      => ['shop', 'shop-storefront'],
            'bill-subscription'    => ['bill-subscription'],
        ];

        foreach ($modules as $mod) {
            if (isset($reverseAliasMap[$mod])) {
                foreach ($reverseAliasMap[$mod] as $alias) {
                    $expanded[] = $alias;
                }
            } else {
                $expanded[] = $mod;
            }
        }

        return response()->json([
            'success'     => true,
            'message'     => "Successfully updated module licenses for {$tenant->name}.",
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => array_values(array_unique($expanded)),
        ]);
    }
}
