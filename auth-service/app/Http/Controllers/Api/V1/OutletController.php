<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutletController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $search = $request->query('q');

        $query = DB::table('outlets as o');

        // Multi-tenant scoping: Non-super-admin users only see outlets belonging to their tenant/workspace
        if ($user && $user->role !== 'super_admin') {
            if (!empty($user->tenant_id)) {
                $query->where('o.tenant_id', $user->tenant_id);
            } elseif (!empty($user->outlet_id)) {
                $query->where('o.id', $user->outlet_id);
            }
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('o.name', 'like', "%{$search}%")
                  ->orWhere('o.code', 'like', "%{$search}%");
            });
        }

        $outlets = $query->select(
                'o.id',
                'o.tenant_id',
                'o.name',
                'o.code',
                'o.address',
                'o.phone',
                'o.receipt_header',
                'o.receipt_footer',
                'o.is_active',
                'o.is_kds_enabled',
                'o.is_cfd_enabled',
                'o.terminal_settings',
                'o.created_at',
                DB::raw('(SELECT COUNT(*) FROM users WHERE outlet_id = o.id) as staff_count'),
                DB::raw('(SELECT COUNT(*) FROM registers WHERE outlet_id = o.id) as registers_count')
            )
            ->orderBy('o.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $outlets,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:outlets,code',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'receipt_header' => 'nullable|string|max:255',
            'receipt_footer' => 'nullable|string|max:255',
            'is_kds_enabled' => 'nullable|boolean',
            'is_cfd_enabled' => 'nullable|boolean',
            'terminal_settings' => 'nullable|array',
        ]);

        $tenantId = $user ? $user->tenant_id : null;

        $id = DB::table('outlets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? 'Thank you for shopping!',
            'receipt_footer' => $validated['receipt_footer'] ?? 'Please come again!',
            'is_kds_enabled' => $validated['is_kds_enabled'] ?? true,
            'is_cfd_enabled' => $validated['is_cfd_enabled'] ?? true,
            'terminal_settings' => isset($validated['terminal_settings']) ? json_encode($validated['terminal_settings']) : null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'receipt_header' => 'nullable|string|max:255',
            'receipt_footer' => 'nullable|string|max:255',
            'is_kds_enabled' => 'nullable|boolean',
            'is_cfd_enabled' => 'nullable|boolean',
            'terminal_settings' => 'nullable|array',
        ]);

        $updates = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? null,
            'receipt_footer' => $validated['receipt_footer'] ?? null,
            'updated_at' => now(),
        ];

        if (array_key_exists('is_kds_enabled', $validated)) {
            $updates['is_kds_enabled'] = $validated['is_kds_enabled'];
        }
        if (array_key_exists('is_cfd_enabled', $validated)) {
            $updates['is_cfd_enabled'] = $validated['is_cfd_enabled'];
        }
        if (array_key_exists('terminal_settings', $validated)) {
            $updates['terminal_settings'] = json_encode($validated['terminal_settings']);
        }

        DB::table('outlets')->where('id', $id)->update($updates);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet updated successfully.',
        ]);
    }

    /**
     * Get POS Terminal & Peripheral Settings for a specific outlet.
     * GET /api/v1/outlets/{id}/terminal-settings
     */
    public function getTerminalSettings($id)
    {
        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found.'], 404);
        }

        $terminalSettings = $outlet->terminal_settings;
        if (is_string($terminalSettings)) {
            $terminalSettings = json_decode($terminalSettings, true);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                'is_kds_enabled' => (bool) $outlet->is_kds_enabled,
                'is_cfd_enabled' => (bool) $outlet->is_cfd_enabled,
                'terminal_settings' => $terminalSettings ?? [],
            ],
        ]);
    }

    /**
     * Update POS Terminal Feature Flags & Peripherals for an outlet.
     * PATCH /api/v1/outlets/{id}/terminal-settings
     */
    public function updateTerminalSettings(Request $request, $id)
    {
        $validated = $request->validate([
            'is_kds_enabled' => 'nullable|boolean',
            'is_cfd_enabled' => 'nullable|boolean',
            'terminal_settings' => 'nullable|array',
        ]);

        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found.'], 404);
        }

        $updates = [
            'updated_at' => now(),
        ];

        if (array_key_exists('is_kds_enabled', $validated)) {
            $updates['is_kds_enabled'] = $validated['is_kds_enabled'];
        }
        if (array_key_exists('is_cfd_enabled', $validated)) {
            $updates['is_cfd_enabled'] = $validated['is_cfd_enabled'];
        }
        if (array_key_exists('terminal_settings', $validated)) {
            $currentSettings = $outlet->terminal_settings;
            if (is_string($currentSettings)) {
                $currentSettings = json_decode($currentSettings, true) ?? [];
            } else {
                $currentSettings = is_array($currentSettings) ? $currentSettings : [];
            }
            $mergedSettings = array_merge($currentSettings, $validated['terminal_settings']);
            $updates['terminal_settings'] = json_encode($mergedSettings);
        }

        DB::table('outlets')->where('id', $id)->update($updates);

        $freshOutlet = DB::table('outlets')->where('id', $id)->first();
        $storedSettings = $freshOutlet->terminal_settings;
        if (is_string($storedSettings)) {
            $storedSettings = json_decode($storedSettings, true);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet terminal settings updated successfully.',
            'data' => [
                'outlet_id' => $freshOutlet->id,
                'is_kds_enabled' => (bool) $freshOutlet->is_kds_enabled,
                'is_cfd_enabled' => (bool) $freshOutlet->is_cfd_enabled,
                'terminal_settings' => $storedSettings ?? [],
            ],
        ]);
    }

    public function destroy($id)
    {
        DB::table('outlets')->where('id', $id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet deactivated.',
        ]);
    }
}
