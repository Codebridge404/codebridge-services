<?php

namespace App\Providers;

use App\Models\Auth\User;
use App\Models\Organization\Outlet;
use App\Models\Organization\Tenant;
use App\Models\Security\SecurityEvent;
use App\Models\System\Role;
use App\Policies\OutletPolicy;
use App\Policies\RolePolicy;
use App\Policies\SecurityEventPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Support\TelemetryEmitter;
use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('reauthentication', static function (Request $request): array {
            $response = static function (Request $request, array $headers): JsonResponse {
                return response()->json([
                    'status' => 'error',
                    'code' => 'REAUTHENTICATION_RATE_LIMITED',
                    'message' => 'Too many reauthentication attempts. Please try again later.',
                    'retry_after_seconds' => (int) $headers['Retry-After'],
                    'request_id' => $request->header('X-Request-ID')
                        ?: $request->attributes->get('request_id')
                        ?: (string) Str::uuid(),
                ], 429, array_merge($headers, ['Cache-Control' => 'no-store']));
            };

            // Count all verification attempts before checking credentials. Caller-supplied
            // identifiers, new sessions, and successful options requests cannot reset these limits.
            return [
                Limit::perMinutes(15, 5)
                    ->by('user:'.$request->user()->getAuthIdentifier())
                    ->response($response),
                Limit::perMinute(30)
                    ->by('ip:'.$request->ip())
                    ->response($response),
            ];
        });

        if (app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }

        // Backward-compatible polymorphic mapping for User identity across model refactors
        Relation::morphMap([
            'App\Models\Auth\User' => User::class,
            'App\Models\User' => User::class,
            'user' => User::class,
        ]);

        // Register Core Domain Model Policies
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Outlet::class, OutletPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(SecurityEvent::class, SecurityEventPolicy::class);

        if (! $this->app->bound('_telemetry_db_listener_registered')) {
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
                ->withOperationTransformers(function (OperationTransformers $transformers) {
                    $transformers->append(function (Operation $operation, RouteInfo $routeInfo) {
                        $uri = $routeInfo->route->uri();
                        $middlewares = collect($routeInfo->route->gatherMiddleware());
                        $isAuthProtected = $middlewares->some(fn ($m) => Str::is(['auth', 'auth:*'], $m));
                        $isExempt = Str::is([
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
                                new SecurityRequirement([
                                    'appKeyAuth' => [],
                                    'bearerAuth' => [],
                                ]),
                            ];
                        } else {
                            $operation->security = [
                                new SecurityRequirement([
                                    'appKeyAuth' => [],
                                ]),
                            ];
                        }
                    });
                })
                ->afterOpenApiGenerated(function (OpenApi $openApi) {
                    $appKeyScheme = SecurityScheme::apiKey('header', 'X-App-Key')
                        ->as('appKeyAuth')
                        ->setDescription('Active registered API Client Key (e.g. client_web_admin_cb2026). Required on all microservice API calls.');
                    $openApi->components->addSecurityScheme('appKeyAuth', $appKeyScheme);

                    $bearerScheme = SecurityScheme::http('bearer', 'JWT')
                        ->as('bearerAuth')
                        ->setDescription('Enter your Sanctum access_token or Bearer token');
                    $openApi->components->addSecurityScheme('bearerAuth', $bearerScheme);

                    $openApi->security = [
                        new SecurityRequirement(['appKeyAuth' => []]),
                    ];
                });
        }
    }
}
