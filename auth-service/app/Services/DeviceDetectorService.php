<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * High-precision User-Agent & Geolocation telemetry detector for active device sessions.
 */
class DeviceDetectorService
{
    /**
     * Parse human-readable device name from User-Agent.
     * Examples: 'Mac Desktop', 'iPhone', 'iPad', 'Windows PC', 'Android Device', 'Linux PC'
     */
    public function parseDeviceName(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Web Client';
        }

        if (preg_match('/iPhone/i', $userAgent)) {
            return 'iPhone';
        }
        if (preg_match('/iPad/i', $userAgent)) {
            return 'iPad';
        }
        if (preg_match('/Android/i', $userAgent)) {
            if (preg_match('/Android[^;]+;(?:\s*wv\s*;)?\s*([^;)]+)\s*(?:Build|[;)])/i', $userAgent, $matches)) {
                $model = trim($matches[1]);
                if (strlen($model) > 2 && strlen($model) < 30 && !preg_match('/version|khtml|gecko/i', $model)) {
                    return $model;
                }
            }
            return 'Android Device';
        }
        if (preg_match('/Macintosh|Mac OS X/i', $userAgent)) {
            return 'Mac Desktop';
        }
        if (preg_match('/Windows NT/i', $userAgent)) {
            return 'Windows PC';
        }
        if (preg_match('/CrOS/i', $userAgent)) {
            return 'Chromebook';
        }
        if (preg_match('/Linux/i', $userAgent)) {
            return 'Linux PC';
        }

        return 'Web Client';
    }

    /**
     * Parse operating system platform from User-Agent.
     * Examples: 'macOS', 'iOS', 'Windows', 'Android', 'Linux', 'ChromeOS'
     */
    public function parsePlatform(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown OS';
        }

        if (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            return 'iOS';
        }
        if (preg_match('/Android/i', $userAgent)) {
            return 'Android';
        }
        if (preg_match('/Macintosh|Mac OS X/i', $userAgent)) {
            return 'macOS';
        }
        if (preg_match('/Windows NT 10\.0/i', $userAgent)) {
            return 'Windows 10/11';
        }
        if (preg_match('/Windows/i', $userAgent)) {
            return 'Windows';
        }
        if (preg_match('/CrOS/i', $userAgent)) {
            return 'ChromeOS';
        }
        if (preg_match('/Linux/i', $userAgent)) {
            return 'Linux';
        }

        return 'Unknown OS';
    }

    /**
     * Parse web browser name & major version from User-Agent.
     * Examples: 'Chrome', 'Safari', 'Edge', 'Firefox', 'Opera', 'Brave'
     */
    public function parseBrowser(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Web Browser';
        }

        if (preg_match('/Edg(?:e)?\/([0-9]+)/i', $userAgent, $m)) {
            return 'Edge ' . $m[1];
        }
        if (preg_match('/OPR\/([0-9]+)/i', $userAgent, $m)) {
            return 'Opera ' . $m[1];
        }
        if (preg_match('/Brave/i', $userAgent)) {
            return 'Brave';
        }
        if (preg_match('/Chrome\/([0-9]+)/i', $userAgent, $m) || preg_match('/CriOS\/([0-9]+)/i', $userAgent, $m)) {
            return 'Chrome ' . $m[1];
        }
        if (preg_match('/Version\/([0-9]+).*Safari/i', $userAgent, $m)) {
            return 'Safari ' . $m[1];
        }
        if (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome|CriOS/i', $userAgent)) {
            return 'Safari';
        }
        if (preg_match('/Firefox\/([0-9]+)/i', $userAgent, $m) || preg_match('/FxiOS\/([0-9]+)/i', $userAgent, $m)) {
            return 'Firefox ' . $m[1];
        }

        return 'Web Browser';
    }

    /**
     * Extract approximate geographic location from proxy/gateway headers.
     * Omit/return null if unavailable or private/local IP.
     */
    public function parseLocation(Request $request): ?string
    {
        // 1. Cloudflare headers
        $city = $request->header('CF-IPCity');
        $country = $request->header('CF-IPCountry');
        if (!empty($city) && !empty($country)) {
            return trim("{$city}, {$country}");
        }
        if (!empty($country) && $country !== 'XX' && $country !== 'T1') {
            return trim($country);
        }

        // 2. Standard GeoIP headers passed by Nginx or load balancer
        $geoCity = $request->header('X-Geo-City') ?? $request->header('X-City-Name');
        $geoCountry = $request->header('X-Geo-Country') ?? $request->header('X-Country-Name') ?? $request->header('X-Country-Code');
        if (!empty($geoCity) && !empty($geoCountry)) {
            return trim("{$geoCity}, {$geoCountry}");
        }
        if (!empty($geoCountry)) {
            return trim($geoCountry);
        }

        // 3. Fallback: If private IP or local loopback, omit (null) as requested
        $ip = $request->ip();
        if ($this->isPrivateIp($ip)) {
            return null;
        }

        return null;
    }

    /**
     * Check if an IP address is a private or loopback address.
     */
    public function isPrivateIp(?string $ip): bool
    {
        if (empty($ip)) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
