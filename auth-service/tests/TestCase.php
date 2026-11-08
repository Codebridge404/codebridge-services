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
                $plainKey = 'client_test_suite_key';
                $hashedKey = hash('sha256', $plainKey);

                $client = \App\Models\ApiClient::firstOrCreate(
                    ['client_key' => $hashedKey],
                    [
                        'name' => 'Test Suite Client',
                        'client_type' => 'server',
                        'is_active' => true,
                    ]
                );
                // Frontend's X-App-Key header sends the plain key
                $this->withHeader('X-App-Key', $plainKey);
            }
        } catch (\Throwable $e) {
            // Ignore during early schema bootstrap
        }
    }
}
