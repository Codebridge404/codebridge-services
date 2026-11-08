<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Security Event Forensic Audit Model.
 * Represents security findings (invalid JWTs, failed logins, tenant access denials, replay attacks).
 * Append-only immutable security ledger.
 */
class SecurityEvent extends Model
{
    use HasFactory;

    protected $table = 'security_events';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'request_id',
        'trace_id',
        'service_name',
        'environment',
        'event_type',
        'severity',
        'confidence',
        'outcome',
        'session_ref_hash',
        'ip_address',
        'user_agent',
        'method',
        'route_template',
        'status_code',
        'rule_code',
        'summary',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    /**
     * Sensitive fields redacted recursively from security event metadata.
     */
    protected static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'pin_code',
        'pin',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'two_factor_secret',
        'authorization',
        'bot_token',
        'credentials',
        'credential',
        'api_key',
        'private_key',
        'credit_card',
        'cvv',
        'recovery_code',
        'otp',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SecurityEvent $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }

            if (empty($event->service_name)) {
                $event->service_name = 'auth-service';
            }

            if (empty($event->environment)) {
                $event->environment = config('app.env', 'production');
            }

            if (empty($event->occurred_at)) {
                $event->occurred_at = now()->format('Y-m-d H:i:s.u');
            }

            if (empty($event->created_at)) {
                $event->created_at = now();
            }

            if (is_array($event->metadata)) {
                $event->metadata = static::redactSensitiveData($event->metadata);
            }
        });
    }

    /**
     * Redact sensitive credentials, passwords, tokens and secrets from metadata.
     */
    public static function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), static::$sensitiveKeys, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = static::redactSensitiveData($value);
            }
        }

        return $data;
    }

    /**
     * Helper to record a structured security event.
     */
    public static function record(
        string $eventType,
        string $ruleCode,
        string $summary,
        string $severity = 'medium',
        string $outcome = 'blocked',
        string $confidence = 'observed',
        ?int $userId = null,
        ?int $tenantId = null,
        ?Request $request = null,
        array $metadata = [],
        ?string $sessionRefHash = null,
        ?int $statusCode = null
    ): static {
        $req = $request ?? (app()->bound('request') ? request() : null);

        $requestId = $req?->header('X-Request-ID')
            ?? $req?->header('CF-Ray')
            ?? (string) Str::uuid();

        $traceId = $req?->header('X-Trace-ID')
            ?? substr(md5($requestId), 0, 32);

        $method = $req ? strtoupper($req->method()) : 'GET';
        $routeTemplate = $req?->route()?->uri() ?? ($req ? $req->path() : '');
        $ipAddress = $req?->ip();
        $userAgent = $req ? substr((string) $req->userAgent(), 0, 512) : null;
        $resolvedStatusCode = $statusCode ?? ($outcome === 'blocked' ? 401 : 200);

        return static::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'request_id' => substr($requestId, 0, 64),
            'trace_id' => substr($traceId, 0, 32),
            'service_name' => 'auth-service',
            'environment' => config('app.env', 'production'),
            'event_type' => $eventType,
            'severity' => $severity,
            'confidence' => $confidence,
            'outcome' => $outcome,
            'session_ref_hash' => $sessionRefHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'method' => $method,
            'route_template' => substr($routeTemplate, 0, 255),
            'status_code' => $resolvedStatusCode,
            'rule_code' => $ruleCode,
            'summary' => substr($summary, 0, 255),
            'metadata' => $metadata,
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Associated user relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Associated tenant relationship.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Query scope: filter by tenant.
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Query scope: filter by severity.
     */
    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Query scope: filter by event type.
     */
    public function scopeEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Query scope: filter by outcome.
     */
    public function scopeOutcome($query, string $outcome)
    {
        return $query->where('outcome', $outcome);
    }
}
