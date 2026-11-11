<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0086] Unified Web Attack Protection, Threat Mitigation & Security Headers Middleware
 *
 * @ticket BD-0086
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/ProtectAttackMiddleware.php
 * @entry-point ProtectAttackMiddleware::handle()
 * @routes Global API pipeline & alias 'protect_attack'
 *
 * Process Capabilities:
 * Step 1 (Vulnerability Scanner Detection): Identifies and drops automated attack tools (sqlmap, nikto, wpscan, etc.).
 * Step 2 (Null-Byte Injection Shield): Detects and rejects poison null bytes (%00, \0) in URI and query parameters.
 * Step 3 (Path Traversal & LFI Shield): Blocks directory traversal patterns (../, win.ini, /etc/passwd).
 * Step 4 (Command & Shell Injection Shield): Inspects query strings and headers for shell execution chains.
 * Step 5 (Forensic Threat Audit Logging): Records security alerts into audit_logs table upon threat detection.
 * Step 6 (Security Headers Enforcement): Injects HSTS, nosniff, DENY, and XSS protection headers on every response.
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

    /**
     * Handle an incoming request and enforce attack protection shields.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 0. Enforce IP Jail / Ban for Repeated Attackers
        $banInfo = \App\Services\IpThreatDefenseService::isBanned((string) $request->ip());
        if ($banInfo) {
            return response()->json([
                'status' => 'error',
                'code' => 'IP_AUTO_BANNED',
                'message' => 'Access Denied: Your IP address has been temporarily banned due to repeated security attacks.',
                'data' => [
                    'ip' => $banInfo['ip'] ?? $request->ip(),
                    'reason' => $banInfo['reason'] ?? 'Repeated malicious attack attempts',
                    'expires_at' => $banInfo['expires_at'] ?? null,
                ],
            ], 403);
        }

        // 1. Block Automated Vulnerability Scanners & Exploit Tools
        $userAgent = strtolower((string) $request->userAgent());
        foreach ($this->blockedScanners as $scanner) {
            if (str_contains($userAgent, $scanner)) {
                $this->recordAttackBlocked($request, 'scanner_detected', "Automated security scanner detected: {$scanner}");
                return response()->json([
                    'status' => 'error',
                    'code' => 'ATTACK_DETECTED',
                    'message' => 'Automated security scanner or attack tool blocked.',
                ], 403);
            }
        }

        // 2. Block Null-Byte Injection in URI and Raw Query String
        $rawUri = $request->getRequestUri();
        if (str_contains($rawUri, '%00') || str_contains($rawUri, "\0") || str_contains($rawUri, '\x00')) {
            $this->recordAttackBlocked($request, 'null_byte_injection', 'Null-byte character detected in URI');
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_URI',
                'message' => 'Invalid request: null-byte character detected.',
            ], 400);
        }

        // Exclude credential fields and login body validation probes (handled strictly by LoginRequest)
        $inspectableInputs = $request->is('api/v1/auth/login')
            ? []
            : $request->except(['password', 'password_confirmation', 'current_password', 'new_password']);

        // 3. Block Path Traversal / Local File Inclusion (LFI) in URI, Query & Body
        if ($this->detectPathTraversal($rawUri, $request->query(), $inspectableInputs)) {
            $this->recordAttackBlocked($request, 'path_traversal', 'Path traversal pattern detected in request');
            return response()->json([
                'status' => 'error',
                'code' => 'PATH_TRAVERSAL_DETECTED',
                'message' => 'Path traversal attempt blocked.',
            ], 403);
        }

        // 4. Block Command Injection Chains in Query Parameters, URI & Body
        if ($this->detectCommandInjection($rawUri, $request->query(), $inspectableInputs)) {
            $this->recordAttackBlocked($request, 'command_injection', 'Command injection pattern detected in request');
            return response()->json([
                'status' => 'error',
                'code' => 'COMMAND_INJECTION_DETECTED',
                'message' => 'Command injection attempt blocked.',
            ], 403);
        }

        // 5. Block SQL Injection Signatures in URI, Query & Body
        if ($this->detectSqlInjection($rawUri, $request->query(), $inspectableInputs)) {
            $this->recordAttackBlocked($request, 'sql_injection', 'SQL injection pattern detected in request');
            return response()->json([
                'status' => 'error',
                'code' => 'SQL_INJECTION_DETECTED',
                'message' => 'SQL injection attempt blocked.',
            ], 403);
        }

        // Forward to the next pipeline handler
        $response = $next($request);

        // 5. Inject Defense-in-Depth Security Headers on Every Access
        $this->injectSecurityHeaders($response);

        return $response;
    }

    /**
     * Inspect URI, query parameters, and payload inputs for directory traversal attempts.
     */
    protected function detectPathTraversal(string $uri, array $query, array $inputs = []): bool
    {
        $traversalPattern = '#(?:\.\.[\\\/]|\.\.%2f|\.\.%5c|%2e%2e[\\\/]|/etc/(?:passwd|shadow|hosts)|(?:win|boot)\.ini)#i';

        if (preg_match($traversalPattern, $uri)) {
            return true;
        }

        foreach ($query as $key => $val) {
            if (is_string($val) && preg_match($traversalPattern, $val)) {
                return true;
            }
        }

        if (! empty($inputs) && $this->containsInputPattern($inputs, $traversalPattern)) {
            return true;
        }

        return false;
    }

    /**
     * Inspect raw URI, query parameters, and payload inputs for command injection signatures.
     */
    protected function detectCommandInjection(string $rawUri, array $query, array $inputs = []): bool
    {
        $cmdPattern = '#(?:;|\|\||&&)(?:[\s+]|%20)*(?:cat|rm|nc|curl|wget|whoami|id\b)#i';

        if (preg_match($cmdPattern, urldecode($rawUri))) {
            return true;
        }

        foreach ($query as $key => $val) {
            if (is_string($val) && preg_match($cmdPattern, $val)) {
                return true;
            }
        }

        if (! empty($inputs) && $this->containsInputPattern($inputs, $cmdPattern)) {
            return true;
        }

        return false;
    }

    /**
     * Inspect raw URI, query parameters, and payload inputs for SQL injection signatures.
     */
    protected function detectSqlInjection(string $rawUri, array $query, array $inputs = []): bool
    {
        $sqliPattern = '#(?:union\s+(?:all\s+)?select|\b(?:drop\s+table|insert\s+into|delete\s+from)\b|information_schema|benchmark\s*\(|sleep\s*\(|\bwaitfor\s+delay\b|\b(?:and|or)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+)#i';

        $decodedUri = urldecode($rawUri);
        $queryString = parse_url($decodedUri, PHP_URL_QUERY);

        if ($queryString && preg_match($sqliPattern, $queryString)) {
            return true;
        }

        foreach ($query as $key => $val) {
            if (is_string($val) && preg_match($sqliPattern, $val)) {
                return true;
            }
        }

        if (! empty($inputs)) {
            // Restrict JSON-body WAF blocking to high-confidence SQLi exploit constructs so
            // parameterized free-text fields (and ORM escaping tests) don't false-positive.
            $bodySqliPattern = '#(?:union\s+(?:all\s+)?select|information_schema|benchmark\s*\(|sleep\s*\(|\bwaitfor\s+delay\b)#i';
            if ($this->containsInputPattern($inputs, $bodySqliPattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively scan input values against an attack signature pattern.
     */
    protected function containsInputPattern(mixed $input, string $pattern): bool
    {
        if (is_string($input)) {
            return (bool) preg_match($pattern, $input);
        }

        if (is_array($input)) {
            foreach ($input as $key => $val) {
                if (in_array(strtolower((string) $key), ['password', 'password_confirmation', 'current_password', 'new_password'], true)) {
                    continue;
                }
                if ($this->containsInputPattern($val, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record an immutable forensic security log upon attack detection.
     */
    protected function recordAttackBlocked(Request $request, string $threatType, string $details): void
    {
        try {
            if (SchemaGuard::hasTable('security_events')) {
                \App\Models\SecurityEvent::record(
                    eventType: 'attack_' . $threatType,
                    ruleCode: 'SEC_ATTACK_' . strtoupper($threatType),
                    summary: $details,
                    severity: 'high',
                    outcome: 'blocked',
                    confidence: 'confirmed',
                    userId: $request->user()?->id,
                    tenantId: $request->user()?->tenant_id,
                    request: $request,
                    metadata: [
                        'threat_type' => $threatType,
                        'uri' => substr($request->getRequestUri(), 0, 200),
                    ],
                    statusCode: 403
                );
            }

            if (SchemaGuard::hasTable('audit_logs')) {
                AuditLog::create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $request->user()?->tenant_id,
                    'user_id' => $request->user()?->id,
                    'action' => 'security.attack_blocked',
                    'module' => 'security',
                    'request_id' => $request->header('X-Request-ID') ?? (string) Str::uuid(),
                    'trace_id' => $request->header('X-Trace-ID') ?? substr(md5(microtime()), 0, 32),
                    'entity_type' => 'security_threat',
                    'entity_id' => $threatType,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                    'details' => $details,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Real-time IP Threat Defense: Track offenses and auto-ban if threshold exceeded
            \App\Services\IpThreatDefenseService::recordOffense($request, $threatType, $details);

            // Real-time dispatch to Telegram Security Alert group
            $this->dispatchTelegramSecurityAlert($request, $threatType, $details);
        } catch (\Throwable $e) {
            Log::warning('Failed to record security audit log: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch an immediate security alert to the configured Telegram Bot.
     */
    protected function dispatchTelegramSecurityAlert(Request $request, string $threatType, string $details): void
    {
        try {
            $provider = \App\Models\NotificationProvider::where('channel', 'telegram')
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

            // Anti-flood throttle: max 1 alert per threat type every 10 seconds to avoid spamming Telegram
            $throttleKey = 'telegram_attack_alert_' . md5($threatType . '_' . $request->ip());
            if (\Illuminate\Support\Facades\Cache::has($throttleKey)) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($throttleKey, true, now()->addSeconds(10));

            $ip = htmlspecialchars((string) $request->ip(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $uri = htmlspecialchars(substr($request->getRequestUri(), 0, 150), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $time = now()->toDateTimeString();
            $label = htmlspecialchars(strtoupper(str_replace('_', ' ', $threatType)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $message = "🚨 <b>CodeBridge Security Attack Blocked</b>\n\n"
                . "• <b>Threat:</b> <code>{$label}</code>\n"
                . "• <b>Attacker IP:</b> <code>{$ip}</code>\n"
                . "• <b>Targeted URI:</b> <code>{$uri}</code>\n"
                . "• <b>Action:</b> BLOCKED (HTTP 403)\n"
                . "• <b>Timestamp:</b> {$time}";

            \Illuminate\Support\Facades\Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable) {
            // Fail-safe: Notification dispatch failure must never interrupt attack defense
        }
    }

    /**
     * Inject enterprise security headers into the HTTP response.
     */
    protected function injectSecurityHeaders(Response $response): void
    {
        $headers = $response->headers;

        // Prevent MIME type sniffing
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent framing / Clickjacking
        $headers->set('X-Frame-Options', 'DENY');

        // Legacy browser XSS filter
        $headers->set('X-XSS-Protection', '1; mode=block');

        // Strict Referrer policy
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HTTP Strict Transport Security (HSTS)
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Permissions Policy (disable unnecessary browser features)
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
