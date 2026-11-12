<?php

use App\Models\NotificationProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('notification_providers')) {
            return;
        }

        // 1. Migrate records from meeting_telegram_configs
        if (DB::getSchemaBuilder()->hasTable('meeting_telegram_configs')) {
            $configs = DB::table('meeting_telegram_configs')
                ->whereNotNull('bot_token')
                ->where('bot_token', '!=', '')
                ->get();

            foreach ($configs as $config) {
                $token = trim((string) $config->bot_token);
                if ($token === '') {
                    continue;
                }

                $tenantId = $config->tenant_id !== null ? (int) $config->tenant_id : null;
                $chatId = trim((string) ($config->chat_id ?? ''));
                $isActive = (bool) $config->is_active;

                DB::transaction(function () use ($config, $tenantId, $token, $chatId, $isActive) {
                    $provider = $this->upsertMeetingProviderPreservingExisting(
                        tenantId: $tenantId,
                        legacyToken: $token,
                        legacyChatId: $chatId,
                        legacyEnabled: $isActive,
                        sourceTable: 'meeting_telegram_configs'
                    );

                    $this->assertVerifiedEncryptedCredentials($provider->id, $token);

                    // Clear plaintext token only after encrypted persistence & decryption succeed
                    DB::table('meeting_telegram_configs')
                        ->where('id', $config->id)
                        ->update(['bot_token' => '']);
                });
            }
        }

        // 2. Migrate records from meeting_system_settings
        if (DB::getSchemaBuilder()->hasTable('meeting_system_settings')) {
            $tokenRows = DB::table('meeting_system_settings')
                ->where('setting_key', 'notification.telegram_bot_token')
                ->whereNotNull('setting_value')
                ->where('setting_value', '!=', '')
                ->get();

            foreach ($tokenRows as $tokenRow) {
                $token = trim((string) $tokenRow->setting_value);
                if ($token === '') {
                    continue;
                }

                $tenantId = $tokenRow->tenant_id !== null ? (int) $tokenRow->tenant_id : null;

                $chatId = (string) (DB::table('meeting_system_settings')
                    ->where('setting_key', 'notification.telegram_default_chat_id')
                    ->where(function ($q) use ($tenantId) {
                        if ($tenantId !== null) {
                            $q->where('tenant_id', $tenantId);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })->value('setting_value') ?? '');
                $chatId = trim($chatId);

                $enabled = DB::table('meeting_system_settings')
                    ->where('setting_key', 'notification.telegram_enabled')
                    ->where(function ($q) use ($tenantId) {
                        if ($tenantId !== null) {
                            $q->where('tenant_id', $tenantId);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })->value('setting_value');
                $isEnabled = $enabled === 'true' || $enabled === '1';

                DB::transaction(function () use ($tokenRow, $tenantId, $token, $chatId, $isEnabled) {
                    $provider = $this->upsertMeetingProviderPreservingExisting(
                        tenantId: $tenantId,
                        legacyToken: $token,
                        legacyChatId: $chatId,
                        legacyEnabled: $isEnabled,
                        sourceTable: 'meeting_system_settings'
                    );

                    $this->assertVerifiedEncryptedCredentials($provider->id, $token);

                    // Delete raw plaintext token row only after encrypted persistence & decryption succeed
                    DB::table('meeting_system_settings')
                        ->where('id', $tokenRow->id)
                        ->delete();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to preserve security of encrypted credentials
    }

    /**
     * Locate, repair, preserve, or create the dedicated Telegram meeting provider for a tenant
     * without letting unrelated or disabled Telegram providers cause credential loss.
     */
    private function upsertMeetingProviderPreservingExisting(
        ?int $tenantId,
        string $legacyToken,
        string $legacyChatId,
        bool $legacyEnabled,
        string $sourceTable
    ): NotificationProvider {
        $candidates = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId !== null) {
                    $q->where('tenant_id', $tenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(function (NotificationProvider $p): bool {
                $purpose = is_array($p->settings) ? ($p->settings['purpose'] ?? null) : null;

                return $purpose === 'meeting_alerts'
                    || $p->name === 'Telegram Meeting Alerts'
                    || str_starts_with((string) $p->name, 'Telegram Meeting Alerts (Migrated Legacy');
            })
            ->values();

        // A. Existing decryptable meeting provider already holds the exact same legacy bot token
        foreach ($candidates as $candidate) {
            try {
                $creds = $candidate->getDecryptedCredentials();
                $existingToken = trim((string) ($creds['bot_token'] ?? ($creds['token'] ?? '')));
                if ($existingToken === $legacyToken) {
                    $dirty = false;
                    $settings = is_array($candidate->settings) ? $candidate->settings : [];
                    if (($settings['purpose'] ?? null) !== 'meeting_alerts') {
                        $settings['purpose'] = 'meeting_alerts';
                        $candidate->settings = $settings;
                        $dirty = true;
                    }
                    if ($legacyChatId !== '' && empty($creds['chat_id'])) {
                        $candidate->credentials = [
                            'bot_token' => $legacyToken,
                            'chat_id' => $legacyChatId,
                        ];
                        $settings['default_chat_id'] = $settings['default_chat_id'] ?? $legacyChatId;
                        $candidate->settings = $settings;
                        $dirty = true;
                    }
                    if ($dirty) {
                        $candidate->save();
                    }

                    return $candidate;
                }
            } catch (\Throwable) {
                // Handled in step B (corrupted/incomplete candidate)
            }
        }

        // B. Incomplete or corrupted meeting provider exists: repair with encrypted legacy credentials
        foreach ($candidates as $candidate) {
            $needsRepair = false;
            $existingChatId = '';
            try {
                $creds = $candidate->getDecryptedCredentials();
                $existingToken = trim((string) ($creds['bot_token'] ?? ($creds['token'] ?? '')));
                $existingChatId = trim((string) ($creds['chat_id'] ?? ''));
                if ($existingToken === '') {
                    $needsRepair = true;
                }
            } catch (\Throwable) {
                $needsRepair = true;
            }

            if ($needsRepair) {
                $settings = is_array($candidate->settings) ? $candidate->settings : [];
                $resolvedChatId = $legacyChatId !== ''
                    ? $legacyChatId
                    : ($existingChatId !== '' ? $existingChatId : (string) ($settings['default_chat_id'] ?? ''));

                $settings['purpose'] = 'meeting_alerts';
                $settings['migrated_from'] = $sourceTable;
                $settings['repaired_incomplete_or_corrupted'] = true;
                if ($resolvedChatId !== '') {
                    $settings['default_chat_id'] = $resolvedChatId;
                }

                $candidate->is_enabled = (bool) ($candidate->is_enabled || $legacyEnabled);
                $candidate->credentials = [
                    'bot_token' => $legacyToken,
                    'chat_id' => $resolvedChatId,
                ];
                $candidate->settings = $settings;
                $candidate->save();

                return $candidate;
            }
        }

        // C. Conflicting valid meeting provider already exists: preserve both configs separately
        $hasConflictingMeetingProvider = $candidates->isNotEmpty();
        $baseName = $hasConflictingMeetingProvider
            ? 'Telegram Meeting Alerts (Migrated Legacy)'
            : 'Telegram Meeting Alerts';

        $name = $baseName;
        if ($hasConflictingMeetingProvider) {
            $suffix = 2;
            while ($candidates->contains(fn (NotificationProvider $p): bool => $p->name === $name)) {
                $name = 'Telegram Meeting Alerts (Migrated Legacy ' . $suffix . ')';
                $suffix++;
            }
        }

        $settings = [
            'default_chat_id' => $legacyChatId,
            'purpose' => 'meeting_alerts',
            'migrated_from' => $sourceTable,
        ];
        if ($hasConflictingMeetingProvider) {
            $settings['migrated_conflict'] = true;
        }

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $tenantId;
        $provider->name = $name;
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = $legacyEnabled;
        $provider->credentials = [
            'bot_token' => $legacyToken,
            'chat_id' => $legacyChatId,
        ];
        $provider->settings = $settings;
        $provider->save();

        return $provider;
    }

    /**
     * Reload the encrypted provider from storage and confirm the token decrypts identically
     * before removing any legacy plaintext credential.
     */
    private function assertVerifiedEncryptedCredentials(int $providerId, string $expectedToken): void
    {
        $persisted = NotificationProvider::findOrFail($providerId);
        $decrypted = $persisted->getDecryptedCredentials();

        if (($decrypted['bot_token'] ?? null) !== $expectedToken) {
            throw new \RuntimeException("Encrypted credential verification failed for provider #{$providerId}.");
        }
    }
};

