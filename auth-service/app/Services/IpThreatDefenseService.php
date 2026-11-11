<?php

namespace App\Services;

use App\Models\NotificationProvider;
use App\Models\SecurityEvent;
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
        try {
            SecurityEvent::record(
                eventType: 'ip_auto_banned',
                ruleCode: 'SEC_ATTACK_REPEATED_IP_BANNED',
                summary: "Source IP {$ip} has been automatically BANNED for {$durationMinutes} minutes due to repeated attacks ({$reason}).",
                severity: 'critical',
                outcome: 'blocked',
                confidence: 'confirmed',
                userId: $request?->user()?->id,
                tenantId: $request?->user()?->tenant_id,
                request: $request,
                metadata: $banData,
                statusCode: 403
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to record IP ban security event: ' . $e->getMessage());
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
     * Dispatch high-priority alert to the Telegram group about an IP being banned.
     */
    protected static function sendTelegramBanAlert(
        string $ip,
        string $reason,
        int $durationMinutes,
        string $expiresAt,
        ?Request $request = null
    ): void {
        try {
            $provider = NotificationProvider::where('channel', 'telegram')
                ->where('is_enabled', true)
                ->latest('id')
                ->first();

            if (! $provider) {
                return;
            }

            $creds = $provider->getDecryptedCredentials();
            $token = $creds['bot_token'] ?? null;
            $chatId = $creds['chat_id'] ?? null;

            if (empty($token) || empty($chatId)) {
                return;
            }

            $uri = $request ? substr($request->getRequestUri(), 0, 100) : 'N/A';
            $time = now()->toDateTimeString();

            $message = "🚨 <b>CRITICAL: ATTACKER IP AUTO-BANNED</b>\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n"
                . "• <b>Banned IP:</b> <code>{$ip}</code>\n"
                . "• <b>Reason:</b> {$reason}\n"
                . "• <b>Duration:</b> <b>{$durationMinutes} minutes</b> (until {$expiresAt})\n"
                . "• <b>Targeted Route:</b> <code>{$uri}</code>\n"
                . "• <b>Action:</b> ALL TRAFFIC BLOCKED (HTTP 403)\n"
                . "• <b>Timestamp:</b> {$time}\n"
                . "━━━━━━━━━━━━━━━━━━━━━━";

            Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send Telegram IP ban alert: ' . $e->getMessage());
        }
    }
}
