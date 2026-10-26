<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKey\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0006] Merchant Developer API Key Issuance & Cryptographic Hashing Controller.
 *
 * @ticket BD-0006
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/ApiKeyController.php
 * @related-model App\Models\ApiKey
 * @entry-point GET /api/v1/api-keys, POST /api/v1/api-keys, DELETE /api/v1/api-keys/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Generates 64-character secret with key_prefix (first 16 chars).
 * 2. SHA-256 hashes the secret (never stores plaintext API keys).
 * 3. Associates granular permissions JSON matrix.
 */
class ApiKeyController extends Controller
{
    /**
     * List all merchant API keys for the current user's tenant/organization.
     * GET /api/v1/api-keys
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = DB::table('api_keys');

        if ($user->role !== 'super_admin' && $tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $keys = $query->select(
                'id',
                'tenant_id',
                'user_id',
                'name',
                'key_prefix',
                'permissions',
                'last_used_at',
                'expires_at',
                'is_active',
                'created_at'
            )
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($k) {
                $k->permissions = json_decode($k->permissions ?? '["*"]', true);
                return $k;
            });

        return response()->json([
            'status' => 'success',
            'data' => ApiKeyResource::collection($keys),
        ]);
    }

    /**
     * Generate a new Merchant API Key.
     * POST /api/v1/api-keys
     */
    public function store(StoreApiKeyRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();

        // Generate full secret key (e.g. cb_live_a1b2c3d4e5...)
        $randomSecret = Str::random(36);
        $rawKey = 'cb_live_' . $randomSecret;
        $prefix = substr($rawKey, 0, 16) . '...';
        $hashedSecret = hash('sha256', $rawKey);

        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays($validated['expires_in_days'])
            : null;

        $permissions = $validated['permissions'] ?? ['*'];

        $id = DB::table('api_keys')->insertGetId([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'name' => $validated['name'],
            'key_prefix' => $prefix,
            'secret_hash' => $hashedSecret,
            'permissions' => json_encode($permissions),
            'expires_at' => $expiresAt,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant API Key created successfully. Please copy it immediately; you will not be able to see it again!',
            'plain_text_key' => $rawKey,
            'data' => [
                'id' => $id,
                'name' => $validated['name'],
                'key_prefix' => $prefix,
                'permissions' => $permissions,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'created_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Revoke / Delete an API Key.
     * DELETE /api/v1/api-keys/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $query = DB::table('api_keys')->where('id', $id);
        if ($user->role !== 'super_admin' && $user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $deleted = $query->delete();

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'API Key not found or unauthorized to delete.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant API Key revoked and deleted successfully.',
        ]);
    }

    /**
     * Toggle API key active status.
     * PUT /api/v1/api-keys/{id}/toggle
     */
    public function toggle(Request $request, $id)
    {
        $user = $request->user();

        $query = DB::table('api_keys')->where('id', $id);
        if ($user->role !== 'super_admin' && $user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $key = $query->first();
        if (!$key) {
            return response()->json([
                'status' => 'error',
                'message' => 'API Key not found.',
            ], 404);
        }

        $newStatus = !$key->is_active;

        $query->update([
            'is_active' => $newStatus,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $newStatus ? 'API Key activated.' : 'API Key disabled.',
            'is_active' => $newStatus,
        ]);
    }
}
