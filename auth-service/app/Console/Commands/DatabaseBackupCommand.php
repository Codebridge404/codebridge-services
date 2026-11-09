<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Automated Daily Database Backup & Encrypted MinIO/S3 Cloud Storage Upload Job (Auth DB).
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
        $connectionName = config('database.default', 'mysql');
        $database = $this->option('database') ?: config("database.connections.{$connectionName}.database", 'codebridge_auth');
        $destination = $this->option('destination') ?: 'local';
        $retentionDays = (int) ($this->option('retention') ?: 30);

        $this->info("Starting encrypted auth database backup for [{$database}]...");
        Log::info("[DatabaseBackupCommand] Starting encrypted backup for database: {$database}");

        try {
            $sqlContent = $this->generateSqlDump((string) $database, (string) $connectionName);
            $compressed = gzencode($sqlContent, 9);
            if ($compressed === false) {
                throw new \RuntimeException('Failed to compress SQL dump.');
            }

            $encrypted = Crypt::encrypt($compressed, false);
            $checksum = hash('sha256', $encrypted);
            $timestamp = now()->format('Ymd_His');
            $filename = "backup_{$database}_{$timestamp}.sql.gz.enc";
            $checksumFilename = "backup_{$database}_{$timestamp}.sha256";

            $backupDir = 'backups';
            $fullBackupPath = "{$backupDir}/{$filename}";
            $fullChecksumPath = "{$backupDir}/{$checksumFilename}";

            Storage::disk('local')->put($fullBackupPath, $encrypted);
            Storage::disk('local')->put(
                $fullChecksumPath,
                "{$checksum}  {$filename}\nAlgorithm: AES-256-CBC\nDatabase: {$database}\nTimestamp: " . now()->toIso8601String() . "\n"
            );

            if ($destination === 'minio' || $destination === 's3') {
                $targetDisk = config('filesystems.disks.minio.key') ? 'minio' : 's3';
                if (config("filesystems.disks.{$targetDisk}.key")) {
                    Storage::disk($targetDisk)->put($fullBackupPath, $encrypted);
                    Storage::disk($targetDisk)->put($fullChecksumPath, "{$checksum}  {$filename}\n");
                    $this->info("Uploaded encrypted archive to {$targetDisk}://{$fullBackupPath}");
                } else {
                    $this->warn('MinIO/S3 credentials not configured; encrypted backup stored locally.');
                }
            }

            $this->purgeOldBackups($backupDir, $retentionDays);

            Log::info("[DatabaseBackupCommand] Encrypted backup succeeded: {$filename} (SHA256: {$checksum})");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            Log::error('[DatabaseBackupCommand] Encrypted backup failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function generateSqlDump(string $database, string $connectionName = 'mysql'): string
    {
        $connection = DB::connection($connectionName);
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();

        $out = "-- CodeBridge Auth Encrypted Database Backup\n";
        $out .= "-- Database: {$database}\n";
        $out .= "-- Date: " . now()->toDateTimeString() . " (" . config('app.timezone') . ")\n\n";

        if ($driver === 'sqlite') {
            $tables = $connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            foreach ($tables as $t) {
                $tableName = (string) $t->name;
                $escapedTable = str_replace('`', '``', $tableName);
                $out .= "DROP TABLE IF EXISTS `{$escapedTable}`;\n";
                $out .= ($t->sql ?? '') . ";\n\n";

                $rows = $connection->table($tableName)->get();
                if ($rows->count() > 0) {
                    $out .= "INSERT INTO `{$escapedTable}` VALUES \n";
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

            return $out;
        }

        $tables = $connection->select('SHOW TABLES');
        $keyName = "Tables_in_{$database}";

        $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $t) {
            $tableName = $t->$keyName ?? array_values((array) $t)[0];
            $escapedTable = str_replace('`', '``', (string) $tableName);

            $createTable = $connection->select("SHOW CREATE TABLE `{$escapedTable}`");
            $createSql = $createTable[0]->{'Create Table'} ?? '';
            $out .= "DROP TABLE IF EXISTS `{$escapedTable}`;\n";
            $out .= $createSql . ";\n\n";

            $rows = $connection->table($tableName)->get();
            if ($rows->count() > 0) {
                $out .= "INSERT INTO `{$escapedTable}` VALUES \n";
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

    protected function purgeOldBackups(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays)->timestamp;
        $files = Storage::disk('local')->files($backupDir);

        foreach ($files as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
