<?php

namespace App\Http\Middleware;

use App\Support\TelemetryEmitter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operations Web Attack Protection, Threat Mitigation & Security Headers Middleware.
 */
class ProtectAttackMiddleware
{
    /**
     * Common vulnerability scanners and automated exploit user-agents to block.
     */
    protected array $blockedScanners = [
        'sqlmap',
        'nikto',
        'masscan',
        'wpscan',
        'gobuster',
        'dirbuster',
        'acunetix',
        'havij',
        'zgrab',
        'nmap',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = strtolower((string) $request->userAgent());
        foreach ($this->blockedScanners as $scanner) {
            if ($userAgent !== '' && str_contains($userAgent, $scanner)) {
                $this->emitThreatTelemetry($request, 'scanner_detected', "Automated security scanner detected: {$scanner}", 403);
                return response()->json([
                    'status' => 'error',
                    'code' => 'ATTACK_DETECTED',
                    'message' => 'Automated security scanner or attack tool blocked.',
                ], 403);
            }
        }

        $rawUri = $request->getRequestUri();
        if (str_contains($rawUri, '%00') || str_contains($rawUri, "\0") || str_contains($rawUri, '\x00')) {
            $this->emitThreatTelemetry($request, 'null_byte_injection', 'Null-byte character detected in URI', 400);
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_URI',
                'message' => 'Invalid request: null-byte character detected.',
            ], 400);
        }

        if ($this->detectPathTraversal($rawUri, $request->query())) {
            $this->emitThreatTelemetry($request, 'path_traversal', 'Path traversal pattern detected in request', 403);
            return response()->json([
                'status' => 'error',
                'code' => 'PATH_TRAVERSAL_DETECTED',
                'message' => 'Path traversal attempt blocked.',
            ], 403);
        }

        if ($this->detectCommandInjection($rawUri, $request->query())) {
            $this->emitThreatTelemetry($request, 'command_injection', 'Command injection pattern detected in request', 403);
            return response()->json([
                'status' => 'error',
                'code' => 'COMMAND_INJECTION_DETECTED',
                'message' => 'Command injection attempt blocked.',
            ], 403);
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-XSS-Protection', '1; mode=block');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }

    protected function detectPathTraversal(string $uri, array $query): bool
    {
        $pattern = '#(?:\.\.[\\\/]|\.\.%2f|\.\.%5c|%2e%2e[\\\/]|/etc/(?:passwd|shadow|hosts)|(?:win|boot)\.ini)#i';
        if (preg_match($pattern, $uri)) {
            return true;
        }

        foreach ($query as $val) {
            if (is_string($val) && preg_match($pattern, $val)) {
                return true;
            }
        }

        return false;
    }

    protected function detectCommandInjection(string $rawUri, array $query): bool
    {
        $pattern = '#(?:;|\|\||&&)(?:[\s+]|%20)*(?:cat|rm|nc|curl|wget|whoami|id\b)#i';
        if (preg_match($pattern, urldecode($rawUri))) {
            return true;
        }

        foreach ($query as $val) {
            if (is_string($val) && preg_match($pattern, $val)) {
                return true;
            }
        }

        return false;
    }

    protected function emitThreatTelemetry(Request $request, string $threatType, string $details, int $statusCode): void
    {
        try {
            TelemetryEmitter::emit('security_attack_blocked', [
                'threat_type' => $threatType,
                'details' => $details,
                'status_code' => $statusCode,
                'ip' => $request->ip(),
                'path' => '/' . ltrim($request->path(), '/'),
                'request_id' => $request->header('X-Request-ID'),
            ]);
        } catch (\Throwable) {
            // Never disrupt threat block response if telemetry fails
        }
    }
}

