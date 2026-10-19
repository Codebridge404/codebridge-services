<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Get the current connection name for the model.
     * In a microservice architecture (Database-per-service),
     * tokens are issued and managed by Auth Service in `codebridge_auth`.
     */
    public function getConnectionName()
    {
        if (config('database.default') === 'sqlite') {
            return parent::getConnectionName();
        }

        return config('database.connections.auth_db') ? 'auth_db' : parent::getConnectionName();
    }
}
