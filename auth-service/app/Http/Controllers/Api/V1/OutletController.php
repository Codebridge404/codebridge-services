<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Outlet\StoreOutletRequest;
use App\Http\Requests\Outlet\UpdateBusinessProfileRequest;
use App\Http\Requests\Outlet\UpdateOutletRequest;
use App\Http\Requests\Outlet\UpdateTerminalSettingsRequest;
use App\Http\Resources\Outlet\OutletResource;
use App\Services\PosBusinessProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0004 & BD-0019] Multi-Outlet & POS Business Operating Profile Management Controller.
 *
 * @ticket BD-0004, BD-0019
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/OutletController.php
 * @related-model App\Models\Outlet, App\Models\User, App\Services\PosBusinessProfileService
 * @entry-point GET /api/v1/outlets, POST /api/v1/outlets, PUT /api/v1/outlets/{id}, PATCH /api/v1/outlets/{id}/terminal-settings, PATCH /api/v1/outlets/{id}/business-profile
 * @date-time 2026-10-25T09:45:00+07:00
 *
 * Process Capabilities:
 * 1. Automatic generation of UUIDv4 for new outlets.
 * 2. Links staff members via outlet_uuid.
 * 3. Enforces multi-tenant workspace isolation.
 * 4. Stores and returns POS business operating profile & feature toggles (retail_mart, restaurant_fnb, cafe_qsr, boutique_retail).
 */
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
                'o.business_type',
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
                DB::raw('(SELECT COUNT(*) FROM users WHERE outlet_uuid = o.uuid) as staff_count'),
                DB::raw('(SELECT COUNT(*) FROM registers WHERE outlet_id = o.id) as registers_count')
            )
            ->orderBy('o.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => OutletResource::collection($outlets),
        ]);
    }

    public function store(StoreOutletRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $tenantId = $user ? $user->tenant_id : null;
        $businessType = $validated['business_type'] ?? 'retail_mart';
        $profile = PosBusinessProfileService::getProfile($businessType);

        $isKdsEnabled = $validated['is_kds_enabled'] ?? $profile['presets']['kds_enabled'];
        $isCfdEnabled = $validated['is_cfd_enabled'] ?? $profile['presets']['cfd_enabled'];

        $terminalSettings = $validated['terminal_settings'] ?? [];
        $terminalSettings['businessType'] = $businessType;
        $terminalSettings['tablesEnabled'] = $profile['presets']['tables_enabled'];

        $id = DB::table('outlets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'business_type' => $businessType,
            'code' => $validated['code'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? 'Thank you for shopping!',
            'receipt_footer' => $validated['receipt_footer'] ?? 'Please come again!',
            'is_kds_enabled' => $isKdsEnabled,
            'is_cfd_enabled' => $isCfdEnabled,
            'terminal_settings' => json_encode($terminalSettings),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet created successfully.',
            'data' => [
                'id' => $id,
                'business_type' => $businessType,
                'business_profile' => $profile,
            ],
        ], 201);
    }

    public function update(UpdateOutletRequest $request, $id)
    {
        $validated = $request->validated();

        $updates = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? null,
            'receipt_footer' => $validated['receipt_footer'] ?? null,
            'updated_at' => now(),
        ];

        if (array_key_exists('business_type', $validated) && $validated['business_type']) {
            $updates['business_type'] = $validated['business_type'];
        }

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
     * Get all supported POS business profiles and their feature presets.
     * GET /api/v1/outlets/business-profiles
     */
    public function listBusinessProfiles()
    {
        return response()->json([
            'status' => 'success',
            'data' => PosBusinessProfileService::getAllProfiles(),
        ]);
    }

    /**
     * Get active POS Business Profile and feature toggles for an outlet.
     * GET /api/v1/outlets/{id}/business-profile
     */
    public function getBusinessProfile($id)
    {
        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found.'], 404);
        }

        $businessType = $outlet->business_type ?? 'retail_mart';

        return response()->json([
            'status' => 'success',
            'data' => [
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                'business_type' => $businessType,
                'business_profile' => PosBusinessProfileService::getProfile($businessType),
                'is_kds_enabled' => (bool) $outlet->is_kds_enabled,
                'is_cfd_enabled' => (bool) $outlet->is_cfd_enabled,
            ],
        ]);
    }

    /**
     * Update active POS Business Operating Profile for an outlet.
     * PATCH|PUT /api/v1/outlets/{id}/business-profile
     */
    public function updateBusinessProfile(UpdateBusinessProfileRequest $request, $id)
    {
        $validated = $request->validated();

        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found.'], 404);
        }

        $businessType = $validated['business_type'];
        $profile = PosBusinessProfileService::getProfile($businessType);

        $currentSettings = $outlet->terminal_settings;
        if (is_string($currentSettings)) {
            $currentSettings = json_decode($currentSettings, true) ?? [];
        } else {
            $currentSettings = is_array($currentSettings) ? $currentSettings : [];
        }

        $currentSettings['businessType'] = $businessType;
        $currentSettings['tablesEnabled'] = $profile['presets']['tables_enabled'];
        $currentSettings['kdsEnabled'] = $profile['presets']['kds_enabled'];
        $currentSettings['cfdEnabled'] = $profile['presets']['cfd_enabled'];
        $currentSettings['barcodeContinuousMode'] = $profile['presets']['barcode_continuous_mode'];

        DB::table('outlets')->where('id', $id)->update([
            'business_type' => $businessType,
            'is_kds_enabled' => $profile['presets']['kds_enabled'],
            'is_cfd_enabled' => $profile['presets']['cfd_enabled'],
            'terminal_settings' => json_encode($currentSettings),
            'updated_at' => now(),
        ]);

        if (!empty($outlet->tenant_id)) {
            DB::table('tenants')->where('id', $outlet->tenant_id)->update([
                'business_type' => $businessType,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Operating profile updated to '{$profile['title']}' successfully.",
            'data' => [
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                'business_type' => $businessType,
                'business_profile' => $profile,
                'is_kds_enabled' => (bool) $profile['presets']['kds_enabled'],
                'is_cfd_enabled' => (bool) $profile['presets']['cfd_enabled'],
                'terminal_settings' => $currentSettings,
            ],
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

        $businessType = $outlet->business_type ?? 'retail_mart';
        $terminalSettings = $outlet->terminal_settings;
        if (is_string($terminalSettings)) {
            $terminalSettings = json_decode($terminalSettings, true);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                'business_type' => $businessType,
                'business_profile' => PosBusinessProfileService::getProfile($businessType),
                'is_kds_enabled' => (bool) $outlet->is_kds_enabled,
                'is_cfd_enabled' => (bool) $outlet->is_cfd_enabled,
                'receipt_header' => $outlet->receipt_header ?? null,
                'receipt_footer' => $outlet->receipt_footer ?? null,
                'receipt_logo_url' => $outlet->receipt_logo_url ?? null,
                'terminal_settings' => $terminalSettings ?? [],
            ],
        ]);
    }

    /**
     * Update POS Terminal Feature Flags & Peripherals for an outlet.
     * PATCH /api/v1/outlets/{id}/terminal-settings
     */
    public function updateTerminalSettings(UpdateTerminalSettingsRequest $request, $id)
    {
        $validated = $request->validated();

        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found.'], 404);
        }

        $updates = [
            'updated_at' => now(),
        ];

        $currentSettings = $outlet->terminal_settings;
        if (is_string($currentSettings)) {
            $currentSettings = json_decode($currentSettings, true) ?? [];
        } else {
            $currentSettings = is_array($currentSettings) ? $currentSettings : [];
        }

        if (array_key_exists('business_type', $validated) && $validated['business_type']) {
            $businessType = $validated['business_type'];
            $updates['business_type'] = $businessType;
            $profile = PosBusinessProfileService::getProfile($businessType);

            // If kds/cfd not explicitly overridden, preset from profile
            if (!array_key_exists('is_kds_enabled', $validated)) {
                $updates['is_kds_enabled'] = $profile['presets']['kds_enabled'];
            }
            if (!array_key_exists('is_cfd_enabled', $validated)) {
                $updates['is_cfd_enabled'] = $profile['presets']['cfd_enabled'];
            }

            $currentSettings['businessType'] = $businessType;
            $currentSettings['tablesEnabled'] = $profile['presets']['tables_enabled'];
            $currentSettings['barcodeContinuousMode'] = $profile['presets']['barcode_continuous_mode'];

            // Also keep tenant default aligned
            if (!empty($outlet->tenant_id)) {
                DB::table('tenants')->where('id', $outlet->tenant_id)->update([
                    'business_type' => $businessType,
                    'updated_at' => now(),
                ]);
            }
        }

        if (array_key_exists('is_kds_enabled', $validated)) {
            $updates['is_kds_enabled'] = $validated['is_kds_enabled'];
            $currentSettings['kdsEnabled'] = (bool) $validated['is_kds_enabled'];
        }
        if (array_key_exists('is_cfd_enabled', $validated)) {
            $updates['is_cfd_enabled'] = $validated['is_cfd_enabled'];
            $currentSettings['cfdEnabled'] = (bool) $validated['is_cfd_enabled'];
        }
        if (array_key_exists('terminal_settings', $validated) && is_array($validated['terminal_settings'])) {
            $currentSettings = array_merge($currentSettings, $validated['terminal_settings']);
        }

        $updates['terminal_settings'] = json_encode($currentSettings);

        DB::table('outlets')->where('id', $id)->update($updates);

        $freshOutlet = DB::table('outlets')->where('id', $id)->first();
        $storedSettings = $freshOutlet->terminal_settings;
        if (is_string($storedSettings)) {
            $storedSettings = json_decode($storedSettings, true);
        }

        $effectiveBusinessType = $freshOutlet->business_type ?? 'retail_mart';

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet terminal settings updated successfully.',
            'data' => [
                'outlet_id' => $freshOutlet->id,
                'business_type' => $effectiveBusinessType,
                'business_profile' => PosBusinessProfileService::getProfile($effectiveBusinessType),
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

    /**
     * Update thermal receipt custom branding (logo, header, footer, tax tin)
     * POST /api/v1/outlets/{id}/receipt-branding
     */
    public function updateReceiptBranding(Request $request, $id)
    {
        $outlet = DB::table('outlets')->where('id', $id)->first();
        if (!$outlet) {
            return response()->json(['status' => 'error', 'message' => 'Outlet not found'], 404);
        }

        $validated = $request->validate([
            'receipt_header' => 'nullable|string|max:500',
            'receipt_footer' => 'nullable|string|max:500',
            'receipt_logo_url' => 'nullable|string|max:500',
            'vat_tin' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
        ]);

        $updateData = [
            'updated_at' => now(),
        ];

        if ($request->has('receipt_header')) $updateData['receipt_header'] = $validated['receipt_header'];
        if ($request->has('receipt_footer')) $updateData['receipt_footer'] = $validated['receipt_footer'];
        if ($request->has('phone')) $updateData['phone'] = $validated['phone'];
        if ($request->has('address')) $updateData['address'] = $validated['address'];

        // If logo file is uploaded
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('receipt_logos', 'public');
            $updateData['receipt_logo_url'] = '/storage/' . $path;
        } elseif ($request->filled('receipt_logo_url')) {
            $updateData['receipt_logo_url'] = $validated['receipt_logo_url'];
        }

        // Store VAT TIN or tax number in terminal_settings JSON
        $terminalSettings = json_decode($outlet->terminal_settings ?? '{}', true) ?: [];
        if ($request->has('vat_tin')) {
            $terminalSettings['vat_tin'] = $validated['vat_tin'];
            $updateData['terminal_settings'] = json_encode($terminalSettings);
        }

        DB::table('outlets')->where('id', $id)->update($updateData);

        $fresh = DB::table('outlets')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Thermal receipt branding updated successfully.',
            'data' => [
                'id' => $fresh->id,
                'name' => $fresh->name,
                'receipt_header' => $fresh->receipt_header,
                'receipt_footer' => $fresh->receipt_footer,
                'receipt_logo_url' => $fresh->receipt_logo_url ?? ($updateData['receipt_logo_url'] ?? null),
                'phone' => $fresh->phone,
                'address' => $fresh->address,
                'vat_tin' => $terminalSettings['vat_tin'] ?? null,
            ],
        ]);
    }
}
