<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\User;
use App\Services\CloudinaryService;
use App\Support\LogRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepFiveHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cors_trusted_proxy_s3_path_style_and_bootstrap_hardening(): void
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

        $this->assertTrue((bool) config('filesystems.disks.s3.use_path_style_endpoint'));

        $bootstrap = File::get(base_path('bootstrap/app.php'));
        $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', $bootstrap);
        $this->assertStringContainsString('ProtectAttackMiddleware', $bootstrap);
    }

    public function test_svg_and_disguised_markup_uploads_are_rejected_in_cloudinary_service_products_and_brands(): void
    {
        Storage::fake('s3');
        Config::set('filesystems.disks.s3.bucket', 'test-ops-bucket');

        $svgBody = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $svgDataUri = 'data:image/svg+xml;base64,' . base64_encode($svgBody);
        $disguisedPngDataUri = 'data:image/png;base64,' . base64_encode($svgBody);

        $cloudinary = new CloudinaryService();

        $badInputs = [
            UploadedFile::fake()->createWithContent('bad.svg', $svgBody),
            UploadedFile::fake()->createWithContent('disguised.png', $svgBody),
            $svgDataUri,
            $disguisedPngDataUri,
            'javascript:alert(1)',
            'https://cdn.example.test/icon.svg',
        ];

        foreach ($badInputs as $bad) {
            $threw = false;
            try {
                $cloudinary->upload($bad, 'products');
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'CloudinaryService::upload must reject SVG/markup/javascript image sources.');
        }

        // Control check: genuine PNG bytes upload cleanly
        $im = imagecreatetruecolor(20, 20);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 150, 80));
        ob_start();
        imagepng($im);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($im);
        $pngDataUri = 'data:image/png;base64,' . base64_encode($pngBytes);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->tenant_id = 'tenant-101';
        Sanctum::actingAs($admin, ['*']);

        $product = Product::create([
            'tenant_id' => 'tenant-101',
            'name' => 'Step 5 Latte',
            'sku' => 'S5-LATTE',
            'selling_price' => 4.25,
            'is_active' => true,
        ]);

        // Product uploadImage rejections
        $this->postJson("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->createWithContent('bad.svg', $svgBody),
        ])->assertStatus(422);

        $this->postJson("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->createWithContent('disguised.png', $svgBody),
        ])->assertStatus(422);

        $this->postJson("/api/v1/products/{$product->id}/image", [
            'image_url' => $svgDataUri,
        ])->assertStatus(422);

        $this->postJson("/api/v1/products/{$product->id}/image", [
            'image_url' => $disguisedPngDataUri,
        ])->assertStatus(422);

        // Brand store & update rejections + valid base64 control check
        $this->postJson('/api/v1/brands', [
            'name' => 'Bad Brand SVG URI',
            'logo' => $svgDataUri,
        ])->assertStatus(422);

        $this->postJson('/api/v1/brands', [
            'name' => 'Bad Brand SVG URL',
            'logo' => 'https://evil.example.test/brand.svg',
        ])->assertStatus(422);

        $brandRes = $this->postJson('/api/v1/brands', [
            'name' => 'Safe Brand',
            'logo' => $pngDataUri,
        ])->assertStatus(201);
        $brandId = $brandRes->json('data.id');

        $this->putJson("/api/v1/brands/{$brandId}", [
            'logo' => 'https://evil.example.test/brand-update.svgz',
        ])->assertStatus(422);
    }

    public function test_protect_attack_middleware_blocks_threats_and_injects_security_headers(): void
    {
        $this->withHeaders(['User-Agent' => 'sqlmap/1.5.4'])
            ->getJson('/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ATTACK_DETECTED');

        $this->withHeaders(['User-Agent' => 'CodeBridgeTestClient/1.0']);

        $this->get('/api/v1/health?q=hello%00world')
            ->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_URI');

        $this->getJson('/api/v1/health?file=../../../../etc/passwd')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PATH_TRAVERSAL_DETECTED');

        $this->getJson('/api/v1/health?search=coffee;+whoami')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMMAND_INJECTION_DETECTED');

        $ok = $this->getJson('/api/v1/health')->assertStatus(200);
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');
        $ok->assertHeader('X-Frame-Options', 'DENY');
        $ok->assertHeader('X-XSS-Protection', '1; mode=block');
        $ok->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $ok->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $ok->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_request_id_propagation_and_recursive_log_redaction(): void
    {
        $auto = $this->getJson('/api/v1/health')->assertStatus(200);
        $this->assertNotEmpty($auto->headers->get('X-Request-ID'));
        $this->assertNotEmpty($auto->headers->get('X-Trace-ID'));

        $echo = $this->withHeaders([
            'X-Request-ID' => 'req-ops-step5-777',
            'X-Trace-ID' => 'trace-ops-step5-777',
        ])->getJson('/api/v1/health')->assertStatus(200);

        $echo->assertHeader('X-Request-ID', 'req-ops-step5-777');
        $echo->assertHeader('X-Trace-ID', 'trace-ops-step5-777');

        $redactedQuery = LogRedactor::redactString('/api/v1/products?token=ops_q_secret&pin_code=9876&company_code=OPS1');
        $this->assertStringNotContainsString('ops_q_secret', $redactedQuery);
        $this->assertStringNotContainsString('9876', $redactedQuery);
        $this->assertStringContainsString('company_code=OPS1', $redactedQuery);

        $tempLog = storage_path('framework/testing/ops_redact_' . uniqid('', true) . '.log');
        File::ensureDirectoryExists(dirname($tempLog));
        $prevPath = config('logging.channels.single.path');
        $prevDefault = config('logging.default');
        $prevLevel = config('logging.channels.single.level');

        Config::set('logging.default', 'single');
        Config::set('logging.channels.single.path', $tempLog);
        Config::set('logging.channels.single.level', 'info');
        Log::forgetChannel('single');

        try {
            $this->postJson('/api/v1/coupons/validate?token=ops_url_secret&pin_code=5678&company_code=OP99', [
                'code' => 'SUMMER10',
                'payment' => [
                    'cvv' => '741',
                    'card_number' => '5500112233445566',
                ],
                'credentials' => [
                    'bot_token' => 'ops-telegram-secret',
                ],
            ]);

            $logContents = File::exists($tempLog) ? File::get($tempLog) : '';
            $this->assertNotEmpty($logContents);
            $this->assertStringNotContainsString('ops_url_secret', $logContents);
            $this->assertStringNotContainsString('5678', $logContents);
            $this->assertStringNotContainsString('5500112233445566', $logContents);
            $this->assertStringNotContainsString('ops-telegram-secret', $logContents);
            $this->assertStringNotContainsString('"cvv":"741"', $logContents);
            $this->assertStringContainsString('company_code=OP99', $logContents);
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

    public function test_health_fails_closed_when_cache_fails_and_backup_command_encrypts(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true);

        $prevCacheDefault = config('cache.default');
        Config::set('cache.stores.failing_test', ['driver' => 'null']);
        Config::set('cache.default', 'failing_test');

        try {
            $this->getJson('/api/v1/health')
                ->assertStatus(503)
                ->assertJsonPath('status', 'unhealthy')
                ->assertJsonPath('checks.database', true)
                ->assertJsonPath('checks.cache', false);
        } finally {
            Config::set('cache.default', $prevCacheDefault);
            Cache::forgetDriver('failing_test');
        }

        Storage::fake('local');
        Storage::fake('s3');
        Config::set('filesystems.disks.s3.key', 'test-ops-s3-key');

        $this->artisan('backup:database-encrypted', [
            '--destination' => 'minio',
            '--retention' => 7,
        ])->assertExitCode(0);

        $localFiles = Storage::disk('local')->files('backups');
        $s3Files = Storage::disk('s3')->files('backups');
        $encFiles = array_values(array_filter($localFiles, fn (string $f) => str_ends_with($f, '.sql.gz.enc')));

        $this->assertCount(1, $encFiles);
        $this->assertSameSize($localFiles, $s3Files);

        $ciphertext = (string) Storage::disk('s3')->get($encFiles[0]);
        $this->assertStringNotContainsString('CREATE TABLE', $ciphertext);
        $decryptedGzip = Crypt::decrypt($ciphertext, false);
        $plainSql = gzdecode($decryptedGzip);
        $this->assertIsString($plainSql);
        $this->assertStringContainsString('CodeBridge Encrypted Database Backup', $plainSql);
    }
}
