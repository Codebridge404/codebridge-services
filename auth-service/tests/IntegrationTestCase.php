<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class IntegrationTestCase extends BaseTestCase
{
    /**
     * Dedicated base test case for live infrastructure integration testing.
     *
     * Unlike unit/feature Tests\TestCase:
     * - Does NOT call Cache::flush() to prevent FLUSHDB on live Redis instances.
     * - Does NOT mutate or insert test ApiClient entities into persistent databases.
     * - Keeps infrastructure integration testing isolated, safe, and idempotent.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }
}
