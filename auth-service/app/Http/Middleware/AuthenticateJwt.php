<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0040] Stateless JWT Authentication Middleware.
 *
 * @ticket BD-0040
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/AuthenticateJwt.php
 * @related-service App\Services\JwtService
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Validates HMAC-SHA256 signed Bearer tokens statelessly across all microservice requests.
 */
class AuthenticateJwt
{
    public function __construct(
        protected JwtService $jwtService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token && substr_count($token, '.') === 2) {
            $payload = $this->jwtService->verify($token);

            if ($payload && !empty($payload['sub'])) {
                $user = User::find($payload['sub']);

                if (!$user) {
                    // Create in-memory user from verified JWT claims
                    $user = new User([
                        'id' => (int) $payload['sub'],
                        'name' => $payload['name'] ?? 'User',
                        'email' => $payload['email'] ?? '',
                        'role' => $payload['role'] ?? 'cashier',
                        'tenant_id' => $payload['tenant_id'] ?? null,
                        'outlet_id' => $payload['outlet_id'] ?? null,
                    ]);
                    $user->id = (int) $payload['sub'];
                    $user->exists = true;
                }

                Auth::setUser($user);
                Auth::guard('sanctum')->setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}
