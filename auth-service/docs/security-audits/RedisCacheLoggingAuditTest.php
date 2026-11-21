<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\PasskeyService;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\Security\RedisAndLoggingRemediationTest;

/** Real Redis acceptance checks, isolated from application cache/database data. */
class RedisCacheLoggingAuditTest extends RedisAndLoggingRemediationTest
{
    private ?string $auditPrefix = null;

    protected function setUp(): void
    {
        // phpunit.xml forces array cache here, so the existing base flush never touches Redis.
        parent::setUp();
        if (getenv('RUN_REDIS_CACHE_AUDIT') !== '1' || ! extension_loaded('redis')) {
            $this->markTestSkipped('Opt in inside the auth container with RUN_REDIS_CACHE_AUDIT=1.');
        }
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->auditPrefix = 'cb-cache-log-audit-'.Str::uuid().':';
        $connection = config('database.redis.cache');
        $connection['database'] = 15;
        $connection['options']['prefix'] = $this->auditPrefix;
        // Explicitly prevent a REDIS_URL from overriding the isolated DB selection.
        $connection['url'] = null;
        config([
            'app.debug' => false,
            'database.redis.audit' => $connection,
            'database.redis.revocation' => $connection,
            'cache.default' => 'redis',
            'cache.prefix' => 'cache:',
            'cache.stores.redis.connection' => 'audit',
            'cache.stores.redis.lock_connection' => 'audit',
            'cache.stores.revocation.prefix' => 'revocation:',
        ]);
        Cache::purge('redis');
        Cache::purge('revocation');
        Http::fake();
        $this->assertTrue((bool) Redis::connection('audit')->ping());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->auditPrefix !== null) {
                // Delete only this test's generated namespace. Never use FLUSHDB/flush().
                $client = Redis::connection('audit')->client();
                $cursor = '0';
                do {
                    [$cursor, $keys] = $client->rawCommand('SCAN', $cursor, 'MATCH', $this->auditPrefix.'*', 'COUNT', '1000');
                    if ($keys !== []) {
                        $client->rawCommand('UNLINK', ...$keys);
                    }
                } while ((string) $cursor !== '0');
                $this->assertSame([], $client->rawCommand('KEYS', $this->auditPrefix.'*'));
                config(['cache.default' => 'array']);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_redis_put_read_forget_ttl_and_lock(): void
    {
        $this->assertTrue(Cache::put('probe', ['enabled' => true], 30));
        $this->assertSame(['enabled' => true], Cache::get('probe'));
        $ttl = Redis::connection('audit')->ttl('cache:probe');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);
        $this->assertTrue(Cache::forget('probe'));
        $this->assertNull(Cache::get('probe'));
        $lock = Cache::lock('probe-lock', 5);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock('probe-lock', 5)->get());
        $this->assertTrue($lock->release());
    }

    public function test_real_redis_passkey_grant_and_two_factor_challenge_are_single_use(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $passkeys = app(PasskeyService::class);
        $grant = $passkeys->createReauthGrant($user, 'audit-session', 'passkey_enrollment');
        $this->assertFalse($passkeys->consumeReauthGrantToken($user, 'other-session', $grant, 'passkey_enrollment'));
        $this->assertTrue($passkeys->consumeReauthGrantToken($user, 'audit-session', $grant, 'passkey_enrollment'));
        $this->assertFalse($passkeys->consumeReauthGrantToken($user, 'audit-session', $grant, 'passkey_enrollment'));
        $this->assertFalse(Cache::has('auth:reauth_grant:token:'.$grant));
        $this->assertFalse(Cache::has('auth:reauth_grant:session:'.$user->id.':audit-session:passkey_enrollment'));
        $this->assertFalse(Cache::has('auth:reauth_grant:'.$user->id.':passkey_enrollment'));
        $twoFactor = app(TwoFactorService::class);
        $challenge = $twoFactor->createChallengeToken($user);
        $this->assertNotNull($twoFactor->validateAndConsumeChallengeToken($challenge));
        $this->assertNull($twoFactor->validateAndConsumeChallengeToken($challenge));
    }
}
