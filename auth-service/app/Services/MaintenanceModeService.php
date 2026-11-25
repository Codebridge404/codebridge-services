<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Models\System\PlatformMaintenanceSetting;
use App\Support\SchemaGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform Maintenance Mode & Scheduled Downtime Service.
 *
 * Uses the database as the persistent source of truth and Redis cache for
 * low-latency reads, with automatic database fallback when cache is unavailable.
 */
class MaintenanceModeService
{
    public const CACHE_KEY = 'platform:maintenance:config';

    public const DISPLAY_TIMEZONE = 'Asia/Phnom_Penh';

    public const CACHE_TTL_SECONDS = 300;

    /**
     * Read current maintenance configuration (Redis-cached with DB fallback).
     *
     * @return array{
     *     enabled: bool,
     *     message: ?string,
     *     starts_at: ?string,
     *     ends_at: ?string,
     *     block_mutations: bool,
     *     status: string
     * }
     */
    public function getConfig(): array
    {
        $raw = null;

        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                $raw = $cached;
            }
        } catch (\Throwable) {
            // Fall through to DB when Redis/cache is unavailable
        }

        if ($raw === null) {
            $raw = $this->loadFromDatabase();
            try {
                Cache::put(self::CACHE_KEY, $raw, self::CACHE_TTL_SECONDS);
            } catch (\Throwable) {
                // Safe fall-through
            }
        }

        return $this->withComputedStatus($raw);
    }

    /**
     * Persist updated maintenance schedule in DB and refresh Redis cache.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateConfig(array $data, ?User $actor = null): array
    {
        $startsAt = ! empty($data['starts_at'])
            ? Carbon::parse((string) $data['starts_at'])->utc()
            : null;
        $endsAt = ! empty($data['ends_at'])
            ? Carbon::parse((string) $data['ends_at'])->utc()
            : null;

        $setting = DB::transaction(function () use ($data, $startsAt, $endsAt, $actor) {
            return PlatformMaintenanceSetting::updateOrCreate(
                ['key' => 'global'],
                [
                    'enabled' => (bool) ($data['enabled'] ?? false),
                    'message' => $data['message'] ?? null,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'block_mutations' => (bool) ($data['block_mutations'] ?? false),
                    'updated_by' => $actor?->id,
                ]
            );
        });

        $raw = $this->serializeRecord($setting);

        try {
            Cache::put(self::CACHE_KEY, $raw, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // Safe fall-through if Redis is unavailable
        }

        return $this->withComputedStatus($raw);
    }

    /**
     * Determine whether an incoming HTTP request should be blocked with 503.
     */
    public function shouldBlockRequest(Request $request, ?User $user = null): bool
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if ($this->isExemptRoute($request)) {
            return false;
        }

        $config = $this->getConfig();
        if (! $config['enabled'] || ! $config['block_mutations'] || $config['status'] !== 'active') {
            return false;
        }

        // Only verified platform super_admin users may bypass maintenance mutation blocking
        if ($user && UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            return false;
        }

        return true;
    }

    /**
     * Routes always allowed during maintenance so admins can authenticate and manage the platform.
     */
    protected function isExemptRoute(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return $path === 'api/v1/auth/login'
            || $path === 'api/v1/auth/two-factor/challenge'
            || str_starts_with($path, 'api/v1/auth/passkeys/login')
            || $path === 'api/v1/auth/refresh'
            || $path === 'api/v1/auth/logout'
            || str_starts_with($path, 'api/v1/super-admin/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadFromDatabase(): array
    {
        if (! SchemaGuard::hasTable('platform_maintenance_settings')) {
            return $this->defaultRawConfig();
        }

        $record = PlatformMaintenanceSetting::where('key', 'global')->first();

        return $record ? $this->serializeRecord($record) : $this->defaultRawConfig();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeRecord(PlatformMaintenanceSetting $record): array
    {
        return [
            'enabled' => (bool) $record->enabled,
            'message' => $record->message,
            'starts_at' => $record->starts_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->toIso8601String(),
            'ends_at' => $record->ends_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->toIso8601String(),
            'block_mutations' => (bool) $record->block_mutations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultRawConfig(): array
    {
        return [
            'enabled' => false,
            'message' => null,
            'starts_at' => null,
            'ends_at' => null,
            'block_mutations' => false,
        ];
    }

    /**
     * Compute real-time maintenance status ('inactive' | 'scheduled' | 'active' | 'ended').
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function withComputedStatus(array $raw): array
    {
        $enabled = (bool) ($raw['enabled'] ?? false);
        $startsAt = ! empty($raw['starts_at']) ? Carbon::parse((string) $raw['starts_at']) : null;
        $endsAt = ! empty($raw['ends_at']) ? Carbon::parse((string) $raw['ends_at']) : null;
        $now = Carbon::now();

        $status = 'inactive';
        if ($enabled) {
            if ($startsAt !== null && $now->lt($startsAt)) {
                $status = 'scheduled';
            } elseif ($endsAt !== null && $now->gt($endsAt)) {
                $status = 'ended';
            } else {
                $status = 'active';
            }
        }

        return [
            'enabled' => $enabled,
            'message' => $raw['message'] ?? null,
            'starts_at' => $startsAt?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->toIso8601String(),
            'ends_at' => $endsAt?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->toIso8601String(),
            'block_mutations' => (bool) ($raw['block_mutations'] ?? false),
            'status' => $status,
        ];
    }
}
