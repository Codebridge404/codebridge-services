<?php

namespace App\Services;

use App\Support\SchemaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Cross-Service Queue, Cron & Failed Jobs Monitor Service.
 *
 * Inspects auth-service queues, failed_jobs, Horizon worker status, and
 * scheduler heartbeats directly, and queries operations-service over its
 * authenticated internal monitoring HTTP endpoint without crossing DB boundaries.
 */
class QueueMonitorService
{
    public const AUTH_SCHEDULER_HEARTBEAT_KEY = 'monitoring:auth:scheduler:last_seen';

    public const HEARTBEAT_STALE_SECONDS = 300;

    /**
     * Collect queue, failed job, worker, and scheduler status across services.
     *
     * @return array{services: array<int, array<string, mixed>>}
     */
    public function getOverview(): array
    {
        return [
            'services' => [
                $this->collectAuthServiceStatus(),
                $this->collectOperationsServiceStatus(),
            ],
        ];
    }

    /**
     * Retry a failed job on the specified service.
     *
     * @return array{status: string, http_status: int, code?: string, message: string, data?: array<string, mixed>}
     */
    public function retryFailedJob(string $service, string $jobId): array
    {
        if (! in_array($service, ['auth-service', 'operations-service'], true)) {
            return [
                'status' => 'error',
                'http_status' => 422,
                'code' => 'INVALID_SERVICE',
                'message' => 'Unsupported service name. Expected auth-service or operations-service.',
            ];
        }

        if ($service === 'auth-service') {
            return $this->retryAuthFailedJob($jobId);
        }

        return $this->retryOperationsFailedJob($jobId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectAuthServiceStatus(): array
    {
        $pendingJobs = $this->getLocalPendingJobsCount();
        $failedData = $this->getLocalFailedJobs();
        $workerStatus = $this->detectLocalWorkerStatus();
        $scheduler = $this->detectLocalSchedulerStatus();

        return [
            'name' => 'auth-service',
            'reachable' => true,
            'queue' => [
                'connection' => (string) config('queue.default', 'sync'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedData['count'],
                'worker_status' => $workerStatus,
                'recent_failed_jobs' => $failedData['recent'],
            ],
            'scheduler' => $scheduler,
        ];
    }

    /**
     * Query operations-service over its internal monitoring endpoint.
     * Returns unknown/unavailable honestly when the endpoint is not reachable.
     *
     * @return array<string, mixed>
     */
    protected function collectOperationsServiceStatus(): array
    {
        $url = (string) env('OPERATIONS_INTERNAL_QUEUES_URL', '');
        if ($url === '') {
            return $this->unavailableOperationsStatus('Internal operations queue monitor URL is not configured.');
        }

        try {
            $headers = ['Accept' => 'application/json'];
            $secret = (string) env('INTERNAL_SERVICE_SECRET', '');
            if ($secret !== '') {
                $headers['X-Internal-Service-Secret'] = $secret;
            }

            $response = Http::timeout(2)->withHeaders($headers)->get($url);
            if ($response->successful()) {
                $payload = $response->json('data') ?? $response->json();
                if (is_array($payload) && isset($payload['queue'], $payload['scheduler'])) {
                    return [
                        'name' => 'operations-service',
                        'reachable' => true,
                        'queue' => [
                            'connection' => $payload['queue']['connection'] ?? 'unknown',
                            'pending_jobs' => $payload['queue']['pending_jobs'] ?? null,
                            'failed_jobs' => $payload['queue']['failed_jobs'] ?? null,
                            'worker_status' => $payload['queue']['worker_status'] ?? 'unknown',
                            'recent_failed_jobs' => $this->sanitizeFailedJobsList($payload['queue']['recent_failed_jobs'] ?? []),
                        ],
                        'scheduler' => [
                            'status' => $payload['scheduler']['status'] ?? 'unknown',
                            'last_heartbeat_at' => $payload['scheduler']['last_heartbeat_at'] ?? null,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
            // Fall through to unavailable
        }

        return $this->unavailableOperationsStatus('Operations internal queue monitor is currently unreachable.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function unavailableOperationsStatus(string $reason): array
    {
        return [
            'name' => 'operations-service',
            'reachable' => false,
            'message' => $reason,
            'queue' => [
                'connection' => 'unknown',
                'pending_jobs' => null,
                'failed_jobs' => null,
                'worker_status' => 'unknown',
                'recent_failed_jobs' => [],
            ],
            'scheduler' => [
                'status' => 'unknown',
                'last_heartbeat_at' => null,
            ],
        ];
    }

    protected function getLocalPendingJobsCount(): ?int
    {
        try {
            $connection = (string) config('queue.default', 'sync');
            if ($connection === 'sync') {
                return 0;
            }
            if ($connection === 'database' && SchemaGuard::hasTable('jobs')) {
                return (int) DB::table('jobs')->count();
            }

            return (int) Queue::size();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{count: int, recent: array<int, array<string, mixed>>}
     */
    protected function getLocalFailedJobs(): array
    {
        if (! SchemaGuard::hasTable('failed_jobs')) {
            return ['count' => 0, 'recent' => []];
        }

        $count = (int) DB::table('failed_jobs')->count();
        $rows = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $recent = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->payload, true);
            $jobName = is_array($decoded) ? ($decoded['displayName'] ?? 'UnknownJob') : 'UnknownJob';
            $firstLine = strtok(trim((string) $row->exception), "\r\n") ?: 'Job execution failed.';

            $recent[] = [
                'id' => (string) $row->id,
                'uuid' => (string) $row->uuid,
                'job_name' => (string) $jobName,
                'queue' => (string) $row->queue,
                'connection' => (string) $row->connection,
                'error_summary' => Str::limit($firstLine, 240),
                'failed_at' => $row->failed_at ? Carbon::parse($row->failed_at)->toIso8601String() : null,
            ];
        }

        return [
            'count' => $count,
            'recent' => $recent,
        ];
    }

    protected function detectLocalWorkerStatus(): string
    {
        try {
            if (class_exists(MasterSupervisorRepository::class)) {
                $masters = app(MasterSupervisorRepository::class)->all();
                if (! empty($masters)) {
                    foreach ($masters as $master) {
                        if (($master->status ?? null) === 'running') {
                            return 'healthy';
                        }
                    }

                    return 'degraded';
                }
            }
        } catch (\Throwable) {
            // Fall through to unknown
        }

        return 'unknown';
    }

    /**
     * @return array{status: string, last_heartbeat_at: ?string}
     */
    protected function detectLocalSchedulerStatus(): array
    {
        try {
            $lastSeen = Cache::get(self::AUTH_SCHEDULER_HEARTBEAT_KEY);
            if (! is_string($lastSeen) || $lastSeen === '') {
                return [
                    'status' => 'unknown',
                    'last_heartbeat_at' => null,
                ];
            }

            $ts = Carbon::parse($lastSeen);
            $isHealthy = $ts->gt(Carbon::now()->subSeconds(self::HEARTBEAT_STALE_SECONDS));

            return [
                'status' => $isHealthy ? 'healthy' : 'stale',
                'last_heartbeat_at' => $ts->toIso8601String(),
            ];
        } catch (\Throwable) {
            return [
                'status' => 'unknown',
                'last_heartbeat_at' => null,
            ];
        }
    }

    /**
     * @return array{status: string, http_status: int, code?: string, message: string, data?: array<string, mixed>}
     */
    protected function retryAuthFailedJob(string $jobId): array
    {
        if (! SchemaGuard::hasTable('failed_jobs')) {
            return [
                'status' => 'error',
                'http_status' => 404,
                'code' => 'JOB_NOT_FOUND',
                'message' => 'Failed jobs table is not available.',
            ];
        }

        $job = DB::table('failed_jobs')
            ->where('uuid', $jobId)
            ->when(is_numeric($jobId), fn ($q) => $q->orWhere('id', (int) $jobId))
            ->first();

        if (! $job) {
            return [
                'status' => 'error',
                'http_status' => 404,
                'code' => 'JOB_NOT_FOUND',
                'message' => 'Failed job not found in auth-service.',
            ];
        }

        $decoded = json_decode((string) $job->payload, true);
        $jobName = is_array($decoded) ? ($decoded['displayName'] ?? 'UnknownJob') : 'UnknownJob';

        Artisan::call('queue:retry', ['id' => [(string) $job->uuid]]);

        return [
            'status' => 'success',
            'http_status' => 200,
            'message' => 'Failed job queued for retry in auth-service.',
            'data' => [
                'service' => 'auth-service',
                'job_id' => (string) $job->id,
                'job_uuid' => (string) $job->uuid,
                'job_name' => (string) $jobName,
                'retried_at' => Carbon::now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{status: string, http_status: int, code?: string, message: string, data?: array<string, mixed>}
     */
    protected function retryOperationsFailedJob(string $jobId): array
    {
        $baseUrl = rtrim((string) env('OPERATIONS_INTERNAL_QUEUES_URL', ''), '/');
        if ($baseUrl === '') {
            return [
                'status' => 'error',
                'http_status' => 503,
                'code' => 'INTERNAL_MONITOR_UNAVAILABLE',
                'message' => 'Operations internal queue monitor URL is not configured.',
            ];
        }

        try {
            $headers = ['Accept' => 'application/json'];
            $secret = (string) env('INTERNAL_SERVICE_SECRET', '');
            if ($secret !== '') {
                $headers['X-Internal-Service-Secret'] = $secret;
            }

            $response = Http::timeout(4)
                ->withHeaders($headers)
                ->post("{$baseUrl}/retry/".urlencode($jobId));

            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'http_status' => 200,
                    'message' => $response->json('message') ?? 'Failed job queued for retry in operations-service.',
                    'data' => [
                        'service' => 'operations-service',
                        'job_id' => $jobId,
                        'retried_at' => Carbon::now()->toIso8601String(),
                    ],
                ];
            }

            if ($response->status() === 404) {
                return [
                    'status' => 'error',
                    'http_status' => 404,
                    'code' => 'JOB_NOT_FOUND',
                    'message' => 'Failed job not found in operations-service.',
                ];
            }
        } catch (\Throwable) {
            // Fall through to 503
        }

        return [
            'status' => 'error',
            'http_status' => 503,
            'code' => 'INTERNAL_MONITOR_UNAVAILABLE',
            'message' => 'Operations internal queue monitor is currently unreachable.',
        ];
    }

    /**
     * Strip any raw payloads or credentials from upstream failed job summaries.
     *
     * @param  array<int, mixed>  $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeFailedJobsList(array $jobs): array
    {
        $sanitized = [];
        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }
            $sanitized[] = [
                'id' => (string) ($job['id'] ?? ''),
                'uuid' => (string) ($job['uuid'] ?? ''),
                'job_name' => (string) ($job['job_name'] ?? 'UnknownJob'),
                'queue' => (string) ($job['queue'] ?? 'default'),
                'connection' => (string) ($job['connection'] ?? 'unknown'),
                'error_summary' => Str::limit((string) ($job['error_summary'] ?? 'Job failed.'), 240),
                'failed_at' => $job['failed_at'] ?? null,
            ];
        }

        return $sanitized;
    }
}
