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

                $client = \App\Models\ApiClient::where('client_key', $hashedKey)
                    ->orWhere('client_key', $plainKey)
                    ->first();

                if (!$client) {
                    $client = \App\Models\ApiClient::create([
                        'name' => 'Test Suite Client',
                        'client_key' => $hashedKey,
                        'client_type' => 'server',
                        'is_active' => true,
                    ]);
                } elseif ($client->client_key !== $hashedKey) {
                    $client->update(['client_key' => $hashedKey]);
                }

                // Frontend / Caller sends public app key in X-App-Key header
                $this->withHeader('X-App-Key', $plainKey);
            }
        } catch (\Throwable $e) {
            // Ignore during early schema bootstrap
        }
    }
}
