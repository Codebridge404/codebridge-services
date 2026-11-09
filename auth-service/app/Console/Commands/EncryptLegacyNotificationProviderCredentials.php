<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EncryptLegacyNotificationProviderCredentials extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notification-providers:encrypt-credentials
                            {--dry-run : Inspect records without modifying stored credentials}';

    /**
     * The console command description.
     */
    protected $description = 'Encrypt legacy plaintext notification provider credentials using the configured application cipher';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $total = 0;
        $alreadyEncrypted = 0;
        $migrated = 0;
        $failed = 0;

        $rows = DB::table('notification_providers')->orderBy('id')->get(['id', 'credentials', 'config_version']);

        foreach ($rows as $row) {
            $total++;
            $raw = $row->credentials;

            if (!is_string($raw) || trim($raw) === '') {
                $failed++;
                $this->error("Provider #{$row->id}: credentials column is missing or empty.");
                continue;
            }

            try {
                Crypt::decryptString($raw);
                $alreadyEncrypted++;
                continue;
            } catch (\Throwable $decryptError) {
                if ($this->looksLikeLaravelEnvelope($raw)) {
                    $failed++;
                    Log::error('Notification provider ciphertext could not be decrypted during maintenance scan.', [
                        'provider_id' => $row->id,
                        'exception' => $decryptError::class,
                    ]);
                    $this->error("Provider #{$row->id}: stored ciphertext could not be decrypted with current key; skipped to avoid double encryption.");
                    continue;
                }
            }

            if ($dryRun) {
                $migrated++;
                $this->line("DRY-RUN: Provider #{$row->id} contains legacy plaintext credentials eligible for encryption.");
                continue;
            }

            try {
                DB::transaction(function () use ($row, $raw) {
                    $encrypted = Crypt::encryptString($raw);
                    if (!is_string($encrypted) || $encrypted === '') {
                        throw new \RuntimeException('Empty ciphertext produced.');
                    }

                    DB::table('notification_providers')
                        ->where('id', $row->id)
                        ->update([
                            'credentials' => $encrypted,
                            'config_version' => ((int) ($row->config_version ?? 1)) + 1,
                            'updated_at' => now(),
                        ]);
                });

                $migrated++;
                $this->info("Provider #{$row->id}: legacy plaintext credentials encrypted.");
            } catch (\Throwable $encryptError) {
                $failed++;
                Log::error('Notification provider legacy credential encryption failed.', [
                    'provider_id' => $row->id,
                    'exception' => $encryptError::class,
                ]);
                $this->error("Provider #{$row->id}: encryption failed cleanly; existing row left unchanged.");
            }
        }

        $this->line(sprintf(
            'Summary: total=%d, already_encrypted=%d, %s=%d, failed=%d',
            $total,
            $alreadyEncrypted,
            $dryRun ? 'would_encrypt' : 'encrypted',
            $migrated,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function looksLikeLaravelEnvelope(string $value): bool
    {
        $decodedBase64 = base64_decode($value, true);
        if (!is_string($decodedBase64) || $decodedBase64 === '') {
            return false;
        }

        $json = json_decode($decodedBase64, true);
        if (!is_array($json)) {
            return false;
        }

        return isset($json['iv'], $json['value'], $json['mac'])
            && is_string($json['iv'])
            && is_string($json['value'])
            && is_string($json['mac']);
    }
}

