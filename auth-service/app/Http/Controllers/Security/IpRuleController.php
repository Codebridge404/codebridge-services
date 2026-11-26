<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\StoreIpRuleRequest;
use App\Models\Security\PlatformIpRule;
use App\Services\IpRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform IP Allowlist, Rate-Limit & WAF Rules Controller (Super Admin).
 */
class IpRuleController extends Controller
{
    public function __construct(
        protected IpRuleService $ipRuleService
    ) {}

    /**
     * List platform IP/CIDR allowlist and blocklist rules.
     * GET /api/v1/super-admin/security/ip-rules
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlatformIpRule::query()->orderByDesc('id');

        if ($request->filled('rule_type') && in_array($request->input('rule_type'), ['allow', 'block'], true)) {
            $query->where('rule_type', $request->input('rule_type'));
        }

        $rules = $query->get()->map(fn (PlatformIpRule $rule) => [
            'id' => $rule->id,
            'uuid' => $rule->uuid,
            'ip_or_cidr' => $rule->ip_or_cidr,
            'rule_type' => $rule->rule_type,
            'reason' => $rule->reason,
            'is_active' => (bool) $rule->is_active,
            'expires_at' => $rule->expires_at?->toIso8601String(),
            'created_at' => $rule->created_at?->toIso8601String(),
        ]);

        return $this->successResponse(
            data: $rules,
            message: 'Platform IP rules retrieved successfully.'
        );
    }

    /**
     * Create or update an IP/CIDR allowlist or blocklist rule.
     * POST /api/v1/super-admin/security/ip-rules
     */
    public function store(StoreIpRuleRequest $request): JsonResponse
    {
        $rule = $this->ipRuleService->upsertRule($request->validated(), $request->user());

        $this->recordAuditLog(
            action: 'security.ip_rule_upserted',
            module: 'security',
            entityType: PlatformIpRule::class,
            entityId: (string) $rule->id,
            payload: [
                'ip_or_cidr' => $rule->ip_or_cidr,
                'rule_type' => $rule->rule_type,
                'is_active' => $rule->is_active,
            ],
            details: "Platform IP rule ({$rule->rule_type}) saved for {$rule->ip_or_cidr}"
        );

        return $this->createdResponse(
            data: [
                'id' => $rule->id,
                'uuid' => $rule->uuid,
                'ip_or_cidr' => $rule->ip_or_cidr,
                'rule_type' => $rule->rule_type,
                'reason' => $rule->reason,
                'is_active' => (bool) $rule->is_active,
                'expires_at' => $rule->expires_at?->toIso8601String(),
                'created_at' => $rule->created_at?->toIso8601String(),
            ],
            message: 'Platform IP rule saved successfully.'
        );
    }

    /**
     * Delete an IP/CIDR rule by ID or UUID.
     * DELETE /api/v1/super-admin/security/ip-rules/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->ipRuleService->deleteRule($id);

        if (! $deleted) {
            return $this->notFoundResponse('Platform IP rule not found.');
        }

        $this->recordAuditLog(
            action: 'security.ip_rule_deleted',
            module: 'security',
            entityType: PlatformIpRule::class,
            entityId: (string) $deleted->id,
            payload: [
                'ip_or_cidr' => $deleted->ip_or_cidr,
                'rule_type' => $deleted->rule_type,
            ],
            details: "Platform IP rule ({$deleted->rule_type}) removed for {$deleted->ip_or_cidr}"
        );

        return $this->successResponse(
            message: 'Platform IP rule deleted successfully.'
        );
    }
}
