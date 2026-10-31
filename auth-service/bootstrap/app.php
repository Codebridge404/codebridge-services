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
        $middleware->api(prepend: [\App\Http\Middleware\AuthenticateJwt::class]);
        $middleware->append(LogRequestActivity::class);
        $middleware->alias([
            'role' => EnsureRole::class,
            'workspace' => \App\Http\Middleware\EnsureWorkspace::class,
            'pos_scope' => \App\Http\Middleware\RestrictPosSession::class,
            'meeting_access' => \App\Http\Middleware\AuthorizeMeetingAccess::class,
            'quota' => TenantQuotaMiddleware::class,
            'api_key' => ApiKeyMiddleware::class,
            'auth_throttle' => RateLimitAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
