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
                        $middlewares = collect($routeInfo->route->gatherMiddleware());
                        $isAuthProtected = $middlewares->some(fn ($m) => \Illuminate\Support\Str::is(['auth', 'auth:*'], $m));

                        if ($isAuthProtected) {
                            $operation->security = [
                                new \Dedoc\Scramble\Support\Generator\SecurityRequirement(['bearerAuth' => []]),
                            ];
                        } else {
                            $operation->security = [];
                        }
                    });
                })
                ->afterOpenApiGenerated(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
                    $scheme = \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'JWT')
                        ->as('bearerAuth')
                        ->setDescription('Enter your Sanctum access_token or Bearer token');
                    $openApi->components->addSecurityScheme('bearerAuth', $scheme);
                    $openApi->security = null;
                });
        }
    }
}
