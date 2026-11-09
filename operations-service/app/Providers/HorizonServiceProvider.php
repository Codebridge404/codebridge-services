<?php

namespace App\Providers;

use App\Services\TenantAuthorizationService;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if ($user === null) {
                return false;
            }

            if (TenantAuthorizationService::hasPlatformSuperAdminRole($user)) {
                return true;
            }

            $allowedEmails = array_map(
                'strtolower',
                (array) config('horizon.allowed_emails', [])
            );

            $userEmail = strtolower((string) ($user->email ?? ''));

            return $userEmail !== '' && in_array($userEmail, $allowedEmails, true);
        });
    }
}
