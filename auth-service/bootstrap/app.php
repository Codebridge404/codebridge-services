<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\LogRequestActivity;
use App\Http\Middleware\TenantQuotaMiddleware;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\RateLimitAuthMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // [BD-0040] Preserve visitor real-IP across Nginx -> Kubernetes -> Laravel proxy chain
        $middleware->trustProxies(
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->redirectGuestsTo(fn () => null);
        $middleware->prepend([
            \Illuminate\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\ProtectAttackMiddleware::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\EnsureApiClient::class,
            \App\Http\Middleware\AuthenticateJwt::class,
        ]);
        $middleware->append(LogRequestActivity::class);
        $middleware->alias([
            'role' => EnsureRole::class,
            'workspace' => \App\Http\Middleware\EnsureWorkspace::class,
            'pos_scope' => \App\Http\Middleware\RestrictPosSession::class,
            'meeting_access' => \App\Http\Middleware\AuthorizeMeetingAccess::class,
            'quota' => TenantQuotaMiddleware::class,
            'api_key' => ApiKeyMiddleware::class,
            'api_client' => \App\Http\Middleware\ApiClientMiddleware::class,
            'auth_throttle' => RateLimitAuthMiddleware::class,
            'protect_attack' => \App\Http\Middleware\ProtectAttackMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = $request->header('X-Request-ID')
                ?: $request->attributes->get('request_id')
                ?: (string) \Illuminate\Support\Str::uuid();

            if (
                $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
            ) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCESS_DENIED',
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                    'request_id' => $requestId,
                ], 403);
            }

            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException) {
                \Illuminate\Support\Facades\Log::warning('Database unique constraint violation', [
                    'request_id' => $requestId,
                    'path' => $request->path(),
                    'sql_state' => $e->getCode(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'code' => 'CONFLICT',
                    'message' => 'A record with these details already exists or violates a data constraint.',
                    'request_id' => $requestId,
                ], 409);
            }

            if ($e instanceof \Illuminate\Database\QueryException) {
                \Illuminate\Support\Facades\Log::error('Database query failure', [
                    'request_id' => $requestId,
                    'path' => $request->path(),
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'code' => 'DATABASE_ERROR',
                    'message' => 'A database error occurred while processing your request.',
                    'request_id' => $requestId,
                ], 500);
            }

            if (
                ! config('app.debug')
                && ! $e instanceof \Illuminate\Validation\ValidationException
                && ! $e instanceof \Illuminate\Auth\AuthenticationException
                && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            ) {
                \Illuminate\Support\Facades\Log::error('Unhandled API exception', [
                    'request_id' => $requestId,
                    'path' => $request->path(),
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'An unexpected server error occurred. Please try again later.',
                    'request_id' => $requestId,
                ], 500);
            }

            return null;
        });
    })->create();
