<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\StoreCheckoutRequest;
use App\Services\PosBffService;
use App\Services\TenantAuthorizationService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;

/**
 * Aggregated POS Backend-for-Frontend (BFF) controller.
 *
 * Exposes screen-shaped endpoints for Flutter POS clients while delegating
 * domain rules, inventory decrements, KHQR, and shift accounting to operations services.
 */
class PosBffController extends Controller
{
    use ResolvesTenantContext;

    public function __construct(
        protected PosBffService $posBffService
    ) {}

    /**
     * GET /api/v1/pos-bff/bootstrap
     */
    public function bootstrap(Request $request)
    {
        $tenantId = (string) $this->getTenantId($request, true);
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'min:1'],
        ]);

        [$isSuperAdminAll, $roles] = $this->resolveCallerContext($request, $tenantId);

        $data = $this->posBffService->getBootstrap(
            $request->user(),
            $tenantId,
            $isSuperAdminAll,
            $roles,
            isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/pos-bff/catalog
     */
    public function catalog(Request $request)
    {
        $tenantId = (string) $this->getTenantId($request, true);
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'updated_since' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        [$isSuperAdminAll] = $this->resolveCallerContext($request, $tenantId);

        $data = $this->posBffService->getCatalog(
            $request->user(),
            $tenantId,
            $isSuperAdminAll,
            $validated
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/pos-bff/shift-summary
     */
    public function shiftSummary(Request $request)
    {
        $tenantId = (string) $this->getTenantId($request, true);
        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'min:1'],
        ]);

        [$isSuperAdminAll] = $this->resolveCallerContext($request, $tenantId);

        $shiftSummary = $this->posBffService->resolveShiftSummary(
            (int) $request->user()->id,
            $tenantId,
            $isSuperAdminAll,
            isset($validated['shift_id']) ? (int) $validated['shift_id'] : null,
            isset($validated['shift_id'])
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_shift' => $shiftSummary,
                'exchange_rate' => $this->posBffService->buildExchangeRatePayload($tenantId),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/pos-bff/checkout
     */
    public function checkout(StoreCheckoutRequest $request)
    {
        $tenantId = (string) $this->getTenantId($request, true);
        $extra = $request->validate([
            'generate_khqr' => ['nullable', 'boolean'],
            'khqr_currency' => ['nullable', 'string', 'in:USD,KHR'],
            'merchant_id' => ['nullable', 'string', 'max:100'],
            'merchant_name' => ['nullable', 'string', 'max:100'],
            'merchant_city' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
        ]);

        [$isSuperAdminAll] = $this->resolveCallerContext($request, $tenantId);
        $payload = array_merge($request->validated(), $extra);

        try {
            $result = $this->posBffService->checkout(
                $payload,
                $request->user(),
                $tenantId,
                $isSuperAdminAll
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['is_duplicate']
                    ? 'Sale already processed'
                    : 'POS checkout orchestrated successfully',
                'data' => $result,
            ], $result['is_duplicate'] ? 200 : 201);
        } catch (\Exception $e) {
            $status = str_starts_with($e->getMessage(), 'Unauthorized:') ? 403 : 422;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * @return array{0: bool, 1: list<string>}
     */
    protected function resolveCallerContext(Request $request, string $tenantId): array
    {
        $jwtClaims = $request->attributes->get('jwt_claims');
        $authContext = TenantAuthorizationService::resolveAuthorizationContext(
            $request,
            $request->user(),
            is_array($jwtClaims) ? $jwtClaims : null,
            false
        );

        $isSuperAdminAll = ($tenantId === 'all' && !empty($authContext['is_platform_super_admin']));
        $roles = is_array($authContext['roles'] ?? null)
            ? array_values(array_map('strval', $authContext['roles']))
            : [strval($request->user()->role ?? 'cashier')];

        return [$isSuperAdminAll, $roles];
    }
}

