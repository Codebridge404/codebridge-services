<?php

namespace App\Providers;

use App\Support\TelemetryEmitter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Dedoc\Scramble\Scramble;

use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }

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

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (class_exists(Scramble::class)) {
            Gate::define('viewApiDocs', fn ($user = null) => true);

            Scramble::configure()
                ->routes(fn ($route) => str_starts_with($route->uri(), 'api/'));
        }
    }
}
