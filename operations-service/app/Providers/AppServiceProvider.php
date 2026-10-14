<?php

namespace App\Providers;

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
        if (app()->environment('production') || env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (class_exists(Scramble::class)) {
            Gate::define('viewApiDocs', fn ($user = null) => true);

            Scramble::configure()
                ->routes(fn ($route) => str_starts_with($route->uri(), 'api/'));
        }
    }
}
