<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * [BD-0016] Automated Daily Database Backup & Encrypted MinIO/S3 Cloud Storage Upload Job.
 *
 * Command: php artisan backup:database-encrypted {--database=} {--destination=minio}
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database-encrypted
                            {--database= : Specify target database name (default from config)}
                            {--destination=local : Storage destination disk (minio, s3, or local)}
                            {--retention=30 : Number of days to keep backup archives}';

    protected $description = 'Performs an encrypted, compressed database backup and uploads archive to MinIO/S3 cloud storage.';

    public function handle(): int
    {
        $database = $this->option('database') ?: config('database.connections.mysql.database', 'codebridge_operations');
        $destination = $this->option('destination') ?: 'local';
        $retentionDays = (int) ($this->option('retention') ?: 30);

        $this->info("🔒 Starting encrypted database backup for [{$database}]...");
        Log::info("[DatabaseBackupCommand] Starting encrypted backup for database: {$database}");

        try {
            // 1. Generate Raw SQL Dump
            $sqlContent = $this->generateSqlDump($database);
            $rawSize = strlen($sqlContent);
            $this->line("  -> SQL dump generated (" . number_format($rawSize / 1024, 1) . " KB)");

            // 2. Gzip Compression
            $compressed = gzencode($sqlContent, 9);
            $compressedSize = strlen($compressed);
            $this->line("  -> Compressed with Gzip level 9 (" . number_format($compressedSize / 1024, 1) . " KB)");

            // 3. AES-256-CBC Encryption
            $encrypted = Crypt::encrypt($compressed, false);
            $encryptedSize = strlen($encrypted);
            $this->line("  -> Encrypted with AES-256-CBC (" . number_format($encryptedSize / 1024, 1) . " KB)");

            // 4. Integrity Checksum
            $checksum = hash('sha256', $encrypted);
            $timestamp = now()->format('Ymd_His');
            $filename = "backup_{$database}_{$timestamp}.sql.gz.enc";
            $checksumFilename = "backup_{$database}_{$timestamp}.sha256";

            // 5. Store File
            $backupDir = 'backups';
            $fullBackupPath = "{$backupDir}/{$filename}";
            $fullChecksumPath = "{$backupDir}/{$checksumFilename}";

            // Always write local resilient copy
            Storage::disk('local')->put($fullBackupPath, $encrypted);
            Storage::disk('local')->put($fullChecksumPath, "{$checksum}  {$filename}\nAlgorithm: AES-256-CBC\nDatabase: {$database}\nTimestamp: " . now()->toIso8601String() . "\n");

            $this->info("  -> Saved local backup: storage/app/{$fullBackupPath}");

            // Upload to Cloud (MinIO/S3) if configured
            if ($destination === 'minio' || $destination === 's3') {
                if (config('filesystems.disks.s3.key')) {
                    Storage::disk('s3')->put($fullBackupPath, $encrypted);
                    Storage::disk('s3')->put($fullChecksumPath, "{$checksum}  {$filename}\n");
                    $this->info("  -> Uploaded encrypted archive to MinIO/S3: s3://{$fullBackupPath}");
                } else {
                    $this->warn("  -> MinIO/S3 credentials not fully configured; encrypted backup safely kept in local storage.");
                }
            }

            // 6. Purge Expired Backups
            $this->purgeOldBackups($backupDir, $retentionDays);

            $this->info("✅ Encrypted database backup completed successfully! SHA-256: {$checksum}");
            Log::info("[DatabaseBackupCommand] Encrypted backup succeeded: {$filename} (SHA256: {$checksum})");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("❌ Backup failed: " . $e->getMessage());
            Log::error("[DatabaseBackupCommand] Encrypted backup failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Dumps all tables, structures, and row data into a single SQL script.
     */
    protected function generateSqlDump(string $database): string
    {
        $pdo = DB::connection('mysql')->getPdo();
        $tables = DB::connection('mysql')->select('SHOW TABLES');
        $keyName = "Tables_in_{$database}";

        $out = "-- =====================================================================\n";
        $out .= "-- CodeBridge Encrypted Database Backup\n";
        $out .= "-- Database: {$database}\n";
        $out .= "-- Date: " . now()->toDateTimeString() . " (" . config('app.timezone') . ")\n";
        $out .= "-- =====================================================================\n\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $t) {
            $tableName = $t->$keyName ?? array_values((array) $t)[0];

            // Table Structure
            $createTable = DB::connection('mysql')->select("SHOW CREATE TABLE `{$tableName}`");
            $createSql = $createTable[0]->{'Create Table'} ?? '';
            $out .= "-- -----------------------------------------------------\n";
            $out .= "-- Table structure for `{$tableName}`\n";
            $out .= "-- -----------------------------------------------------\n";
            $out .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
            $out .= $createSql . ";\n\n";

            // Table Data
            $rows = DB::connection('mysql')->table($tableName)->get();
            if ($rows->count() > 0) {
                $out .= "-- Data for `{$tableName}` ({$rows->count()} rows)\n";
                $out .= "INSERT INTO `{$tableName}` VALUES \n";
                $rowStrings = [];

                foreach ($rows as $row) {
                    $values = [];
                    foreach ((array) $row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($val) && !is_string($val)) {
                            $values[] = $val;
                        } else {
                            $values[] = $pdo->quote((string) $val);
                        }
                    }
                    $rowStrings[] = '(' . implode(', ', $values) . ')';
                }

                $out .= implode(",\n", $rowStrings) . ";\n\n";
            }
        }

        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $out;
    }

    /**
     * Delete backups older than retention window.
     */
    protected function purgeOldBackups(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays)->timestamp;
        $files = Storage::disk('local')->files($backupDir);
        $deleted = 0;

        foreach ($files as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->line("  -> Cleaned up {$deleted} expired backup archives older than {$retentionDays} days.");
        }
    }
}
