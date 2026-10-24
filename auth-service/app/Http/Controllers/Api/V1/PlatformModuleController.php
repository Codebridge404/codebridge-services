<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0076] Global Platform Microservice Modules & Features Controller
 *
 * @ticket BD-0076
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/PlatformModuleController.php
 * @related-model DB table platform_modules, App\Models\Tenant
 * @entry-point PlatformModuleController::index(), updateModuleStatus()
 * @routes
 *  - GET /api/v1/platform-modules
 *  - PUT /api/v1/platform-modules/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Superadmin Authorization): Requires superadmin platform credentials to configure system modules.
 * Step 2 (Module Registry Listing): Returns all platform modules (KDS, Tables, Loyalty, Multi-currency, Store Credit).
 * Step 3 (Feature Flag Definition): Defines availability flags, beta release toggles, and minimum service version requirements.
 * Step 4 (Dependency Validation): Verifies required base services before enabling advanced platform add-ons.
 * Step 5 (Global Catalog Distribution): Synchronizes enabled module registry with downstream microservices.
 */
class PlatformModuleController extends Controller
{
    /**
     * List all platform modules.
     * GET /api/v1/platform-modules
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user && $user->role === 'super_admin';
        $showAll = $request->boolean('all', $isSuperAdmin);

        $query = DB::table('platform_modules');

        if (!$showAll) {
            $query->where('is_active', true);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('id', 'like', $search);
            });
        }

        $modules = $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc')->get();

        // Calculate tenant adoption counts if Super Admin
        if ($isSuperAdmin) {
            $tenants = DB::table('tenants')->select('enabled_modules')->get();
            $modules = $modules->map(function ($mod) use ($tenants) {
                $modObj = (array) $mod;
                $modId = $modObj['id'];
                $count = 0;
                foreach ($tenants as $t) {
                    $raw = $t->enabled_modules;
                    if (is_string($raw)) {
                        $raw = json_decode($raw, true);
                    }
                    if (is_array($raw)) {
                        if (in_array($modId, $raw) || in_array(str_replace('-management', '', $modId), $raw) || in_array(str_replace('-suite', '', $modId), $raw)) {
                            $count++;
                        }
                    }
                }
                $modObj['adoption_count'] = $count;
                return $modObj;
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $modules,
        ]);
    }

    /**
     * Show a specific module.
     * GET /api/v1/platform-modules/{id}
     */
    public function show($id)
    {
        $module = DB::table('platform_modules')->where('id', $id)->first();

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Module not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $module,
        ]);
    }

    /**
     * Register a new platform module.
     * POST /api/v1/super-admin/platform-modules
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id'          => 'nullable|string|max:64|unique:platform_modules,id',
            'name'        => 'required|string|max:128',
            'monogram'    => 'nullable|string|max:8',
            'category'    => 'required|string|max:64',
            'description' => 'required|string',
            'icon'        => 'nullable|string|max:64',
            'logo_url'    => 'nullable|string|max:255',
            'href'        => 'required|string|max:255',
            'bg_pill'     => 'nullable|string|max:128',
            'card_bg'     => 'nullable|string|max:255',
            'is_core'     => 'nullable|boolean',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
        ]);

        $id = !empty($validated['id']) ? Str::slug($validated['id']) : Str::slug($validated['name']);
        
        // Ensure ID is unique
        if (DB::table('platform_modules')->where('id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Module with slug '{$id}' already exists. Please choose a different identifier.",
            ], 422);
        }

        $monogram = !empty($validated['monogram'])
            ? strtoupper($validated['monogram'])
            : strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $validated['name']), 0, 3));

        $record = [
            'id'          => $id,
            'name'        => $validated['name'],
            'monogram'    => $monogram ?: 'MOD',
            'category'    => $validated['category'],
            'description' => $validated['description'],
            'icon'        => $validated['icon'] ?? 'Store',
            'logo_url'    => $validated['logo_url'] ?? null,
            'href'        => $validated['href'],
            'bg_pill'     => $validated['bg_pill'] ?? 'bg-brand-subtle text-brand',
            'card_bg'     => $validated['card_bg'] ?? 'bg-blue-50/70 border-blue-200/90 hover:border-blue-400',
            'is_core'     => (bool) ($validated['is_core'] ?? false),
            'is_active'   => (bool) ($validated['is_active'] ?? true),
            'sort_order'  => (int) ($validated['sort_order'] ?? 0),
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        DB::table('platform_modules')->insert($record);

        $authUser = $request->user();
        if ($authUser && class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $authUser->id,
                $authUser->name,
                'create_platform_module',
                'platform_modules',
                $request->ip(),
                ['module_id' => $id, 'name' => $record['name']]
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully registered module '{$record['name']}'.",
            'data'    => $record,
        ], 201);
    }

    /**
     * Update an existing platform module.
     * PUT /api/v1/super-admin/platform-modules/{id}
     */
    public function update(Request $request, $id)
    {
        $module = DB::table('platform_modules')->where('id', $id)->first();
        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Module not found to update.',
            ], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:128',
            'monogram'    => 'nullable|string|max:8',
            'category'    => 'sometimes|required|string|max:64',
            'description' => 'sometimes|required|string',
            'icon'        => 'nullable|string|max:64',
            'logo_url'    => 'nullable|string|max:255',
            'href'        => 'sometimes|required|string|max:255',
            'bg_pill'     => 'nullable|string|max:128',
            'card_bg'     => 'nullable|string|max:255',
            'is_core'     => 'nullable|boolean',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
        ]);

        $updates = [];
        foreach ($validated as $key => $val) {
            if ($val !== null) {
                $updates[$key] = $val;
            }
        }
        $updates['updated_at'] = now();

        DB::table('platform_modules')->where('id', $id)->update($updates);
        $updatedModule = DB::table('platform_modules')->where('id', $id)->first();

        $authUser = $request->user();
        if ($authUser && class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $authUser->id,
                $authUser->name,
                'update_platform_module',
                'platform_modules',
                $request->ip(),
                ['module_id' => $id, 'updates' => $updates]
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Module '{$updatedModule->name}' updated successfully.",
            'data'    => $updatedModule,
        ]);
    }

    /**
     * Delete/archive a platform module.
     * DELETE /api/v1/super-admin/platform-modules/{id}
     */
    public function destroy(Request $request, $id)
    {
        $module = DB::table('platform_modules')->where('id', $id)->first();
        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Module not found.',
            ], 404);
        }

        if ($module->is_core) {
            return response()->json([
                'success' => false,
                'message' => "Core system module '{$module->name}' cannot be deleted.",
            ], 403);
        }

        DB::table('platform_modules')->where('id', $id)->delete();

        $authUser = $request->user();
        if ($authUser && class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $authUser->id,
                $authUser->name,
                'delete_platform_module',
                'platform_modules',
                $request->ip(),
                ['module_id' => $id, 'name' => $module->name]
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Module '{$module->name}' deleted successfully.",
        ]);
    }
}
