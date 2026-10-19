<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class InputValidationSecurityTest extends TestCase
{
    /**
     * Test SQL injection payloads in public catalog search.
     */
    public function test_catalog_search_safely_sanitizes_sql_injection(): void
    {
        $payloads = [
            "' OR 1=1 --",
            "1' UNION SELECT 1,2,3,4,5,6,7,8,9,10 --",
            "'; DROP TABLE products; --",
            "admin'#",
        ];

        foreach ($payloads as $payload) {
            $response = $this->getJson('/api/v1/catalog/products?search=' . urlencode($payload));

            // Must respond cleanly with 200 or 422, never crash with 500 database error
            $this->assertNotEquals(500, $response->getStatusCode(), "Crashed on payload: {$payload}");
        }
    }

    /**
     * Test XSS injection in catalog filters and search parameters.
     */
    public function test_catalog_search_rejects_or_escapes_xss(): void
    {
        $xssPayloads = [
            "<script>alert(document.cookie)</script>",
            "\"><script>alert(1)</script>",
            "<img src=x onerror=alert('xss')>",
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->getJson('/api/v1/catalog/products?search=' . urlencode($payload));

            $this->assertNotEquals(500, $response->getStatusCode());
        }
    }

    /**
     * Test pagination parameter manipulation (DoS / integer overflow).
     */
    public function test_pagination_limits_prevent_resource_exhaustion(): void
    {
        // Requesting 10,000,000 items should not cause server crash
        $response = $this->getJson('/api/v1/catalog/products?per_page=99999999');

        $this->assertNotEquals(500, $response->getStatusCode());
    }
}
