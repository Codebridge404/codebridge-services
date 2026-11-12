<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Recursive log and URL redactor so tokens, PINs, passwords, and secrets
 * never leak into application logs, stderr telemetry, or audit trails.
 */
final class LogRedactor
{
    /** @var array<string, true> */
    private const EXACT_SENSITIVE_KEYS = [
        'code' => true,
        'hash' => true,
        'signature' => true,
        'challenge_token' => true,
        'bot_token' => true,
        'recovery_code' => true,
        'recovery_codes' => true,
        'password' => true,
        'password_confirmation' => true,
        'current_password' => true,
        'new_password' => true,
        'pin_code' => true,
        'pin' => true,
        'secret' => true,
        'secret_key' => true,
        'webhook_secret' => true,
        'client_secret' => true,
        'token' => true,
        'access_token' => true,
        'refresh_token' => true,
        'id_token' => true,
        'two_factor_secret' => true,
        'credit_card' => true,
        'card_number' => true,
        'cvv' => true,
        'authorization' => true,
        'credentials' => true,
        'credential' => true,
        'api_key' => true,
        'x-app-key' => true,
        'x-api-key' => true,
        'x-client-key' => true,
        'webhook_url' => true,
        'private_key' => true,
    ];

    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'pin_code',
        'private_key',
        'credit_card',
        'card_number',
        'cvv',
        'authorization',
        'credential',
        'api_key',
        'recovery_code',
        'passcode',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return false;
        }

        if (isset(self::EXACT_SENSITIVE_KEYS[$normalized])) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (self::isSensitiveKey($keyStr)) {
                $sanitized[$key] = '********';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = self::redactString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public static function redactString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $redacted = preg_replace('/\bBearer\s+[A-Za-z0-9\-_\.=]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $redacted = preg_replace('/\/bot[^\/\s?]+/i', '/bot[REDACTED]', $redacted) ?? $redacted;
        $redacted = preg_replace('#(email/verify/[^/\s]+/)[^/\s?]+#i', '$1[redacted]', $redacted) ?? $redacted;
        $redacted = preg_replace_callback(
            '/([?&])([^=&#]+)=([^&#\s]*)/',
            static function (array $matches): string {
                $param = urldecode($matches[2]);
                if (self::isSensitiveKey($param)) {
                    return $matches[1] . $matches[2] . '=********';
                }
                return $matches[0];
            },
            $redacted
        ) ?? $redacted;

        return $redacted;
    }

    public static function sanitizePathAndQuery(Request $request): string
    {
        $path = '/' . ltrim(self::redactString($request->path()), '/');
        $query = $request->query();
        if (!is_array($query) || count($query) === 0) {
            return $path;
        }

        $sanitizedQuery = self::sanitizeArray($query);
        $encoded = http_build_query($sanitizedQuery);

        return $encoded !== '' ? ($path . '?' . $encoded) : $path;
    }

    /**
     * Sanitize request path and mask ALL query parameter values for external alerts/events.
     */
    public static function sanitizePathWithMaskedQuery(Request $request): string
    {
        $path = '/' . ltrim(self::redactString($request->path()), '/');
        $rawQuery = $request->getQueryString();
        if (! is_string($rawQuery) || trim($rawQuery) === '') {
            $parsedQuery = parse_url($request->getRequestUri(), PHP_URL_QUERY);
            $rawQuery = is_string($parsedQuery) ? $parsedQuery : '';
        }

        if ($rawQuery === '') {
            return $path;
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $segment) {
            if ($segment === '') {
                continue;
            }
            $eqPos = strpos($segment, '=');
            if ($eqPos === false) {
                $pairs[] = '[REDACTED]';
            } else {
                $key = self::redactString(urldecode(substr($segment, 0, $eqPos)));
                $pairs[] = ($key !== '' ? $key : 'param') . '=[REDACTED]';
            }
        }

        return count($pairs) > 0 ? ($path . '?' . implode('&', $pairs)) : $path;
    }

    /**
     * Scrub Telegram bot tokens, URL query values, and sensitive secrets from exception/log messages.
     */
    public static function sanitizeTelegramSecrets(?string $message, ?string $rawToken = null): string
    {
        $sanitized = preg_replace('/\/bot[^\/\s?]+/i', '/bot[REDACTED]', (string) $message) ?? 'Telegram error';
        if (! empty($rawToken)) {
            $sanitized = str_replace($rawToken, '[REDACTED]', $sanitized);
        }

        // Mask all query parameter values in any URL included inside exception messages
        $sanitized = preg_replace_callback(
            '/([?&])([^=&#\s]+)=([^&#\s]*)/',
            static fn (array $matches): string => $matches[1] . self::redactString(urldecode($matches[2])) . '=[REDACTED]',
            $sanitized
        ) ?? $sanitized;

        $sanitized = self::redactString($sanitized);

        return $sanitized !== '' ? $sanitized : 'Telegram error';
    }

    public static function normalizeSql(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $scrubbed = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $collapsed) ?? $collapsed;
        $scrubbed = preg_replace('/0x[0-9a-fA-F]+/', '?', $scrubbed) ?? $scrubbed;

        return substr($scrubbed, 0, 1000);
    }
}

