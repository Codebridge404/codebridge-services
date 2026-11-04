<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\InviteUserRequest;
use App\Http\Resources\UserInvitation\UserInvitationResource;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserInvitationController extends Controller
{
    /**
     * List all staff invitations for the authenticated tenant.
     * GET /api/v1/invitations
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = ($currentUser?->role === 'super_admin' && $request->filled('tenant_id'))
            ? (int) $request->query('tenant_id')
            : (int) $currentUser?->tenant_id;

        $status = $request->query('status', 'all');
        $search = $request->query('q') ?? $request->query('search');

        $query = UserInvitation::with(['roleRecord', 'outlet', 'invitedBy']);

        if ($currentUser && $currentUser->role !== 'super_admin' && $tenantId) {
            $query->where('tenant_id', $tenantId);
        } elseif ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($status === 'pending') {
            $query->whereNull('accepted_at')->where('expires_at', '>', now());
        } elseif ($status === 'accepted') {
            $query->whereNotNull('accepted_at');
        } elseif ($status === 'expired') {
            $query->whereNull('accepted_at')->where('expires_at', '<=', now());
        }

        if (!empty($search)) {
            $query->where('email', 'like', "%{$search}%");
        }

        $invitations = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => UserInvitationResource::collection($invitations),
        ]);
    }

    /**
     * Generate and dispatch a staff invitation link.
     * POST /api/v1/invitations
     */
    public function store(InviteUserRequest $request)
    {
        $validated = $request->validated();
        $currentUser = $request->user();

        $tenantId = ($currentUser?->role === 'super_admin' && !empty($validated['tenant_id']))
            ? (int) $validated['tenant_id']
            : (int) $currentUser?->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Workspace context (tenant_id) is required to issue staff invitations.',
            ], 422);
        }

        // Check if user is already an active member of this workspace
        $existing = DB::table('users')->where('email', $validated['email'])->first();
        if ($existing && $tenantId) {
            $isAlreadyMember = DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $existing->id)
                ->where('status', 'active')
                ->exists();

            if ($isAlreadyMember) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A user with this email already belongs to your workspace.',
                ], 422);
            }
        }

        // Subscription seat limit check
        if ($quotaErr = $this->checkUserLimit($currentUser, $tenantId)) {
            return response()->json([
                'status' => 'error',
                'code' => 'QUOTA_EXCEEDED',
                'message' => $quotaErr,
            ], 403);
        }

        // 1. Resolve role and ensure role belongs to the invited tenant
        $roleRecord = null;
        if (!empty($validated['role_id'])) {
            $roleRecord = DB::table('roles')->where('id', $validated['role_id'])->first();
        } elseif (!empty($validated['role'])) {
            $roleRecord = DB::table('roles')
                ->where('slug', $validated['role'])
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                })
                ->first();

            if (!$roleRecord) {
                $roleRecord = DB::table('roles')->where('slug', $validated['role'])->first();
            }
        }

        if (!$roleRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected role does not exist.',
            ], 422);
        }

        if ($roleRecord->tenant_id && (int) $roleRecord->tenant_id !== $tenantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected role does not belong to the invited tenant.',
            ], 422);
        }

        if ($roleRecord->slug === 'super_admin' && (!$currentUser || $currentUser->role !== 'super_admin')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only a super administrator may invite users with the super_admin role.',
            ], 403);
        }

        // 2. Resolve outlet and ensure outlet belongs to the invited tenant
        $outletUuid = null;
        $outletIdentifier = $validated['outlet_uuid'] ?? ($validated['outlet_id'] ?? null);
        if (!empty($outletIdentifier)) {
            $outlet = DB::table('outlets')
                ->where(function ($q) use ($outletIdentifier) {
                    $q->where('uuid', $outletIdentifier);
                    if (is_numeric($outletIdentifier)) {
                        $q->orWhere('id', (int) $outletIdentifier);
                    }
                })
                ->first();

            if (!$outlet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected outlet does not exist.',
                ], 422);
            }

            if ($outlet->tenant_id && (int) $outlet->tenant_id !== $tenantId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected outlet does not belong to the invited tenant.',
                ], 422);
            }

            $outletUuid = $outlet->uuid;
        }

        $inviteToken = Str::random(48);
        $tokenHash = hash('sha256', $inviteToken);

        // Remove any previous unaccepted invitation for this email in this tenant
        UserInvitation::where('tenant_id', $tenantId)
            ->where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->delete();

        $invitation = UserInvitation::create([
            'tenant_id' => $tenantId,
            'role_id' => $roleRecord->id,
            'outlet_uuid' => $outletUuid,
            'email' => $validated['email'],
            'token_hash' => $tokenHash,
            'invited_by' => $currentUser?->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff invitation link generated successfully.',
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $roleRecord->slug,
                'role_id' => $roleRecord->id,
                'outlet_uuid' => $outletUuid,
                'token' => $inviteToken,
                'token_hash' => $tokenHash,
                'invite_url' => "/accept-invite?token={$inviteToken}",
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * View invitation details by ID.
     * GET /api/v1/invitations/{id}
     */
    public function show(Request $request, $id)
    {
        $currentUser = $request->user();
        $query = UserInvitation::with(['roleRecord', 'outlet', 'invitedBy']);

        if ($currentUser && $currentUser->role !== 'super_admin' && $currentUser->tenant_id) {
            $query->where('tenant_id', $currentUser->tenant_id);
        }

        $invitation = $query->find($id);
        if (!$invitation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invitation not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new UserInvitationResource($invitation),
        ]);
    }

    /**
     * Resend/renew an unaccepted staff invitation.
     * POST /api/v1/invitations/{id}/resend
     */
    public function resend(Request $request, $id)
    {
        $currentUser = $request->user();
        $query = UserInvitation::whereNull('accepted_at');

        if ($currentUser && $currentUser->role !== 'super_admin' && $currentUser->tenant_id) {
            $query->where('tenant_id', $currentUser->tenant_id);
        }

        $invitation = $query->find($id);
        if (!$invitation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invitation not found or has already been accepted.',
            ], 404);
        }

        $newToken = Str::random(48);
        $tokenHash = hash('sha256', $newToken);

        $invitation->update([
            'token_hash' => $tokenHash,
            'expires_at' => now()->addDays(7),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff invitation refreshed and renewed for 7 days.',
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'token' => $newToken,
                'token_hash' => $tokenHash,
                'invite_url' => "/accept-invite?token={$newToken}",
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Revoke/cancel a pending staff invitation.
     * DELETE /api/v1/invitations/{id}
     */
    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();
        $query = UserInvitation::query();

        if ($currentUser && $currentUser->role !== 'super_admin' && $currentUser->tenant_id) {
            $query->where('tenant_id', $currentUser->tenant_id);
        }

        $invitation = $query->find($id);
        if (!$invitation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invitation not found.',
            ], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot revoke an invitation that has already been accepted.',
            ], 422);
        }

        $invitation->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Staff invitation revoked successfully.',
        ]);
    }

    /**
     * Helper to check subscription seat quota.
     */
    private function checkUserLimit($currentUser, $tenantId): ?string
    {
        if ($currentUser && $currentUser->role !== 'super_admin' && $tenantId) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->first();
            $maxUsers = $tenant->max_users ?? 50;
            $count = DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count();

            if ($count >= $maxUsers) {
                return "User limit reached ({$count}/{$maxUsers}) for your subscription tier.";
            }
        }
        return null;
    }
}

