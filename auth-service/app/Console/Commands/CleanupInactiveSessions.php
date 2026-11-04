<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0042] Automated Inactive & Expired Sessions Periodic Cleanup.
 *
 * @ticket BD-0042
 * @component auth-service
 * @file codebridge-services/auth-service/app/Console/Commands/CleanupInactiveSessions.php
 * @command-trigger php artisan sessions:cleanup-inactive {--dry-run}
 *
 * Sweeps personal_access_tokens and revokes sessions that have exceeded their
 * owner's inactivity timeout policy or hard session expiration.
 */
class CleanupInactiveSessions extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sessions:cleanup-inactive
                            {--dry-run : Only report expired sessions without purging}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up inactive device sessions exceeding account inactivity timeout policies and expired tokens';

    public function handle(JwtService $jwtService): int
    {
        $dryRun = $this->option('dry-run');
        $this->info("⏳ Scanning for inactive & expired device sessions" . ($dryRun ? " (DRY RUN)" : "") . "...");

        $now = Carbon::now();
        $totalExpired = 0;
        $totalInactive = 0;

        // 1. Sweep sessions past explicit session_expires_at
        $explicitExpiredQuery = DB::table('personal_access_tokens')
            ->whereNotNull('session_expires_at')
            ->where('session_expires_at', '<', $now);

        $expiredCount = $explicitExpiredQuery->count();
        $totalExpired += $expiredCount;

        if (!$dryRun && $expiredCount > 0) {
            $explicitExpiredQuery->delete();
        }

        // 2. Sweep sessions past user-configured inactivity timeout (default 180 days)
        // Join users to evaluate individual session_inactivity_days preferences
        $tokens = DB::table('personal_access_tokens')
            ->join('users', 'personal_access_tokens.tokenable_id', '=', 'users.id')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->select([
                'personal_access_tokens.id',
                'personal_access_tokens.tokenable_id as user_id',
                'personal_access_tokens.last_used_at',
                'personal_access_tokens.created_at',
                'users.session_inactivity_days',
            ])
            ->get();

        $idsToPurge = [];
        foreach ($tokens as $token) {
            $inactivityDays = $token->session_inactivity_days ?: 180;
            $activityTime = $token->last_used_at
                ? Carbon::parse($token->last_used_at)
                : Carbon::parse($token->created_at);

            if ($activityTime->copy()->addDays($inactivityDays)->isPast()) {
                $idsToPurge[] = $token->id;
            }
        }

        $totalInactive = count($idsToPurge);

        if (!$dryRun && !empty($idsToPurge)) {
            // Chunk deletions to prevent lock contention
            foreach (array_chunk($idsToPurge, 200) as $chunk) {
                DB::table('personal_access_tokens')->whereIn('id', $chunk)->delete();
            }
        }

        $grandTotal = $totalExpired + $totalInactive;

        $this->info("✅ Inactivity session cleanup completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Hard-Expired Sessions (session_expires_at)', $totalExpired],
                ['Inactive Sessions Past Policy (session_inactivity_days)', $totalInactive],
                ['Total Sessions ' . ($dryRun ? 'Identified' : 'Purged'), $grandTotal],
                ['Executed At', $now->toIso8601String()],
            ]
        );

        Log::info('Automated sessions cleanup executed', [
            'dry_run' => $dryRun,
            'expired_count' => $totalExpired,
            'inactive_count' => $totalInactive,
            'total_purged' => $grandTotal,
        ]);

        return Command::SUCCESS;
    }
}
