<?php

namespace App\Providers;

use App\Support\TelemetryEmitter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Dedoc\Scramble\Scramble;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }

        // Register Core Domain Model Policies
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\Tenant::class, \App\Policies\TenantPolicy::class);
        Gate::policy(\App\Models\Outlet::class, \App\Policies\OutletPolicy::class);
        Gate::policy(\App\Models\Role::class, \App\Policies\RolePolicy::class);
        Gate::policy(\App\Models\SecurityEvent::class, \App\Policies\SecurityEventPolicy::class);

        if (!$this->app->bound('_telemetry_db_listener_registered')) {
            $this->app->instance('_telemetry_db_listener_registered', true);

            DB::listen(static function (QueryExecuted $query): void {
                TelemetryEmitter::recordQuery(
                    connection: (string) $query->connectionName,
                    sql: (string) $query->sql,
                    durationMs: (float) $query->time,
                    bindingsCount: is_array($query->bindings) ? count($query->bindings) : 0
                );
            });
        }

        if (class_exists(Scramble::class)) {
            // Allow public viewing of API documentation on cloud deployments
            Gate::define('viewApiDocs', fn ($user = null) => true);

            Scramble::configure()
                ->routes(fn ($route) => str_starts_with($route->uri(), 'api/'))
                ->withOperationTransformers(function (\Dedoc\Scramble\Configuration\OperationTransformers $transformers) {
                    $transformers->append(function (\Dedoc\Scramble\Support\Generator\Operation $operation, \Dedoc\Scramble\Support\RouteInfo $routeInfo) {
                        $uri = $routeInfo->route->uri();
                        $middlewares = collect($routeInfo->route->gatherMiddleware());
                        $isAuthProtected = $middlewares->some(fn ($m) => \Illuminate\Support\Str::is(['auth', 'auth:*'], $m));
                        $isExempt = \Illuminate\Support\Str::is([
                            '*email/verify/*',
                            '*payment-callbacks*',
                            '*payments/webhook*',
                            '*health*',
                            '*.well-known*',
                            '*assetlinks*',
                            '*apple-app-site-association*',
                            '*passkey-test*',
                            'health',
                            'up',
                        ], $uri);

                        if ($isExempt) {
                            $operation->security = [];
                        } elseif ($isAuthProtected) {
                            $operation->security = [
                                new \Dedoc\Scramble\Support\Generator\SecurityRequirement([
                                    'appKeyAuth' => [],
                                    'bearerAuth' => [],
                                ]),
                            ];
                        } else {
                            $operation->security = [
                                new \Dedoc\Scramble\Support\Generator\SecurityRequirement([
                                    'appKeyAuth' => [],
                                ]),
                            ];
                        }
                    });
                })
                ->afterOpenApiGenerated(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
                    $appKeyScheme = \Dedoc\Scramble\Support\Generator\SecurityScheme::apiKey('header', 'X-App-Key')
                        ->as('appKeyAuth')
                        ->setDescription('Active registered API Client Key (e.g. client_web_admin_cb2026). Required on all microservice API calls.');
                    $openApi->components->addSecurityScheme('appKeyAuth', $appKeyScheme);

                    $bearerScheme = \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'JWT')
                        ->as('bearerAuth')
                        ->setDescription('Enter your Sanctum access_token or Bearer token');
                    $openApi->components->addSecurityScheme('bearerAuth', $bearerScheme);

                    $openApi->security = [
                        new \Dedoc\Scramble\Support\Generator\SecurityRequirement(['appKeyAuth' => []]),
                    ];
                });
        }
    }
}
