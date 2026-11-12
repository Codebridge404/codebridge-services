<?php

namespace Tests\Feature;

use Tests\TestCase;

class GatewayRoutingParityTest extends TestCase
{
    public function test_gateway_and_k8s_nginx_routes_remain_synced_and_collision_free(): void
    {
        $repoRoot = dirname(base_path());
        $authConf = file_get_contents($repoRoot . '/gateway/services/auth-service.conf');
        $opsConf = file_get_contents($repoRoot . '/gateway/services/operations-service.conf');
        $k8sConfigMap = file_get_contents($repoRoot . '/k8s/staging/configmaps.yaml');

        $this->assertIsString($authConf);
        $this->assertIsString($opsConf);
        $this->assertIsString($k8sConfigMap);

        foreach (['/api/v1/security-events', '/api/v1/request-logs', '/api/v1/refresh-tokens', '/api/v1/user-sessions', '/api/v1/telegram-alerts'] as $route) {
            $this->assertStringContainsString($route, $authConf);
            $this->assertStringContainsString($route, $k8sConfigMap);
        }

        $this->assertStringContainsString('location = /api/v1/admin/dashboard', $authConf);
        $this->assertStringContainsString('location /api/v1/admin/dashboard/', $opsConf);
        $this->assertStringContainsString('location /api/v1/pos-bff', $opsConf);

        $this->assertStringContainsString('location = /api/v1/admin/dashboard', $k8sConfigMap);
        $this->assertStringContainsString('location /api/v1/admin/dashboard/', $k8sConfigMap);
        $this->assertStringContainsString('location /api/v1/pos-bff', $k8sConfigMap);

        // Ensure deploy/staging/update-k3s.sh inlines all gateway/services/*.conf includes cleanly
        $updateK3s = file_get_contents($repoRoot . '/deploy/staging/update-k3s.sh');
        $this->assertIsString($updateK3s);
        $this->assertStringContainsString('/api/v1/security-events', $k8sConfigMap);
        $this->assertStringContainsString("assert '/etc/nginx/services/' not in nginx", $updateK3s);
    }
}

