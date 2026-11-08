<?php

namespace App\Providers;

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
        if (app()->environment('production') || env('APP_ENV') === 'production') {
            URL::forceScheme('https');
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
