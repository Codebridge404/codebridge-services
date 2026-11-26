<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\LogRequestActivity;
use App\Http\Middleware\ProtectAttackMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->api(prepend: [AuthenticateJwt::class]);
        $middleware->api(append: [SetLocale::class]);
        $middleware->append(LogRequestActivity::class);
        $middleware->append(ProtectAttackMiddleware::class);
        $middleware->alias([
            'role' => EnsureRole::class,
            'protect_attack' => ProtectAttackMiddleware::class,
            'module' => CheckModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
