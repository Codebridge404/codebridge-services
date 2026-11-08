<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('api_clients')) {
                $client = \App\Models\ApiClient::firstOrCreate(
                    ['client_key' => 'client_test_suite_key'],
                    [
                        'name' => 'Test Suite Client',
                        'client_type' => 'server',
                        'is_active' => true,
                    ]
                );
                $this->withHeader('X-App-Key', $client->client_key);
            }
        } catch (\Throwable $e) {
            // Ignore during early schema bootstrap
        }
    }
}
