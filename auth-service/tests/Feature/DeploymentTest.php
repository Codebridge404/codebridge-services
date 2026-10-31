<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    /**
     * Test application internal health endpoint returns healthy.
     */
    public function test_application_health_endpoint_returns_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'healthy',
                'service' => 'auth-service',
            ]);
    }

    /**
     * Test VPS Blue-Green deployment and rollback scripts exist and are executable.
     */
    public function test_bluegreen_scripts_exist_and_are_executable(): void
    {
        $bgDir = base_path('deploy/bluegreen');

        $this->assertDirectoryExists($bgDir);

        $scripts = [
            'deploy.sh',
            'rollback.sh',
            'traffic.sh',
        ];

        foreach ($scripts as $script) {
            $path = "{$bgDir}/{$script}";
            $this->assertFileExists($path, "Expected {$script} to exist");
            $this->assertTrue(is_executable($path), "Expected {$script} to have executable permissions");
        }

        $this->assertFileExists(base_path('docker-compose.bluegreen.yml'));
        $this->assertFileExists("{$bgDir}/nginx/nginx.conf");
        $this->assertFileExists("{$bgDir}/nginx/upstream-blue.conf");
        $this->assertFileExists("{$bgDir}/nginx/upstream-green.conf");
    }

    /**
     * Test Blue-Green deployment bash scripts have valid syntax.
     */
    public function test_bluegreen_scripts_syntax_is_valid(): void
    {
        $bgDir = base_path('deploy/bluegreen');
        $scripts = ['deploy.sh', 'rollback.sh', 'traffic.sh'];

        foreach ($scripts as $script) {
            $path = "{$bgDir}/{$script}";
            $result = Process::run("bash -n " . escapeshellarg($path));
            $this->assertTrue($result->successful(), "Syntax error in {$script}: " . $result->errorOutput());
        }
    }

    /**
     * Test Blue-Green compose file uses immutable release images and avoids host code bind mounts.
     */
    public function test_bluegreen_compose_uses_immutable_images(): void
    {
        $composePath = base_path('docker-compose.bluegreen.yml');
        $content = File::get($composePath);

        $this->assertStringContainsString('codebridge-auth-service:blue', $content);
        $this->assertStringContainsString('codebridge-auth-service:green', $content);
        $this->assertStringNotContainsString('auth-vendor-blue', $content, 'Vendor volumes must not be mounted across slots');
        $this->assertStringNotContainsString('auth-vendor-green', $content, 'Vendor volumes must not be mounted across slots');
    }

    /**
     * Test Blue-Green zero-downtime deployment simulation and failure rollback protection.
     */
    public function test_bluegreen_deploy_workflow_simulation(): void
    {
        $tempDir = storage_path('framework/testing/bg_sim_' . uniqid());
        File::makeDirectory($tempDir, 0755, true);

        try {
            $scriptsDir = "{$tempDir}/deploy/bluegreen";
            File::copyDirectory(base_path('deploy/bluegreen'), $scriptsDir);
            File::put("{$scriptsDir}/.active_color", "blue\n");
            File::put("{$tempDir}/docker-compose.bluegreen.yml", "services: {}\n");

            $binDir = "{$tempDir}/bin";
            File::makeDirectory($binDir, 0755, true);

            $mockDocker = <<<'BASH'
#!/usr/bin/env bash
echo "$@" >> "$MOCK_LOG"
ARGS="$*"
if [[ "$ARGS" == *"ps -o args"* ]]; then
    exit 1
fi
if [[ "$MOCK_FAILURE" == "readiness" && "$ARGS" == *"fsockopen"* ]]; then
    exit 1
fi
if [[ "$MOCK_FAILURE" == "proxy" && "$ARGS" == *"http://auth-proxy/health"* ]]; then
    exit 1
fi
exit 0
BASH;
            File::put("{$binDir}/docker", $mockDocker);
            chmod("{$binDir}/docker", 0755);

            File::put("{$binDir}/sleep", "#!/bin/sh\nexit 0\n");
            chmod("{$binDir}/sleep", 0755);

            $logFile = "{$tempDir}/docker_calls.log";

            $env = [
                'PATH' => "{$binDir}:" . getenv('PATH'),
                'MOCK_LOG' => $logFile,
                'MOCK_FAILURE' => '',
            ];

            // 1. Successful deploy scenario
            $result = Process::env($env)->run("bash {$scriptsDir}/deploy.sh");
            $this->assertTrue($result->successful(), "deploy.sh simulation failed: " . $result->errorOutput());

            $activeColor = trim(File::get("{$scriptsDir}/.active_color"));
            $this->assertEquals('green', $activeColor, "Deploy should have switched active color to green");

            $calls = File::get($logFile);
            $this->assertStringContainsString('http://auth-proxy/health', $calls);
            $this->assertMatchesRegularExpression('/stop.*auth-service-blue/', $calls);

            $proxyPos = strpos($calls, 'http://auth-proxy/health');
            preg_match('/stop.*auth-service-blue/', $calls, $matches, PREG_OFFSET_CAPTURE);
            $stopPos = $matches[0][1];
            $this->assertLessThan($stopPos, $proxyPos, 'Proxy health check must happen before stopping old slot');

            // 2. Failure abort scenario: old slot is never stopped
            File::put("{$scriptsDir}/.active_color", "blue\n");
            File::delete($logFile);
            $env['MOCK_FAILURE'] = 'proxy';

            $failedResult = Process::env($env)->run("bash {$scriptsDir}/deploy.sh");
            $this->assertFalse($failedResult->successful(), "deploy.sh should fail when proxy check fails");

            $remainingColor = trim(File::get("{$scriptsDir}/.active_color"));
            $this->assertEquals('blue', $remainingColor, "Failed deploy must retain old active color");

            $failedCalls = File::exists($logFile) ? File::get($logFile) : '';
            $this->assertDoesNotMatchRegularExpression('/stop.*auth-service-blue/', $failedCalls, 'Must not stop blue slot on failed deployment');

        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * Test Kubernetes manifests pass client-side dry-run validation via Kustomize.
     */
    public function test_kubernetes_manifests_dry_run_validation(): void
    {
        $k8sDir = base_path('k8s');
        $this->assertDirectoryExists($k8sDir);

        $kubectlCheck = Process::run('command -v kubectl');
        if (! $kubectlCheck->successful()) {
            $this->markTestSkipped('kubectl is not installed on this system.');
        }

        $result = Process::path($k8sDir)->run('kubectl apply --dry-run=client -k .');
        $this->assertTrue($result->successful(), "Kubernetes manifests failed dry-run validation: " . $result->errorOutput());

        $output = $result->output();
        $this->assertStringContainsString('deployment.apps/auth-service', $output);
        $this->assertStringContainsString('service/auth-service', $output);
        $this->assertStringContainsString('cronjob.batch/auth-scheduler', $output);
        $this->assertStringContainsString('service/api-gateway', $output);
    }

    /**
     * Test Kubernetes auth-service deployment spec has HA replicas, probes, and resource constraints.
     */
    public function test_kubernetes_auth_service_ha_spec(): void
    {
        $deployYaml = base_path('k8s/auth-deployment.yaml');
        $this->assertFileExists($deployYaml);

        $content = File::get($deployYaml);
        $this->assertStringContainsString('replicas: 2', $content, 'Auth service must have at least 2 replicas for HA');
        $this->assertStringContainsString('readinessProbe:', $content, 'Readiness probe must be defined');
        $this->assertStringContainsString('livenessProbe:', $content, 'Liveness probe must be defined');
        $this->assertStringContainsString('resources:', $content, 'Resource constraints must be configured');
        $this->assertStringContainsString('limits:', $content);
        $this->assertStringContainsString('requests:', $content);
    }

    /**
     * Test Kubernetes cronjob and database migration job specifications.
     */
    public function test_kubernetes_cronjob_and_migration_specs(): void
    {
        $cronYaml = base_path('k8s/scheduler-cronjob.yaml');
        $this->assertFileExists($cronYaml);
        $cronContent = File::get($cronYaml);
        $this->assertStringContainsString('schedule: "* * * * *"', $cronContent);
        $this->assertStringContainsString('schedule:run', $cronContent);

        $migYaml = base_path('k8s/migration-job.yaml');
        $this->assertFileExists($migYaml);
        $migContent = File::get($migYaml);
        $this->assertStringContainsString('migrate', $migContent);
    }

    /**
     * Test automated backup scripts exist, are executable, and have valid bash syntax.
     */
    public function test_backup_scripts_exist_executable_and_valid(): void
    {
        $backupDir = base_path('deploy/backup');
        $this->assertDirectoryExists($backupDir);

        $scripts = ['backup.sh', 'status.sh', 'restore.sh'];
        foreach ($scripts as $script) {
            $path = "{$backupDir}/{$script}";
            $this->assertFileExists($path);
            $this->assertTrue(is_executable($path), "Expected {$script} to be executable");

            $syntax = Process::run("bash -n " . escapeshellarg($path));
            $this->assertTrue($syntax->successful(), "Syntax error in {$script}: " . $syntax->errorOutput());
        }

        $this->assertFileExists("{$backupDir}/crontab.example");
        $this->assertFileExists("{$backupDir}/README.md");
    }

    /**
     * Test backup status checker script executes and outputs all tiers and storage space.
     */
    public function test_backup_status_checker_execution(): void
    {
        $statusScript = base_path('deploy/backup/status.sh');
        $result = Process::run("bash " . escapeshellarg($statusScript));

        $this->assertTrue($result->successful(), "status.sh failed: " . $result->errorOutput());
        $output = $result->output();

        $this->assertStringContainsString('CodeBridges Backup Health & Status Overview', $output);
        $this->assertStringContainsString('DAILY', $output);
        $this->assertStringContainsString('WEEKLY', $output);
        $this->assertStringContainsString('MONTHLY', $output);
        $this->assertStringContainsString('Storage Disk Space', $output);
    }

    /**
     * Test crontab.example defines daily, weekly, and monthly backup schedules.
     */
    public function test_crontab_schedule_definitions(): void
    {
        $crontabPath = base_path('deploy/backup/crontab.example');
        $content = File::get($crontabPath);

        $this->assertMatchesRegularExpression('/^\s*0\s+2\s+\*\s+\*\s+\*/m', $content, 'Expected daily cron at 02:00 AM');
        $this->assertMatchesRegularExpression('/^\s*0\s+3\s+\*\s+\*\s+0/m', $content, 'Expected weekly cron at 03:00 AM on Sunday');
        $this->assertMatchesRegularExpression('/^\s*0\s+4\s+1\s+\*\s+\*/m', $content, 'Expected monthly cron at 04:00 AM on 1st of month');
    }

    /**
     * Test live Kubernetes cluster health and gateway routing if cluster is running.
     */
    public function test_live_kubernetes_cluster_if_available(): void
    {
        $checkCluster = Process::run('kubectl cluster-info');
        if (! $checkCluster->successful()) {
            $this->markTestSkipped('Kubernetes cluster is not currently reachable.');
        }

        // Check pods in codebridge namespace
        $podsResult = Process::run('kubectl get pods -n codebridge -o json');
        $this->assertTrue($podsResult->successful(), "Failed to get pods: " . $podsResult->errorOutput());

        $podsData = json_decode($podsResult->output(), true);
        $this->assertNotEmpty($podsData['items'] ?? [], 'Namespace codebridge should have active pods');

        foreach ($podsData['items'] as $pod) {
            $podName = $pod['metadata']['name'];
            $phase = $pod['status']['phase'];
            $this->assertContains($phase, ['Running', 'Succeeded'], "Pod {$podName} has unexpected phase: {$phase}");
        }

        // Test auth-service health probe inside cluster
        $healthCmd = 'kubectl exec -n codebridge deployment/auth-service -c nginx -- curl -s http://127.0.0.1/api/v1/health';
        $healthResult = Process::run($healthCmd);
        $this->assertTrue($healthResult->successful());
        $healthJson = json_decode($healthResult->output(), true);
        $this->assertEquals('healthy', $healthJson['status'] ?? null);
        $this->assertEquals('auth-service', $healthJson['service'] ?? null);

        // Test API Gateway routing to Auth Service
        $gwCmd = 'kubectl exec -n codebridge deployment/api-gateway -- curl -s -X POST http://127.0.0.1/api/v1/auth/login -H "Accept: application/json" -H "X-Forwarded-Proto: https" -d "{}"';
        $gwResult = Process::run($gwCmd);
        $this->assertTrue($gwResult->successful());
        $output = $gwResult->output();
        $this->assertTrue(
            str_contains($output, 'The email field is required') || str_contains($output, 'ACCOUNT_LOCKED_OUT'),
            "Expected Laravel Auth response from gateway, got: {$output}"
        );
    }
}
