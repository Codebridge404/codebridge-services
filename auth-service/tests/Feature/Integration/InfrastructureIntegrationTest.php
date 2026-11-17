<?php

namespace Tests\Feature\Integration;

use App\Services\ImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\IntegrationTestCase;

class InfrastructureIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.mysql.database') === ':memory:') {
            config(['database.connections.mysql.database' => 'codebridge_auth']);
            DB::purge('mysql');
        }
    }

    /**
     * Helper to determine if MySQL container is reachable.
     */
    protected function isMysqlAvailable(): bool
    {
        try {
            if (config('database.connections.mysql.database') === ':memory:') {
                config(['database.connections.mysql.database' => 'codebridge_auth']);
                DB::purge('mysql');
            }
            DB::connection('mysql')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Helper to determine if Redis container is reachable.
     */
    protected function isRedisAvailable(): bool
    {
        try {
            return (bool) Redis::connection()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Helper to determine if MinIO S3 container is reachable.
     */
    protected function isMinioAvailable(): bool
    {
        try {
            $endpoint = config('filesystems.disks.s3.endpoint');

            return ! empty($endpoint) && @fsockopen(parse_url($endpoint, PHP_URL_HOST), parse_url($endpoint, PHP_URL_PORT) ?: 9000, $errno, $errstr, 1) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 1. Real MySQL database transaction & isolation integration.
     */
    public function test_mysql_real_connection_and_transaction_isolation(): void
    {
        $this->assertTrue(
            $this->isMysqlAvailable(),
            'Integration Gate Failure: Real MySQL database (auth-mysql:3306) is not reachable.'
        );

        $conn = DB::connection('mysql');
        $conn->beginTransaction();

        $testEmail = 'itest_user_'.Str::random(10).'@codebridge.dev';
        $insertedId = $conn->table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'MySQL Integration Probe',
            'email' => $testEmail,
            'password' => bcrypt('password123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertIsNumeric($insertedId);
        $this->assertTrue($conn->table('users')->where('id', $insertedId)->exists());

        // Verify transaction isolation with rollback
        $conn->rollBack();
        $this->assertFalse($conn->table('users')->where('id', $insertedId)->exists(), 'Transaction rollback failed to discard uncommitted record');
    }

    /**
     * 2. Real Redis cache & atomic Lua script execution.
     */
    public function test_redis_real_connection_and_atomic_script_execution(): void
    {
        $this->assertTrue(
            $this->isRedisAvailable(),
            'Integration Gate Failure: Real Redis cache service (auth-redis:6379) is not reachable.'
        );

        $redis = Redis::connection();
        $key = 'codebridge:integration:atomic_test_'.Str::random(8);

        // Test real key setting with TTL
        $redis->setex($key, 60, 'probe_value');
        $this->assertSame('probe_value', $redis->get($key));

        // Test real atomic Lua script (atomic get and del)
        $script = <<<'LUA'
local val = redis.call('get', KEYS[1])
if val then
    redis.call('del', KEYS[1])
end
return val
LUA;
        $evalResult = $redis->eval($script, 1, $key);
        $this->assertSame('probe_value', $evalResult);

        // Verify key was atomically deleted by Lua script
        $this->assertNull($redis->get($key));
    }

    /**
     * 3. Real Redis Queue (Horizon backing store) job push and dequeue.
     */
    public function test_redis_horizon_queue_integration(): void
    {
        $this->assertTrue(
            $this->isRedisAvailable(),
            'Integration Gate Failure: Real Redis queue service (auth-redis:6379) is not reachable.'
        );

        $redis = Redis::connection();
        $queueKey = 'queues:integration_probe_'.Str::random(6);
        $payload = json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\IntegrationProbeJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'timeout' => 60,
            'data' => ['command' => serialize(new \stdClass)],
        ]);

        $redis->rpush($queueKey, $payload);
        $this->assertSame(1, $redis->llen($queueKey));

        $popped = $redis->lpop($queueKey);
        $this->assertSame($payload, $popped);
        $this->assertSame(0, $redis->llen($queueKey));
    }

    /**
     * 4. Real MinIO S3 media upload, retrieval, and cleanup (no Storage::fake).
     */
    public function test_real_minio_s3_media_upload_and_webp_storage(): void
    {
        $this->assertTrue(
            $this->isMinioAvailable(),
            'Integration Gate Failure: Real MinIO S3 media service (minio:9000) is not reachable.'
        );

        $disk = Storage::disk('s3');
        $fileName = 'test_uploads/integration_'.Str::random(10).'.webp';

        // Generate valid WebP bytes using ImageService
        $service = new ImageService;
        $im = imagecreatetruecolor(30, 30);
        $green = imagecolorallocate($im, 0, 200, 0);
        imagefill($im, 0, 0, $green);
        ob_start();
        imagepng($im);
        $pngBytes = ob_get_clean();
        imagedestroy($im);

        $webpBytes = $service->convertToWebp($pngBytes);
        $this->assertNotNull($webpBytes);

        // Store directly on real MinIO bucket
        $stored = $disk->put($fileName, $webpBytes, 'public');
        $this->assertTrue($stored, 'Failed to store file on MinIO S3 bucket');
        $this->assertTrue($disk->exists($fileName), 'File does not exist on MinIO S3 bucket');

        // Verify retrieval and file integrity
        $retrievedBytes = $disk->get($fileName);
        $this->assertSame($webpBytes, $retrievedBytes);

        // Verify public URL generation
        $url = $disk->url($fileName);
        $this->assertStringContainsString($fileName, $url);

        // Clean up
        $disk->delete($fileName);
        $this->assertFalse($disk->exists($fileName), 'Failed to delete file from MinIO S3 bucket');
    }
}
