<?php

namespace App\Services;

use App\Models\NotificationProvider;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\LogRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real-Time IP Threat Defense, Repeat Attack Tracking & Auto-Jail Service.
 * Protects APIs against repeated attacks (SQL injection, path traversal, exploit tools) from the same source IP.
 */
class IpThreatDefenseService
{
    private const BAN_PREFIX = 'sec_ip_ban:';
    private const OFFENSE_PREFIX = 'sec_ip_offense:';
    private const BANNED_INDEX_KEY = 'sec_banned_ips_index';
    private const SETTINGS_KEY = 'sec_threat_defense_settings';

    /**
     * Get active defense configuration settings.
     */
    public static function getSettings(): array
    {
        try {
            $custom = Cache::get(self::SETTINGS_KEY, []);
        } catch (\Throwable) {
            $custom = [];
        }

        return [
            'enabled' => $custom['enabled'] ?? config('security.auto_ban.enabled', true),
            'max_offenses' => (int) ($custom['max_offenses'] ?? config('security.auto_ban.max_offenses', 3)),
            'offense_decay_minutes' => (int) ($custom['offense_decay_minutes'] ?? config('security.auto_ban.offense_decay_minutes', 15)),
            'ban_duration_minutes' => (int) ($custom['ban_duration_minutes'] ?? config('security.auto_ban.ban_duration_minutes', 60)),
            'whitelist' => config('security.whitelist', ['127.0.0.1', '::1']),
        ];
    }

    /**
     * Update defense settings in memory/cache.
     */
    public static function updateSettings(array $newSettings): array
    {
        $current = self::getSettings();
        $merged = array_merge($current, $newSettings);
        try {
            Cache::forever(self::SETTINGS_KEY, $merged);
        } catch (\Throwable) {}

        return $merged;
    }

