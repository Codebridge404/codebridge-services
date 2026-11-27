<?php

use App\Http\Middleware\ApiClientMiddleware;
use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\AuthorizeMeetingAccess;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\EnsureApiClient;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureWorkspace;
use App\Http\Middleware\LogRequestActivity;
use App\Http\Middleware\ProtectAttackMiddleware;
use App\Http\Middleware\RateLimitAuthMiddleware;
use App\Http\Middleware\RestrictPosSession;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SwitchSuperAdminTenantContext;
use App\Http\Middleware\TenantQuotaMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->redirectGuestsTo(fn () => null);
        $middleware->prepend([
            TrustProxies::class,
            LogRequestActivity::class,
            ProtectAttackMiddleware::class,
        ]);
        $middleware->api(prepend: [
            EnsureApiClient::class,
            AuthenticateJwt::class,
        ]);
        $middleware->api(append: [
            SwitchSuperAdminTenantContext::class,
            CheckMaintenanceMode::class,
            SetLocale::class,
        ]);
        $middleware->alias([
            'role' => EnsureRole::class,
            'workspace' => EnsureWorkspace::class,
            'pos_scope' => RestrictPosSession::class,
            'meeting_access' => AuthorizeMeetingAccess::class,
            'quota' => TenantQuotaMiddleware::class,
            'api_key' => ApiKeyMiddleware::class,
            'api_client' => ApiClientMiddleware::class,
            'auth_throttle' => RateLimitAuthMiddleware::class,
            'protect_attack' => ProtectAttackMiddleware::class,
            'module' => CheckModuleAccess::class,
            'tenant_switch' => SwitchSuperAdminTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = $request->header('X-Request-ID')
                ?: $request->attributes->get('request_id')
                ?: (string) Str::uuid();

            if (
                $e instanceof AuthorizationException
                || $e instanceof AccessDeniedHttpException
            ) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCESS_DENIED',
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                    'request_id' => $requestId,
                ], 403);
            }

            if ($e instanceof UniqueConstraintViolationException) {
                Log::warning('Database unique constraint violation', [
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

            if ($e instanceof QueryException) {
                Log::error('Database query failure', [
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
                && ! $e instanceof ValidationException
                && ! $e instanceof AuthenticationException
                && ! $e instanceof HttpResponseException
                && ! $e instanceof HttpExceptionInterface
            ) {
                Log::error('Unhandled API exception', [
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
