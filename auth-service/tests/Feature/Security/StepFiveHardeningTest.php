<?php

namespace Tests\Feature\Security;

use App\Models\ApiRequestLog;
use App\Models\User;
use App\Services\ImageService;
use App\Support\LogRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepFiveHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function validPngUploadedFile(string $name = 'valid.png'): UploadedFile
    {
        $im = imagecreatetruecolor(24, 24);
        $blue = imagecolorallocate($im, 30, 120, 220);
        imagefill($im, 0, 0, $blue);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_cors_trusted_proxy_and_bootstrap_contain_no_wildcard_or_runtime_env_calls(): void
    {
        $this->assertTrue((bool) config('cors.supports_credentials'));

        $patterns = config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsStringIgnoringCase('vercel', (string) $pattern);
        }

        $exposed = config('cors.exposed_headers', []);
        $this->assertContains('X-Request-ID', $exposed);
        $this->assertContains('X-Trace-ID', $exposed);

        $trustedProxies = config('trustedproxy.proxies');
        $this->assertIsArray($trustedProxies);
        $this->assertNotSame('*', $trustedProxies);
        $this->assertNotContains('*', $trustedProxies);
        $this->assertContains('10.0.0.0/8', $trustedProxies);
        $this->assertContains('127.0.0.1', $trustedProxies);

        $bootstrap = File::get(base_path('bootstrap/app.php'));
        $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', $bootstrap);
    }

    public function test_svg_and_polyglot_uploads_are_rejected_across_image_service_and_all_auth_endpoints(): void
    {
        Storage::fake('minio');
        Storage::fake('s3');
        Storage::fake('public');

        $svgBody = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $svgDataUri = 'data:image/svg+xml;base64,' . base64_encode($svgBody);
        $disguisedPngDataUri = 'data:image/png;base64,' . base64_encode($svgBody);

        /** @var ImageService $imageService */
        $imageService = app(ImageService::class);

        $svgFile = UploadedFile::fake()->createWithContent('avatar.svg', $svgBody);
        $disguisedPngFile = UploadedFile::fake()->createWithContent('avatar.png', $svgBody);

        foreach ([$svgFile, $disguisedPngFile, $svgDataUri] as $badSource) {
            $threwAvatar = false;
            try {
                $imageService->storeAvatar($badSource, 999);
            } catch (\InvalidArgumentException) {
                $threwAvatar = true;
            }
            $this->assertTrue($threwAvatar, 'ImageService::storeAvatar must reject SVG/markup payloads.');

            $threwLogo = false;
            try {
                $imageService->storeLogo($badSource, 999);
            } catch (\InvalidArgumentException) {
                $threwLogo = true;
            }
            $this->assertTrue($threwLogo, 'ImageService::storeLogo must reject SVG/markup payloads.');
        }

        // Control check: genuine PNG still converts cleanly
        $validUrl = $imageService->storeAvatar($this->validPngUploadedFile(), 998);
        $this->assertStringEndsWith('.webp', $validUrl);

        // 1. User profile endpoints
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/auth/upload-profile', [
            'avatar' => UploadedFile::fake()->createWithContent('bad.svg', $svgBody),
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/upload-profile', [
            'avatar' => UploadedFile::fake()->createWithContent('disguised.png', $svgBody),
        ])->assertStatus(422);

        $this->putJson('/api/v1/auth/profile', [
            'avatar' => UploadedFile::fake()->createWithContent('bad.svg', $svgBody),
        ])->assertStatus(422);

        $this->putJson('/api/v1/auth/profile', [
            'avatar_url' => $svgDataUri,
        ])->assertStatus(422);

        $this->putJson('/api/v1/auth/profile', [
            'avatar_url' => $disguisedPngDataUri,
        ])->assertStatus(422);

        $this->putJson('/api/v1/auth/profile', [
            'avatar_url' => 'https://evil.example.test/avatar.svg',
        ])->assertStatus(422);

        // 2. Tenant profile endpoint
        $this->putJson('/api/v1/tenants/profile', [
            'logo' => UploadedFile::fake()->createWithContent('tenant.svg', $svgBody),
        ])->assertStatus(422);

        $this->putJson('/api/v1/tenants/profile', [
            'logo_url' => $svgDataUri,
        ])->assertStatus(422);

        $this->putJson('/api/v1/tenants/profile', [
            'logo_url' => 'https://evil.example.test/logo.svg',
        ])->assertStatus(422);

        // 3. Outlet receipt branding endpoint
        $outletId = DB::table('outlets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $admin->tenant_id,
            'name' => 'Step 5 Flagship Outlet',
            'code' => 'S5-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/outlets/{$outletId}/receipt-branding", [
            'logo' => UploadedFile::fake()->createWithContent('receipt.svg', $svgBody),
        ])->assertStatus(422);

        $this->postJson("/api/v1/outlets/{$outletId}/receipt-branding", [
            'logo' => UploadedFile::fake()->createWithContent('receipt.png', $svgBody),
        ])->assertStatus(422);

        $this->postJson("/api/v1/outlets/{$outletId}/receipt-branding", [
            'receipt_logo_url' => $svgDataUri,
        ])->assertStatus(422);

        $this->postJson("/api/v1/outlets/{$outletId}/receipt-branding", [
            'receipt_logo_url' => 'https://evil.example.test/receipt.svg',
        ])->assertStatus(422);

        // 4. Platform modules endpoints (super_admin)
        $superAdmin = User::factory()->inWorkspace()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($superAdmin, ['*']);

        $this->postJson('/api/v1/super-admin/platform-modules', [
            'name' => 'Unsafe SVG Module',
            'slug' => 'unsafe-svg-mod-' . Str::random(5),
            'logo' => UploadedFile::fake()->createWithContent('mod.svg', $svgBody),
        ])->assertStatus(422);

        $this->postJson('/api/v1/super-admin/platform-modules', [
            'name' => 'Unsafe SVG Data Module',
            'slug' => 'unsafe-svg-data-' . Str::random(5),
            'logo_data' => $svgDataUri,
        ])->assertStatus(422);

        $this->postJson('/api/v1/super-admin/platform-modules', [
            'name' => 'Unsafe SVG URL Module',
            'slug' => 'unsafe-svg-url-' . Str::random(5),
            'logo_url' => 'https://evil.example.test/module.svg',
        ])->assertStatus(422);
    }

    public function test_request_id_propagation_and_recursive_log_redaction(): void
    {
        $autoRes = $this->getJson('/api/v1/health')->assertStatus(200);
        $autoReqId = (string) $autoRes->headers->get('X-Request-ID');
        $autoTraceId = (string) $autoRes->headers->get('X-Trace-ID');
        $this->assertNotEmpty($autoReqId);
        $this->assertNotEmpty($autoTraceId);

        $customRes = $this->withHeaders([
            'X-Request-ID' => 'req-auth-step5-999',
            'X-Trace-ID' => 'trace-auth-step5-999',
        ])->getJson('/api/v1/health')->assertStatus(200);

        $customRes->assertHeader('X-Request-ID', 'req-auth-step5-999');
        $customRes->assertHeader('X-Trace-ID', 'trace-auth-step5-999');

        $sanitized = LogRedactor::sanitizeArray([
            'company_code' => 'ACME01',
            'currency_code' => 'USD',
            'pin_code' => '1234',
            'auth' => [
                'access_token' => 'secret-jwt-value',
                'authorization' => 'Bearer top-secret-bearer',
            ],
            'provider' => [
                'credentials' => [
                    'bot_token' => '123456:telegram-secret',
                    'password' => 'smtp-pass-secret',
                ],
            ],
            'payment' => [
                'cvv' => '987',
                'card_number' => '4111111111111111',
            ],
        ]);

        $this->assertSame('ACME01', $sanitized['company_code']);
        $this->assertSame('USD', $sanitized['currency_code']);
        $this->assertSame('********', $sanitized['pin_code']);
        $this->assertSame('********', $sanitized['auth']['access_token']);
        $this->assertSame('********', $sanitized['auth']['authorization']);
        $this->assertSame('********', $sanitized['provider']['credentials']);
        $this->assertSame('********', $sanitized['payment']['cvv']);
        $this->assertSame('********', $sanitized['payment']['card_number']);

        $redactedLine = LogRedactor::redactString(
            'Authorization: Bearer super_secret_token /api/v1/auth/email/verify/42/verifyhash99?token=query_secret&company_code=CO9&pin_code=8888'
        );
        $this->assertStringNotContainsString('super_secret_token', $redactedLine);
        $this->assertStringNotContainsString('verifyhash99', $redactedLine);
        $this->assertStringNotContainsString('query_secret', $redactedLine);
        $this->assertStringNotContainsString('8888', $redactedLine);
        $this->assertStringContainsString('company_code=CO9', $redactedLine);

        // End-to-end Monolog + ApiRequestLog check on HTTP request with nested secrets and sensitive query
        $tempLog = storage_path('framework/testing/auth_redact_' . uniqid('', true) . '.log');
        File::ensureDirectoryExists(dirname($tempLog));
        $prevPath = config('logging.channels.single.path');
        $prevDefault = config('logging.default');
        $prevLevel = config('logging.channels.single.level');

        Config::set('logging.default', 'single');
        Config::set('logging.channels.single.path', $tempLog);
        Config::set('logging.channels.single.level', 'info');
        Log::forgetChannel('single');

        try {
            $this->postJson('/api/v1/auth/login?token=query_tok_secret&company_code=SUNNY01&pin_code=4321', [
                'email' => 'missing.step5@sunnycafe.kh',
                'password' => 'TopSecretPassword123!',
                'nested' => [
                    'bot_token' => 'tg-secret-bot-token',
                    'cvv' => '654',
                ],
            ]);

            $logContents = File::exists($tempLog) ? File::get($tempLog) : '';
            $this->assertNotEmpty($logContents);
            $this->assertStringNotContainsString('query_tok_secret', $logContents);
            $this->assertStringNotContainsString('4321', $logContents);
            $this->assertStringContainsString('company_code=SUNNY01', $logContents);

            Log::info('Nested secret audit check', [
                'authorization' => 'Bearer monolog_bearer_secret',
                'credentials' => ['password' => 'db_secret_pass', 'bot_token' => 'monolog_bot_secret'],
            ]);
            $afterLog = File::get($tempLog);
            $this->assertStringNotContainsString('monolog_bearer_secret', $afterLog);
            $this->assertStringNotContainsString('db_secret_pass', $afterLog);
            $this->assertStringNotContainsString('monolog_bot_secret', $afterLog);

            $loggedRow = ApiRequestLog::latest('id')->first();
            $this->assertNotNull($loggedRow);
            $this->assertSame('********', $loggedRow->query_params['token'] ?? null);
            $this->assertSame('********', $loggedRow->query_params['pin_code'] ?? null);
            $this->assertSame('SUNNY01', $loggedRow->query_params['company_code'] ?? null);
            $this->assertSame('********', $loggedRow->request_data['password'] ?? null);
            $this->assertSame('********', $loggedRow->request_data['nested']['bot_token'] ?? null);
            $this->assertSame('********', $loggedRow->request_data['nested']['cvv'] ?? null);
        } finally {
            Config::set('logging.channels.single.path', $prevPath);
            Config::set('logging.default', $prevDefault);
            Config::set('logging.channels.single.level', $prevLevel);
            Log::forgetChannel('single');
            if (File::exists($tempLog)) {
                File::delete($tempLog);
            }
        }
    }

    public function test_health_endpoint_fails_closed_when_cache_probe_fails(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true);

        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new \RuntimeException('Redis connection refused'));

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', false);
    }

    public function test_encrypted_database_backup_command_and_hardened_infrastructure_manifests(): void
    {
        Storage::fake('local');
        Storage::fake('minio');
        Config::set('filesystems.disks.minio.key', 'test-minio-access-key');

        $this->artisan('backup:database-encrypted', [
            '--destination' => 'minio',
            '--retention' => 7,
        ])->assertExitCode(0);

        $localFiles = Storage::disk('local')->files('backups');
        $minioFiles = Storage::disk('minio')->files('backups');

        $encFiles = array_values(array_filter($localFiles, fn (string $f) => str_ends_with($f, '.sql.gz.enc')));
        $shaFiles = array_values(array_filter($localFiles, fn (string $f) => str_ends_with($f, '.sha256')));

        $this->assertCount(1, $encFiles);
        $this->assertCount(1, $shaFiles);
        $this->assertSameSize($localFiles, $minioFiles);

        $ciphertext = (string) Storage::disk('minio')->get($encFiles[0]);
        $this->assertStringNotContainsString('CREATE TABLE', $ciphertext);
        $this->assertStringNotContainsString('CodeBridge Auth Encrypted Database Backup', $ciphertext);

        $decryptedGzip = Crypt::decrypt($ciphertext, false);
        $plainSql = gzdecode($decryptedGzip);
        $this->assertIsString($plainSql);
        $this->assertStringContainsString('CodeBridge Auth Encrypted Database Backup', $plainSql);

        // Verify hardened non-root Dockerfiles
        $authDockerfile = File::get(base_path('Dockerfile'));
        $this->assertStringContainsString('--no-dev', $authDockerfile);
        $this->assertStringContainsString('USER 33:33', $authDockerfile);

        $opsDockerfile = File::get(dirname(base_path()) . '/operations-service/Dockerfile');
        $this->assertStringContainsString('--no-dev', $opsDockerfile);
        $this->assertStringContainsString('USER 33:33', $opsDockerfile);

        // Verify hardened K8s staging kustomization
        $stagingK8sDir = dirname(base_path()) . '/k8s/staging';
        $kubectlCheck = Process::run('command -v kubectl');
        if ($kubectlCheck->successful() && is_dir($stagingK8sDir)) {
            $createdDummySecret = false;
            $secretFile = $stagingK8sDir . '/secrets.yaml';
            $exampleSecret = $stagingK8sDir . '/secrets.example.yaml';
            if (!file_exists($secretFile) && file_exists($exampleSecret)) {
                @copy($exampleSecret, $secretFile);
                $createdDummySecret = true;
            }

            try {
                $kustomize = Process::path($stagingK8sDir)->run('kubectl kustomize .');
                $this->assertTrue($kustomize->successful(), 'Staging K8s failed kustomize: ' . $kustomize->errorOutput());

                $rendered = $kustomize->output();
                $this->assertStringNotContainsString('PMA_ARBITRARY', $rendered);
                $this->assertStringNotContainsString('npm.servicefixit.me', $rendered);
                $this->assertStringContainsString('runAsUser: 33', $rendered);
                $this->assertStringContainsString('name: operations-scheduler', $rendered);
                $this->assertStringContainsString('name: auth-db-backup', $rendered);
                $this->assertStringContainsString('name: operations-db-backup', $rendered);
                $this->assertStringContainsString('TELEMETRY_ENABLED', $rendered);
            } finally {
                if ($createdDummySecret && file_exists($secretFile)) {
                    @unlink($secretFile);
                }
            }
        }
    }
}
