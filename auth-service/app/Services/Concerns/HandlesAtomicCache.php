<?php

namespace App\Services\Concerns;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HandlesAtomicCache
{
    /**
     * Atomically fetch and delete a cache key using Redis Lua script when Redis is available.
     * Fails closed when Redis execution fails, and uses atomic locking for non-Redis test stores.
     * Raw authentication keys/tokens are never emitted to logs.
     */
    public function atomicCachePull(string $cacheKey): mixed
    {
        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            try {
                $script = <<<'LUA'
local val = redis.call('get', KEYS[1])
if val then
    redis.call('del', KEYS[1])
end
return val
LUA;
                $prefix = $store->getPrefix();
                $fullKey = $prefix.$cacheKey;
                $raw = $store->connection()->eval($script, 1, $fullKey);

                if ($raw === null || $raw === false) {
                    return null;
                }

                return is_numeric($raw) ? $raw : @unserialize($raw);
            } catch (\Throwable $e) {
                // Fail-closed on Redis scripting failure to preserve single-use guarantees.
                // Log only a safe identifier hash and error class; never log raw tokens or keys.
                Log::warning('Atomic Redis cache pull failed', [
                    'key_hash' => substr(hash('sha256', $cacheKey), 0, 16),
                    'error_class' => get_class($e),
                ]);

                return null;
            }
        }

        // Non-Redis fallback (e.g., local tests): use an atomic lock to guard pull
        try {
            $lockKey = 'atomic_pull_lock:'.md5($cacheKey);

            return Cache::lock($lockKey, 5)->block(2, function () use ($cacheKey) {
                return Cache::pull($cacheKey);
            });
        } catch (\Throwable $e) {
            Log::warning('Atomic locked cache pull failed', [
                'key_hash' => substr(hash('sha256', $cacheKey), 0, 16),
                'error_class' => get_class($e),
            ]);

            return null;
        }
    }
}
