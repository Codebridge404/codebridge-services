<?php

namespace Tests\Feature\Security;

use App\Models\Auth\User;
use App\Services\PasskeyService;
use App\Services\TwoFactorService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AtomicAuthenticationFallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email' => 'atomic_test@codebridges.io',
            'password' => Hash::make('SecretPass123!'),
        ]);
    }

    /**
     * Test PasskeyService atomicCachePull fails closed without logging raw secret when Redis eval fails.
     */
    public function test_passkey_atomic_pull_fails_closed_without_logging_raw_secret_on_eval_failure(): void
    {
        $loggedContexts = [];
        $rawLogMessages = [];

        Log::listen(function ($message) use (&$loggedContexts, &$rawLogMessages) {
            $rawLogMessages[] = is_string($message->message) ? $message->message : json_encode($message->message);
            $loggedContexts[] = $message->context;
        });

        // Create a mock RedisStore whose connection throws on eval
        $mockConnection = new class
        {
            public function eval($script, $numkeys, ...$args)
            {
                throw new \RuntimeException('NOPERM this user has no permissions to run the eval command');
            }
        };

        $mockStore = $this->getMockBuilder(RedisStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrefix', 'connection'])
            ->getMock();
        $mockStore->method('getPrefix')->willReturn('codebridge_test:');
        $mockStore->method('connection')->willReturn($mockConnection);

        $repository = new Repository($mockStore);
        Cache::shouldReceive('getStore')->andReturn($mockStore);

        $passkeyService = app(PasskeyService::class);
        $rawSensitiveToken = 'sensitive_raw_grant_token_xyz_998877';
        $cacheKey = "auth:reauth_grant:token:{$rawSensitiveToken}";

        $result = $passkeyService->atomicCachePull($cacheKey);

        // 1. MUST fail closed (null) to preserve single-use guarantee
        $this->assertNull($result, 'atomicCachePull must fail closed when Redis eval fails');

        // 2. Logs must NEVER contain the raw token or raw cache key
        $allLoggedText = implode(' ', $rawLogMessages).' '.json_encode($loggedContexts);
        $this->assertStringNotContainsString($rawSensitiveToken, $allLoggedText, 'Raw authentication token was leaked in logs');
        $this->assertStringNotContainsString($cacheKey, $allLoggedText, 'Raw cache key was leaked in logs');

        // 3. Safe truncated key hash must be present in log context
        $expectedHash = substr(hash('sha256', $cacheKey), 0, 16);
        $hashFound = collect($loggedContexts)->contains(fn ($ctx) => ($ctx['key_hash'] ?? '') === $expectedHash);
        $this->assertTrue($hashFound, 'Sanitized key_hash was not found in log context');
    }

    /**
     * Test TwoFactorService challenge consumption fails closed without logging raw challenge when Redis eval fails.
     */
    public function test_two_factor_atomic_pull_fails_closed_without_logging_raw_secret_on_eval_failure(): void
    {
        $loggedContexts = [];
        $rawLogMessages = [];

        Log::listen(function ($message) use (&$loggedContexts, &$rawLogMessages) {
            $rawLogMessages[] = is_string($message->message) ? $message->message : json_encode($message->message);
            $loggedContexts[] = $message->context;
        });

        // Create a mock RedisStore whose connection throws on eval
        $mockConnection = new class
        {
            public function eval($script, $numkeys, ...$args)
            {
                throw new \RuntimeException('NOSCRIPT No matching script. Please use EVAL.');
            }
        };

        $mockStore = $this->getMockBuilder(RedisStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrefix', 'connection'])
            ->getMock();
        $mockStore->method('getPrefix')->willReturn('codebridge_test:');
        $mockStore->method('connection')->willReturn($mockConnection);

        Cache::shouldReceive('getStore')->andReturn($mockStore);

        $twoFactorService = app(TwoFactorService::class);
        $rawChallengeToken = 'super_secret_2fa_challenge_abc_112233';

        $result = $twoFactorService->validateAndConsumeChallengeToken($rawChallengeToken);

        // 1. MUST fail closed (null)
        $this->assertNull($result, '2FA challenge consumption must fail closed when Redis eval fails');

        // 2. Logs must NEVER contain the raw challenge token
        $allLoggedText = implode(' ', $rawLogMessages).' '.json_encode($loggedContexts);
        $this->assertStringNotContainsString($rawChallengeToken, $allLoggedText, 'Raw 2FA challenge token was leaked in logs');

        // 3. Safe truncated key hash must be logged
        $cacheKey = "auth:2fa_challenge:{$rawChallengeToken}";
        $expectedHash = substr(hash('sha256', $cacheKey), 0, 16);
        $hashFound = collect($loggedContexts)->contains(fn ($ctx) => ($ctx['key_hash'] ?? '') === $expectedHash);
        $this->assertTrue($hashFound, 'Sanitized key_hash was not found in log context');
    }

    /**
     * Test single-use guarantee on non-Redis store (array store with atomic lock).
     */
    public function test_passkey_and_2fa_single_use_guarantee_on_locked_store(): void
    {
        Cache::setDefaultDriver('array');
        $passkeyService = app(PasskeyService::class);
        $twoFactorService = app(TwoFactorService::class);

        // Passkey reauth grant single-use
        $grantKey = 'auth:reauth_grant:token:test_grant_single_use';
        Cache::put($grantKey, ['user_id' => $this->user->id, 'purpose' => 'passkey_register'], 300);

        $firstPull = $passkeyService->atomicCachePull($grantKey);
        $this->assertNotNull($firstPull);
        $this->assertEquals($this->user->id, $firstPull['user_id']);

        $secondPull = $passkeyService->atomicCachePull($grantKey);
        $this->assertNull($secondPull, 'Subsequent pull must return null (single-use guarantee)');

        // 2FA challenge single-use
        $challengeToken = 'test_challenge_single_use_token_554433';
        Cache::put("auth:2fa_challenge:{$challengeToken}", [
            'user_id' => $this->user->id,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], 300);

        $firstUser = $twoFactorService->validateAndConsumeChallengeToken($challengeToken);
        $this->assertNotNull($firstUser);
        $this->assertEquals($this->user->id, $firstUser->id);

        $secondUser = $twoFactorService->validateAndConsumeChallengeToken($challengeToken);
        $this->assertNull($secondUser, 'Subsequent challenge consumption must return null (single-use guarantee)');
    }
}
