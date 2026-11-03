<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * [BD-0128] Clean Production Tenant Seeder.
 * Sample demo organizations removed to ensure clean VPS deployment.
 * All organizations are provisioned organically via workspace registration.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // Sample demo organizations removed per production requirements.
        // Organizations are dynamically registered by tenant owners via the UI/API.
    }
}
