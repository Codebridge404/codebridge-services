<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
                if (strlen($model) > 2 && strlen($model) < 30 && ! preg_match('/version|khtml|gecko/i', $model)) {
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
            return 'Edge '.$m[1];
        }
        if (preg_match('/OPR\/([0-9]+)/i', $userAgent, $m)) {
            return 'Opera '.$m[1];
        }
        if (preg_match('/Brave/i', $userAgent)) {
            return 'Brave';
        }
        if (preg_match('/Chrome\/([0-9]+)/i', $userAgent, $m) || preg_match('/CriOS\/([0-9]+)/i', $userAgent, $m)) {
            return 'Chrome '.$m[1];
        }
        if (preg_match('/Version\/([0-9]+).*Safari/i', $userAgent, $m)) {
            return 'Safari '.$m[1];
        }
        if (preg_match('/Safari/i', $userAgent) && ! preg_match('/Chrome|CriOS/i', $userAgent)) {
            return 'Safari';
        }
        if (preg_match('/Firefox\/([0-9]+)/i', $userAgent, $m) || preg_match('/FxiOS\/([0-9]+)/i', $userAgent, $m)) {
            return 'Firefox '.$m[1];
        }

        return 'Web Browser';
    }

    /**
     * Extract approximate geographic location from proxy/gateway headers.
     * Omit/return null if unavailable or private/local IP.
     */
    public function parseLocation(Request $request): ?string
    {
        return $this->resolveDetailedLocation($request)['location'];
    }

    /**
     * Resolve detailed area-level location (Sangkat / Khan / District / City / Country + coordinates).
     * Priority:
     * 1. Browser/Client GPS coordinates (reverse-geocoded via OSM Nominatim)
     * 2. Cloudflare / Nginx GeoIP headers (district, city, region, country, lat/lon)
     * 3. Public IP GeoIP lookup (ip-api.com cached 24h)
     *
     * @return array{location: ?string, latitude: ?float, longitude: ?float, source: ?string}
     */
    public function resolveDetailedLocation(Request $request, ?float $lat = null, ?float $lng = null): array
    {
        $headerLat = $request->header('X-Geo-Lat');
        $headerLng = $request->header('X-Geo-Lng');
        if ($lat === null && is_numeric($headerLat)) {
            $lat = (float) $headerLat;
        }
        if ($lng === null && is_numeric($headerLng)) {
            $lng = (float) $headerLng;
        }

        // 1. Exact GPS coordinates -> reverse-geocode to Khan / Sangkat / Area
        if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $area = $this->reverseGeocodeCoordinates($lat, $lng);

            return [
                'location' => $area ?? sprintf('%.5f, %.5f', $lat, $lng),
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                'source' => 'gps',
            ];
        }

        // 2. Cloudflare / Nginx GeoIP headers
        $cfCity = $request->header('CF-IPCity');
        $cfRegion = $request->header('CF-Region');
        $cfCountry = $request->header('CF-IPCountry');
        $cfLat = $request->header('CF-IPLatitude');
        $cfLng = $request->header('CF-IPLongitude');

        $geoDistrict = $request->header('X-Geo-District') ?? $request->header('X-District-Name');
        $geoCity = $cfCity ?: ($request->header('X-Geo-City') ?? $request->header('X-City-Name'));
        $geoRegion = $cfRegion ?: $request->header('X-Geo-Region');
        $geoCountry = ($cfCountry && $cfCountry !== 'XX' && $cfCountry !== 'T1')
            ? $cfCountry
            : ($request->header('X-Geo-Country') ?? $request->header('X-Country-Name') ?? $request->header('X-Country-Code'));

        $headerParts = array_values(array_unique(array_filter([
            $geoDistrict ? trim($geoDistrict) : null,
            $geoCity ? trim($geoCity) : null,
            $geoRegion && $geoRegion !== $geoCity ? trim($geoRegion) : null,
            $geoCountry ? trim($geoCountry) : null,
        ])));

        if (! empty($headerParts)) {
            return [
                'location' => implode(', ', $headerParts),
                'latitude' => is_numeric($cfLat) ? round((float) $cfLat, 7) : null,
                'longitude' => is_numeric($cfLng) ? round((float) $cfLng, 7) : null,
                'source' => 'proxy',
            ];
        }

        // 3. Public IP GeoIP fallback (cached 24h)
        $ip = $request->ip();
        if ($this->isPrivateIp($ip)) {
            return ['location' => null, 'latitude' => null, 'longitude' => null, 'source' => null];
        }

        return $this->lookupPublicIpLocation((string) $ip);
    }

    /**
     * Reverse-geocode GPS coordinates to exact area (Sangkat / Khan / City / Country).
     */
    protected function reverseGeocodeCoordinates(float $lat, float $lng): ?string
    {
        $cacheKey = sprintf('geo:rev:%.4f:%.4f', $lat, $lng);

        return Cache::remember($cacheKey, 86400, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(3)
                    ->withHeaders(['User-Agent' => 'CodeBridges-ERP/1.0'])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $lat,
                        'lon' => $lng,
                        'zoom' => 18,
                        'addressdetails' => 1,
                        'accept-language' => 'en',
                    ]);

                if (! $response->ok()) {
                    return null;
                }

                $addr = $response->json('address') ?? [];
                $neighbourhood = $addr['quarter'] ?? $addr['neighbourhood'] ?? $addr['suburb'] ?? $addr['village'] ?? null;
                $district = $addr['city_district'] ?? $addr['county'] ?? $addr['town'] ?? null;
                $city = $addr['city'] ?? $addr['state'] ?? $addr['province'] ?? null;
                $country = isset($addr['country_code']) ? strtoupper((string) $addr['country_code']) : ($addr['country'] ?? null);

                $parts = array_values(array_unique(array_filter([
                    $neighbourhood,
                    $district && $district !== $neighbourhood ? $district : null,
                    $city && $city !== $district ? $city : null,
                    $country,
                ])));

                return ! empty($parts) ? substr(implode(', ', $parts), 0, 250) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Resolve detailed district/city/ISP for a public IP address (cached 24h).
     *
     * @return array{location: ?string, latitude: ?float, longitude: ?float, source: ?string}
     */
    protected function lookupPublicIpLocation(string $ip): array
    {
        $cacheKey = 'geo:ip:'.md5($ip);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['location'])) {
            return $cached;
        }

        try {
            $response = Http::timeout(2)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode,regionName,city,district,isp,lat,lon',
                ]);

            if (! $response->ok() || $response->json('status') !== 'success') {
                return ['location' => null, 'latitude' => null, 'longitude' => null, 'source' => null];
            }

            $district = $response->json('district');
            $city = $response->json('city');
            $region = $response->json('regionName');
            $country = $response->json('countryCode');
            $isp = $response->json('isp');
            $lat = $response->json('lat');
            $lon = $response->json('lon');

            $parts = array_values(array_unique(array_filter([
                $district ?: null,
                $city ?: null,
                $region && $region !== $city ? $region : null,
                $country ?: null,
            ])));

            $location = ! empty($parts) ? implode(', ', $parts) : null;
            if ($location && ! $district && $isp) {
                $location = "{$location} ({$isp})";
            }

            $result = [
                'location' => $location ? substr($location, 0, 250) : null,
                'latitude' => is_numeric($lat) ? round((float) $lat, 7) : null,
                'longitude' => is_numeric($lon) ? round((float) $lon, 7) : null,
                'source' => $location ? 'ip_geo' : null,
            ];

            if (! empty($result['location'])) {
                Cache::put($cacheKey, $result, 3600);
            }

            return $result;
        } catch (\Throwable) {
            return ['location' => null, 'latitude' => null, 'longitude' => null, 'source' => null];
        }
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
