<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-26
 * Task: BD-0171 - Request Locale Resolution Middleware (English / Khmer)
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['en', 'km'];

    public function handle(Request $request, Closure $next): Response
    {
        $headerLocale = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));
        $locale = in_array($headerLocale, self::SUPPORTED_LOCALES, true) ? $headerLocale : 'en';

        App::setLocale($locale);

        return $next($request);
    }
}
