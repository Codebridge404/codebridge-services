<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiClient\StoreApiClientRequest;
use App\Http\Requests\ApiClient\UpdateApiClientRequest;
use App\Http\Resources\ApiClient\ApiClientResource;
use App\Http\Responses\HttpStatus;
use App\Http\Responses\ResponseCode;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return $this->successResponse(
            ApiClientResource::collection($clients),
            'API clients retrieved successfully.'
        );
    }

    /**
     * Register a new API client.
     * POST /api/v1/api-clients
     */
    public function store(StoreApiClientRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $clientKey = !empty($validated['client_key'])
            ? $validated['client_key']
            : ApiClient::generateClientKey($validated['client_type']);

        $client = ApiClient::create([
            'name' => $validated['name'],
            'client_key' => $clientKey,
            'client_type' => $validated['client_type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->recordAuditLog(
            action: 'create',
            module: 'api_client',
            entityType: ApiClient::class,
            entityId: (string) $client->id,
            payload: ['name' => $client->name, 'client_type' => $client->client_type],
            details: "API Client '{$client->name}' created"
        );

        return $this->createdResponse(
            new ApiClientResource($client),
            'API client registered successfully.'
        );
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
            return $this->notFoundResponse('API client not found.');
        }

        return $this->successResponse(
            new ApiClientResource($client),
            'API client details retrieved successfully.'
        );
    }

    /**
     * Update client details.
     * PUT/PATCH /api/v1/api-clients/{id}
     */
    public function update(UpdateApiClientRequest $request, string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return $this->notFoundResponse('API client not found.');
        }

        $validated = $request->validated();
        $client->update($validated);

        $this->recordAuditLog(
            action: 'update',
            module: 'api_client',
            entityType: ApiClient::class,
            entityId: (string) $client->id,
            payload: $validated,
            details: "API Client '{$client->name}' updated"
        );

        return $this->successResponse(
            new ApiClientResource($client),
            'API client updated successfully.'
        );
    }

    /**
     * Toggle active state.
     * PUT /api/v1/api-clients/{id}/toggle
     */
    public function toggle(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return $this->notFoundResponse('API client not found.');
        }

        $client->update(['is_active' => !$client->is_active]);

        $this->recordAuditLog(
            action: 'toggle',
            module: 'api_client',
            entityType: ApiClient::class,
            entityId: (string) $client->id,
            payload: ['is_active' => $client->is_active],
            details: "API Client '{$client->name}' " . ($client->is_active ? 'activated' : 'deactivated')
        );

        return $this->successResponse(
            new ApiClientResource($client),
            $client->is_active ? 'API client activated.' : 'API client deactivated.'
        );
    }

    /**
     * Regenerate client key.
     * POST /api/v1/api-clients/{id}/regenerate-key
     */
    public function regenerateKey(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return $this->notFoundResponse('API client not found.');
        }

        $newKey = ApiClient::generateClientKey($client->client_type);
        $client->update(['client_key' => $newKey]);

        $this->recordAuditLog(
            action: 'regenerate_key',
            module: 'api_client',
            entityType: ApiClient::class,
            entityId: (string) $client->id,
            details: "API Client key regenerated for '{$client->name}'"
        );

        return $this->successResponse(
            new ApiClientResource($client),
            'API client key regenerated successfully.'
        );
    }

    /**
     * Delete an API client.
     * DELETE /api/v1/api-clients/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $client = ApiClient::where('id', $id)->orWhere('uuid', $id)->first();

        if (!$client) {
            return $this->notFoundResponse('API client not found.');
        }

        if ($client->userSessions()->exists()) {
            return $this->errorResponse(
                'Cannot delete API client with existing user session lineage.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::VALIDATION_ERROR
            );
        }

        $clientName = $client->name;
        $clientId = (string) $client->id;
        $client->delete();

        $this->recordAuditLog(
            action: 'delete',
            module: 'api_client',
            entityType: ApiClient::class,
            entityId: $clientId,
            details: "API Client '{$clientName}' deleted"
        );

        return $this->successResponse(
            null,
            'API client deleted successfully.'
        );
    }
}