    /**
     * Check if a given IP address is currently banned.
     */
    public static function isBanned(string $ip): ?array
    {
        try {
            $settings = self::getSettings();
            if (! $settings['enabled']) {
                return null;
            }

            return Cache::get(self::BAN_PREFIX . $ip);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record an attack offense from a source IP, and automatically ban if threshold is exceeded.
     *
     * @return array{banned: bool, offense_count: int, threshold: int, ban_info: ?array}
     */
    public static function recordOffense(Request $request, string $threatType, string $details): array
    {
        $settings = self::getSettings();
        $ip = $request->ip();

        if (! $settings['enabled'] || empty($ip)) {
            return ['banned' => false, 'offense_count' => 0, 'threshold' => $settings['max_offenses'], 'ban_info' => null];
        }

        // Whitelisted IPs are exempt from automatic banning
        if (in_array($ip, $settings['whitelist'], true) && $request->header('X-Simulate-Attacker') !== 'true') {
            return ['banned' => false, 'offense_count' => 0, 'threshold' => $settings['max_offenses'], 'ban_info' => null];
        }

        $offenseKey = self::OFFENSE_PREFIX . $ip;
        $decayMinutes = $settings['offense_decay_minutes'];
        $maxOffenses = $settings['max_offenses'];

        // Increment offenses count within window
        if (! Cache::has($offenseKey)) {
            Cache::put($offenseKey, 1, now()->addMinutes($decayMinutes));
            $count = 1;
        } else {
            $count = Cache::increment($offenseKey);
        }

        // Check if offense count has exceeded threshold
        if ($count >= $maxOffenses) {
            $durationMinutes = $settings['ban_duration_minutes'];
            $banInfo = self::banIp(
                ip: $ip,
                durationMinutes: $durationMinutes,
                reason: "Repeated security violations ({$count} attempts, last: {$threatType})",
                threatType: $threatType,
                request: $request
            );

            return [
                'banned' => true,
                'offense_count' => $count,
                'threshold' => $maxOffenses,
                'ban_info' => $banInfo,
            ];
        }

        return [
            'banned' => false,
            'offense_count' => $count,
            'threshold' => $maxOffenses,
            'ban_info' => null,
        ];
    }

    /**
     * Ban an IP address for a specified duration and send alert notifications.
     */
    public static function banIp(
        string $ip,
        int $durationMinutes,
        string $reason = 'Repeated malicious attack attempts',
        string $threatType = 'multiple_attacks',
        ?Request $request = null
    ): array {
        $expiresAt = now()->addMinutes($durationMinutes);
        $banData = [
            'ip' => $ip,
            'reason' => $reason,
            'threat_type' => $threatType,
            'banned_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'duration_minutes' => $durationMinutes,
        ];

        Cache::put(self::BAN_PREFIX . $ip, $banData, $expiresAt);

        // Update banned IPs index
        $index = Cache::get(self::BANNED_INDEX_KEY, []);
        $index[$ip] = $banData;
        Cache::forever(self::BANNED_INDEX_KEY, $index);

        // Record a Critical Forensic Security Event
        $verifiedAuth = self::extractVerifiedAuthContext($request);
        try {
            SecurityEvent::record(
                eventType: 'ip_auto_banned',
                ruleCode: 'SEC_ATTACK_REPEATED_IP_BANNED',
                summary: "Source IP {$ip} has been automatically BANNED for {$durationMinutes} minutes due to repeated attacks ({$reason}).",
                severity: 'critical',
                outcome: 'blocked',
                confidence: 'confirmed',
                userId: $verifiedAuth['user_id'],
                tenantId: $verifiedAuth['tenant_id'],
                request: $request,
                metadata: $banData,
                statusCode: 403
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to record IP ban security event: ' . LogRedactor::sanitizeTelegramSecrets($e->getMessage()));
        }

        // Send High-Priority Telegram Alert
        self::sendTelegramBanAlert($ip, $reason, $durationMinutes, $expiresAt->toDateTimeString(), $request);

        return $banData;
    }

    /**
     * Unban an IP address.
     */
    public static function unbanIp(string $ip): bool
    {
        Cache::forget(self::BAN_PREFIX . $ip);
        Cache::forget(self::OFFENSE_PREFIX . $ip);

        $index = Cache::get(self::BANNED_INDEX_KEY, []);
        if (isset($index[$ip])) {
            unset($index[$ip]);
            Cache::forever(self::BANNED_INDEX_KEY, $index);
        }

        return true;
    }

    /**
     * Get list of currently banned IPs.
     */
    public static function getBannedIps(): array
    {
        $index = Cache::get(self::BANNED_INDEX_KEY, []);
        $activeBans = [];
        $now = now();

        foreach ($index as $ip => $data) {
            if (Cache::has(self::BAN_PREFIX . $ip)) {
                $activeBans[] = $data;
            }
        }

        return $activeBans;
    }

    /**
     * Extract verified authentication user and tenant IDs from request state or verified bearer tokens.
     * Never trusts spoofable unauthenticated headers (e.g. X-Tenant-ID) or unsigned JWT claims.
     *
     * @return array{user_id: ?int, tenant_id: ?int}
     */
    public static function extractVerifiedAuthContext(?Request $request): array
    {
        if (! $request) {
            return ['user_id' => null, 'tenant_id' => null];
        }

        $attrUserId = $request->attributes->get('auth_user_id');
        $attrTenantId = $request->attributes->get('auth_tenant_id');
        if ($attrUserId !== null || $attrTenantId !== null) {
            return [
                'user_id' => is_numeric($attrUserId) && (int) $attrUserId > 0 ? (int) $attrUserId : null,
                'tenant_id' => is_numeric($attrTenantId) && (int) $attrTenantId > 0 ? (int) $attrTenantId : null,
            ];
        }

        $user = $request->user();
        if ($user) {
            return [
                'user_id' => ! empty($user->id) ? (int) $user->id : null,
                'tenant_id' => ! empty($user->tenant_id) ? (int) $user->tenant_id : null,
            ];
        }

        $token = $request->bearerToken()
            ?: $request->header('token')
            ?: $request->header('Token')
            ?: $request->header('x-token')
            ?: $request->header('X-Token');

        if (! is_string($token) || trim($token) === '') {
            return ['user_id' => null, 'tenant_id' => null];
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }

        if (substr_count($token, '.') === 2) {
            try {
                $jwtService = app(JwtService::class);
                $payload = $jwtService->verify($token);
                if ($payload && ! empty($payload['sub']) && ($payload['token_use'] ?? null) === 'access') {
                    $revoked = (! empty($payload['jti']) && $jwtService->isRevoked($payload['jti']))
                        || (! empty($payload['sid']) && $jwtService->isSessionUuidRevoked((string) $payload['sid']))
                        || $jwtService->isUserSessionRevoked($payload['sub'], (int) ($payload['iat'] ?? 0), $payload['session_version'] ?? null);

                    if (! $revoked) {
                        $verifiedUser = User::where('id', $payload['sub'])
                            ->where('is_active', true)
                            ->first(['id', 'tenant_id']);

                        if ($verifiedUser) {
                            return [
                                'user_id' => (int) $verifiedUser->id,
                                'tenant_id' => ! empty($verifiedUser->tenant_id) ? (int) $verifiedUser->tenant_id : null,
                            ];
                        }
                    }
                }
            } catch (\Throwable) {
                // Fail closed to unauthenticated platform context
            }
        } elseif (str_contains($token, '|')) {
            try {
                $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                if ($pat && (! $pat->expires_at || $pat->expires_at->isFuture())) {
                    $verifiedUser = $pat->tokenable;
                    if ($verifiedUser instanceof User && $verifiedUser->is_active) {
                        return [
                            'user_id' => (int) $verifiedUser->id,
                            'tenant_id' => ! empty($verifiedUser->tenant_id) ? (int) $verifiedUser->tenant_id : null,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Fail closed
            }
        }

        return ['user_id' => null, 'tenant_id' => null];
    }

    /**
     * Resolve the authorized Telegram security alert provider.
     * - When verifiedTenantId is non-null: resolves strictly within that tenant.
     * - When verifiedTenantId is null: resolves strictly from explicit platform providers (tenant_id IS NULL).
     * Never selects or falls back to an arbitrary tenant's provider.
     */
    public static function resolveSecurityAlertProvider(?int $verifiedTenantId = null): ?NotificationProvider
    {
        try {
            if ($verifiedTenantId !== null) {
                $candidates = NotificationProvider::where('channel', 'telegram')
                    ->where('tenant_id', $verifiedTenantId)
                    ->where('is_enabled', true)
                    ->orderByDesc('id')
                    ->get();

                $explicitSecurity = $candidates->first(function (NotificationProvider $p): bool {
                    $purpose = $p->settings['purpose'] ?? null;

                    return $purpose === 'security_alerts'
                        || $p->name === 'CodeBridge Security Alert Bot';
                });

                if ($explicitSecurity) {
                    return $explicitSecurity;
                }

                return $candidates->first(function (NotificationProvider $p): bool {
                    $purpose = $p->settings['purpose'] ?? null;

                    return $purpose !== 'meeting_alerts'
                        && ! str_starts_with((string) $p->name, 'Telegram Meeting Alerts');
                });
            }

            $configuredProviderId = config('services.telegram_security.provider_id');
            if (! empty($configuredProviderId) && is_numeric($configuredProviderId)) {
                $configured = NotificationProvider::where('id', (int) $configuredProviderId)
                    ->where('channel', 'telegram')
                    ->whereNull('tenant_id')
                    ->where('is_enabled', true)
                    ->first();

                if ($configured) {
                    return $configured;
                }
            }

            $platformCandidates = NotificationProvider::where('channel', 'telegram')
                ->whereNull('tenant_id')
                ->where('is_enabled', true)
                ->orderByDesc('id')
                ->get();

            $explicitPlatform = $platformCandidates->first(function (NotificationProvider $p): bool {
                $purpose = $p->settings['purpose'] ?? null;

                return $purpose === 'security_alerts'
                    || $p->name === 'CodeBridge Security Alert Bot';
            });

            if ($explicitPlatform) {
                return $explicitPlatform;
            }

            return $platformCandidates->first(function (NotificationProvider $p): bool {
                $purpose = $p->settings['purpose'] ?? null;

                return $purpose !== 'meeting_alerts'
                    && ! str_starts_with((string) $p->name, 'Telegram Meeting Alerts');
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Dispatch high-priority alert to the scoped Telegram group about an IP being banned.
     */
    protected static function sendTelegramBanAlert(
        string $ip,
        string $reason,
        int $durationMinutes,
        string $expiresAt,
        ?Request $request = null
    ): void {
        $token = null;
        try {
            $verifiedAuth = self::extractVerifiedAuthContext($request);
            $provider = self::resolveSecurityAlertProvider($verifiedAuth['tenant_id']);

            if (! $provider) {
                return;
            }

            $creds = $provider->getDecryptedCredentials();
            $token = $creds['bot_token'] ?? ($creds['token'] ?? null);
            $chatId = $creds['chat_id'] ?? ($provider->settings['default_chat_id'] ?? null);

            if (empty($token) || empty($chatId)) {
                return;
            }

            $safeIp = htmlspecialchars($ip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeReason = htmlspecialchars(LogRedactor::sanitizeTelegramSecrets($reason), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $uri = $request
                ? htmlspecialchars(substr(LogRedactor::sanitizePathWithMaskedQuery($request), 0, 150), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : 'N/A';
            $time = now()->toDateTimeString();

            $message = "🚨 <b>CRITICAL: ATTACKER IP AUTO-BANNED</b>\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n"
                . "• <b>Banned IP:</b> <code>{$safeIp}</code>\n"
                . "• <b>Reason:</b> {$safeReason}\n"
                . "• <b>Duration:</b> <b>{$durationMinutes} minutes</b> (until {$expiresAt})\n"
                . "• <b>Targeted Route:</b> <code>{$uri}</code>\n"
                . "• <b>Action:</b> ALL TRAFFIC BLOCKED (HTTP 403)\n"
                . "• <b>Timestamp:</b> {$time}\n"
                . "━━━━━━━━━━━━━━━━━━━━━━";

            $response = Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful()) {
                Log::warning('Failed to send Telegram IP ban alert: HTTP ' . $response->status() . ' ' . LogRedactor::sanitizeTelegramSecrets((string) $response->body(), is_string($token) ? $token : null));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send Telegram IP ban alert: ' . LogRedactor::sanitizeTelegramSecrets($e->getMessage(), is_string($token) ? $token : null));
        }
    }
}
