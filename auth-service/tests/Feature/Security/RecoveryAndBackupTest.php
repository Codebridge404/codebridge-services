<?php

namespace Tests\Feature\Security;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoveryAndBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_database_backup_checksum_and_isolated_sqlite_recovery_drill(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create([
            'name' => 'Recovery Drill Cafe',
            'slug' => 'recovery-drill-' . Str::lower(Str::random(5)),
            'status' => 'active',
            'client_tier' => 'enterprise_org',
            'max_users' => 50,
            'max_outlets' => 10,
            'max_registers' => 20,
        ]);

        $outlet = Outlet::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Recovery Main Branch',
            'code' => 'RCV-01',
            'is_active' => true,
        ]);

        $role = Role::create([
            'name' => 'Recovery Auditor',
            'slug' => 'recovery_auditor',
            'is_system' => false,
            'tenant_id' => $tenant->id,
            'company_id' => $tenant->id,
        ]);

        $user = User::factory()->inWorkspace()->create([
            'name' => 'Recovery Admin User',
            'email' => 'recovery.admin@sunnycafe.kh',
            'tenant_id' => $tenant->id,
            'outlet_uuid' => $outlet->uuid,
            'role' => 'admin',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $this->artisan('backup:database-encrypted', [
            '--destination' => 'local',
            '--retention' => 7,
        ])->assertExitCode(0);

        $backupFiles = Storage::disk('local')->files('backups');
        $encFiles = array_values(array_filter($backupFiles, fn (string $f) => str_ends_with($f, '.sql.gz.enc')));
        $shaFiles = array_values(array_filter($backupFiles, fn (string $f) => str_ends_with($f, '.sha256')));

        $this->assertCount(1, $encFiles);
        $this->assertCount(1, $shaFiles);

        $ciphertext = (string) Storage::disk('local')->get($encFiles[0]);
        $checksumManifest = (string) Storage::disk('local')->get($shaFiles[0]);

        // 1. Verify SHA-256 digest matches the encrypted archive bytes
        $expectedHash = trim(explode(' ', trim($checksumManifest))[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expectedHash);
        $this->assertTrue(hash_equals($expectedHash, hash('sha256', $ciphertext)));

        // 2. Tamper detection: corrupting 1 byte breaks SHA-256 verification and AES-CBC HMAC decryption
        $tampered = substr($ciphertext, 0, -4) . 'XXXX';
        $this->assertFalse(hash_equals($expectedHash, hash('sha256', $tampered)));
        $decryptThrew = false;
        try {
            Crypt::decrypt($tampered, false);
        } catch (DecryptException) {
            $decryptThrew = true;
        }
        $this->assertTrue($decryptThrew, 'Tampered encrypted backup must fail authenticated decryption.');

        // 3. Full recovery drill into a brand-new isolated SQLite database
        $compressedSql = Crypt::decrypt($ciphertext, false);
        $plainSql = gzdecode($compressedSql);
        $this->assertIsString($plainSql);
        $this->assertStringContainsString('INSERT INTO `tenants`', $plainSql);
        $this->assertStringContainsString('INSERT INTO `users`', $plainSql);

        $recoveryPdo = new \PDO('sqlite::memory:');
        $recoveryPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $recoveryPdo->exec('PRAGMA foreign_keys = OFF;');
        $recoveryPdo->exec($plainSql);

        $restoredTenant = $recoveryPdo->query("SELECT name, slug FROM tenants WHERE id = " . (int) $tenant->id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Recovery Drill Cafe', $restoredTenant['name'] ?? null);
        $this->assertSame($tenant->slug, $restoredTenant['slug'] ?? null);

        $restoredOutlet = $recoveryPdo->query("SELECT code, name FROM outlets WHERE id = " . (int) $outlet->id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('RCV-01', $restoredOutlet['code'] ?? null);
        $this->assertSame('Recovery Main Branch', $restoredOutlet['name'] ?? null);

        $restoredRole = $recoveryPdo->query("SELECT slug, tenant_id FROM roles WHERE id = " . (int) $role->id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('recovery_auditor', $restoredRole['slug'] ?? null);
        $this->assertEquals($tenant->id, $restoredRole['tenant_id'] ?? null);

        $restoredUser = $recoveryPdo->query(
            "SELECT u.email, u.name, tu.tenant_id, r.slug AS role_slug
             FROM users u
             INNER JOIN tenant_users tu ON tu.user_id = u.id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = " . (int) $user->id
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('recovery.admin@sunnycafe.kh', $restoredUser['email'] ?? null);
        $this->assertSame('Recovery Admin User', $restoredUser['name'] ?? null);
        $this->assertSame('admin', $restoredUser['role_slug'] ?? null);
        $this->assertEquals($tenant->id, $restoredUser['tenant_id'] ?? null);
    }

    public function test_backup_restore_script_verify_mode_validates_checksum_and_blocks_tampered_archives(): void
    {
        $restoreScript = base_path('deploy/backup/restore.sh');
        $backupScript = base_path('deploy/backup/backup.sh');
        $this->assertFileExists($restoreScript);
        $this->assertTrue(is_executable($restoreScript));
        $this->assertStringContainsString('write_sha256', File::get($backupScript));

        $tempDir = storage_path('framework/testing/restore_verify_' . uniqid('', true));
        File::makeDirectory($tempDir, 0755, true);

        try {
            $archivePath = "{$tempDir}/mysql_codebridge_auth_test.sql.gz";
            $checksumPath = "{$archivePath}.sha256";

            $gzipBytes = gzencode("-- test backup SQL\nSELECT 1;\n", 9);
            $this->assertIsString($gzipBytes);
            File::put($archivePath, $gzipBytes);
            File::put($checksumPath, hash('sha256', $gzipBytes) . "  mysql_codebridge_auth_test.sql.gz\n");

            // 1. Valid archive passes --verify cleanly
            $ok = Process::run('bash ' . escapeshellarg($restoreScript) . ' --verify ' . escapeshellarg($archivePath));
            $this->assertTrue($ok->successful(), 'restore.sh --verify failed: ' . $ok->errorOutput() . $ok->output());

            // 2. Missing checksum sidecar fails verification
            File::delete($checksumPath);
            $missingSha = Process::run('bash ' . escapeshellarg($restoreScript) . ' --verify ' . escapeshellarg($archivePath));
            $this->assertFalse($missingSha->successful(), 'Missing .sha256 sidecar must fail --verify.');

            // 3. Mismatched checksum fails verification (tamper detection)
            File::put($checksumPath, str_repeat('0', 64) . "  mysql_codebridge_auth_test.sql.gz\n");
            $badSha = Process::run('bash ' . escapeshellarg($restoreScript) . ' --verify ' . escapeshellarg($archivePath));
            $this->assertFalse($badSha->successful(), 'Mismatched .sha256 digest must fail --verify.');
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_bluegreen_rollback_restores_previous_slot_without_rebuilding_image(): void
    {
        $tempDir = storage_path('framework/testing/bg_rollback_' . uniqid('', true));
        File::makeDirectory($tempDir, 0755, true);

        try {
            $scriptsDir = "{$tempDir}/deploy/bluegreen";
            File::copyDirectory(base_path('deploy/bluegreen'), $scriptsDir);
            File::put("{$scriptsDir}/.active_color", "green\n");
            File::put("{$tempDir}/docker-compose.bluegreen.yml", "services: {}\n");

            $binDir = "{$tempDir}/bin";
            File::makeDirectory($binDir, 0755, true);

            $mockDocker = <<<'BASH'
#!/usr/bin/env bash
echo "$@" >> "$MOCK_LOG"
ARGS="$*"
if [[ "$ARGS" == *"ps -o args"* ]]; then
    exit 1
fi
if [[ "$ARGS" == *"http://auth-proxy/health"* ]]; then
    exit 0
fi
exit 0
BASH;
            File::put("{$binDir}/docker", $mockDocker);
            chmod("{$binDir}/docker", 0755);

            File::put("{$binDir}/sleep", "#!/bin/sh\nexit 0\n");
            chmod("{$binDir}/sleep", 0755);

            $logFile = "{$tempDir}/rollback_calls.log";
            $env = [
                'PATH' => "{$binDir}:" . getenv('PATH'),
                'MOCK_LOG' => $logFile,
            ];

            $res = Process::env($env)->run("bash {$scriptsDir}/rollback.sh");
            $this->assertTrue($res->successful(), 'rollback.sh failed: ' . $res->errorOutput());

            $this->assertSame('blue', trim(File::get("{$scriptsDir}/.active_color")));

            $calls = File::get($logFile);
            $this->assertStringContainsString('up -d --no-build auth-service-blue', $calls);
            $this->assertStringContainsString('up -d --no-build auth-horizon-blue', $calls);
            $this->assertStringNotContainsString(' build ', $calls, 'Rollback must reuse immutable tested previous image without rebuilding.');
            $this->assertMatchesRegularExpression('/stop.*auth-service-green/', $calls);
        } finally {
            File::deleteDirectory($tempDir);
        }
    }
}
