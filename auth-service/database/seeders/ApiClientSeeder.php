<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use Illuminate\Database\Seeder;

class ApiClientSeeder extends Seeder
{
    /**
     * Seed enterprise API clients for standard platform applications.
     */
    public function run(): void
    {
        $clients = [
            [
                'uuid' => 'c0deb41d-0001-4000-8000-000000000001',
                'name' => 'CodeBridges Web Admin',
                'client_key' => 'client_web_admin_cb2026',
                'client_type' => 'first_party',
                'is_active' => true,
            ],
            [
                'uuid' => 'c0deb41d-0002-4000-8000-000000000002',
                'name' => 'CodeBridges POS Flutter Terminal',
                'client_key' => 'client_pos_flutter_cb2026',
                'client_type' => 'public',
                'is_active' => true,
            ],
            [
                'uuid' => 'c0deb41d-0003-4000-8000-000000000003',
                'name' => 'CodeBridges Mobile Runner',
                'client_key' => 'client_mobile_runner_cb2026',
                'client_type' => 'public',
                'is_active' => true,
            ],
        ];

        foreach ($clients as $clientData) {
            ApiClient::updateOrCreate(
                ['client_key' => $clientData['client_key']],
                $clientData
            );
        }
    }
}
