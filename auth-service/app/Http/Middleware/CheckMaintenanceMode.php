<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Platform Maintenance Mode by returning HTTP 503 Service Unavailable
 * on non-super_admin state-changing requests when block_mutations is active.
 */
class CheckMaintenanceMode
{
    public function __construct(
        protected MaintenanceModeService $maintenanceService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($this->maintenanceService->shouldBlockRequest($request, $user)) {
            $config = $this->maintenanceService->getConfig();

            return response()->json([
                'status' => 'error',
                'code' => 'MAINTENANCE_MODE',
                'message' => 'The platform is undergoing maintenance. Please try again later.',
                'maintenance' => [
                    'message' => $config['message'],
                    'starts_at' => $config['starts_at'],
                    'ends_at' => $config['ends_at'],
                ],
            ], 503);
        }

        return $next($request);
    }
}
