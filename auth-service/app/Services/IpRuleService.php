<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Models\Security\PlatformIpRule;
use App\Support\SchemaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Redis-cached Platform IP Allowlist & Blocklist Evaluation Service.
 *
 * Uses Symfony IpUtils for exact IPv4/IPv6 and CIDR range matching,
 * backed by Redis cache with automatic database fallback.
 */
class IpRuleService
{
    public const CACHE_KEY = 'platform:security:ip_rules';

    public const CACHE_TTL_SECONDS = 300;

    /**
     * Evaluate an IP address against active allowlist and blocklist rules.
     * Allowlist rules take precedence so enterprise office IPs are never locked out.
     *
     * @return 'allow'|'block'|null
     */
    public function evaluateIp(?string $ip): ?string
    {
        if (empty($ip)) {
            return null;
        }

        $rules = $this->getActiveRules();
        $now = Carbon::now();

        foreach ($rules['allow'] as $entry) {
            if ($this->isUnexpired($entry['expires_at'] ?? null, $now) && IpUtils::checkIp($ip, $entry['ip_or_cidr'])) {
                return 'allow';
            }
        }

        foreach ($rules['block'] as $entry) {
            if ($this->isUnexpired($entry['expires_at'] ?? null, $now) && IpUtils::checkIp($ip, $entry['ip_or_cidr'])) {
                return 'block';
            }
        }

        return null;
    }

    public function isAllowlisted(?string $ip): bool
    {
        return $this->evaluateIp($ip) === 'allow';
    }

    public function isBlocked(?string $ip): bool
    {
        return $this->evaluateIp($ip) === 'block';
    }

    /**
     * Create or update an IP/CIDR rule and refresh Redis cache.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertRule(array $data, ?User $actor = null): PlatformIpRule
    {
        $rule = PlatformIpRule::updateOrCreate(
            ['ip_or_cidr' => trim((string) $data['ip_or_cidr'])],
            [
                'rule_type' => $data['rule_type'],
                'reason' => $data['reason'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at'])->utc() : null,
                'created_by' => $actor?->id,
            ]
        );

        $this->refreshCache();

        return $rule;
    }

    /**
     * Delete a rule by numeric ID or UUID and refresh Redis cache.
     */
    public function deleteRule(string $id): ?PlatformIpRule
    {
        $rule = PlatformIpRule::where('uuid', $id)
            ->when(is_numeric($id), fn ($q) => $q->orWhere('id', (int) $id))
            ->first();

        if (! $rule) {
            return null;
        }

        $rule->delete();
        $this->refreshCache();

        return $rule;
    }

    /**
     * Refresh Redis cache from database.
     */
    public function refreshCache(): void
    {
        try {
            Cache::put(self::CACHE_KEY, $this->loadActiveRulesFromDb(), self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // Safe fall-through if Redis is unavailable
        }
    }

    /**
     * Validate IPv4, IPv6, or CIDR notation.
     */
    public static function isValidIpOrCidr(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        if (! str_contains($trimmed, '/')) {
            return filter_var($trimmed, FILTER_VALIDATE_IP) !== false;
        }

        $parts = explode('/', $trimmed, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return false;
        }

        [$ip, $prefix] = $parts;
        $prefixInt = (int) $prefix;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 128;
        }

        return false;
    }

    /**
     * @return array{allow: array<int, array<string, mixed>>, block: array<int, array<string, mixed>>}
     */
    protected function getActiveRules(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['allow'], $cached['block'])) {
                return $cached;
            }
        } catch (\Throwable) {
            // Fall through to DB
        }

        $rules = $this->loadActiveRulesFromDb();

        if (! empty($rules['allow']) || ! empty($rules['block'])) {
            try {
                Cache::put(self::CACHE_KEY, $rules, self::CACHE_TTL_SECONDS);
            } catch (\Throwable) {
                // Safe fall-through
            }
        }

        return $rules;
    }

    /**
     * @return array{allow: array<int, array<string, mixed>>, block: array<int, array<string, mixed>>}
     */
    protected function loadActiveRulesFromDb(): array
    {
        $result = ['allow' => [], 'block' => []];

        if (! SchemaGuard::hasTable('platform_ip_rules')) {
            return $result;
        }

        $rows = PlatformIpRule::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->get(['ip_or_cidr', 'rule_type', 'expires_at']);

        foreach ($rows as $row) {
            $bucket = $row->rule_type === 'allow' ? 'allow' : 'block';
            $result[$bucket][] = [
                'ip_or_cidr' => (string) $row->ip_or_cidr,
                'expires_at' => $row->expires_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    protected function isUnexpired(?string $expiresAt, Carbon $now): bool
    {
        if ($expiresAt === null) {
            return true;
        }

        return Carbon::parse($expiresAt)->gt($now);
    }
}
