<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Enterprise API Client Registration & Management Controller.
 * Provides administrative control over consuming client apps (Web Admin, POS Flutter, Mobile Runner).
 */
class ApiClientController extends Controller
{
    /**
     * List all registered API clients.
     * GET /api/v1/api-clients
     */
    public function index(Request $request): JsonResponse
    {
        $clients = ApiClient::query()
            ->withCount('userSessions')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $clients,
        ]);
    }

    /**
     * Register a new API client.
     * POST /api/v1/api-clients
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'client_type' => 'required|string|in:web,pos,mobile,server,other',
            'client_key' => 'nullable|string|max:64|unique:api_clients,client_key',
            'is_active' => 'nullable|boolean',
        ]);

        $clientKey = $validated['client_key'] ?? ('client_' . Str::lower($validated['client_type']) . '_' . Str::random(24));

        $client = ApiClient::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'client_key' => $clientKey,
            'client_type' => $validated['client_type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'API client registered successfully.',
            'data' => $client,
        ], 201);
    }

    /**
     * Show client details.
     * GET /api/v1/api-clients/{id}
     */
    public function show(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)
            ->orWhere('uuid', $id)
            ->withCount('userSessions')
            ->first();

        if (!$client) {
            return response()->json([
                'status' => 'error',
                'message' => 'API client not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $client,
        ]);
    }

    /**
     * Update client details.
     * PUT/PATCH /api/v1/api-clients/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return response()->json([
                'status' => 'error',
                'message' => 'API client not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'client_type' => 'sometimes|string|in:web,pos,mobile,server,other',
            'is_active' => 'sometimes|boolean',
        ]);

        $client->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'API client updated successfully.',
            'data' => $client,
        ]);
    }

    /**
     * Toggle active state.
     * PUT /api/v1/api-clients/{id}/toggle
     */
    public function toggle(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return response()->json([
                'status' => 'error',
                'message' => 'API client not found.',
            ], 404);
        }

        $client->update(['is_active' => !$client->is_active]);

        return response()->json([
            'status' => 'success',
            'message' => $client->is_active ? 'API client activated.' : 'API client deactivated.',
            'data' => $client,
        ]);
    }

    /**
     * Delete an API client.
     * DELETE /api/v1/api-clients/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return response()->json([
                'status' => 'error',
                'message' => 'API client not found.',
            ], 404);
        }

        if ($client->userSessions()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete API client with existing user session lineage.',
            ], 422);
        }

        $client->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'API client deleted successfully.',
        ]);
    }
}
