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
        // 1. Migrate records from meeting_telegram_configs
        if (DB::getSchemaBuilder()->hasTable('meeting_telegram_configs') && DB::getSchemaBuilder()->hasTable('notification_providers')) {
            $configs = DB::table('meeting_telegram_configs')
                ->whereNotNull('bot_token')
                ->where('bot_token', '!=', '')
                ->get();

            foreach ($configs as $config) {
                $existing = NotificationProvider::where('channel', 'telegram')
                    ->where('tenant_id', $config->tenant_id)
                    ->first();

                if (!$existing) {
                    $provider = new NotificationProvider();
                    $provider->uuid = (string) Str::uuid();
                    $provider->tenant_id = $config->tenant_id;
                    $provider->name = 'Telegram Meeting Alerts';
                    $provider->channel = 'telegram';
                    $provider->provider = 'telegram';
                    $provider->environment = 'all';
                    $provider->is_enabled = (bool) $config->is_active;
                    $provider->credentials = [
                        'bot_token' => $config->bot_token,
                        'chat_id' => $config->chat_id,
                    ];
                    $provider->settings = [
                        'default_chat_id' => $config->chat_id,
                    ];
                    $provider->save();
                }

                // Remove plaintext copy
                DB::table('meeting_telegram_configs')
                    ->where('id', $config->id)
                    ->update(['bot_token' => '']);
            }
        }

        // 2. Migrate records from meeting_system_settings
        if (DB::getSchemaBuilder()->hasTable('meeting_system_settings') && DB::getSchemaBuilder()->hasTable('notification_providers')) {
            $tokenRows = DB::table('meeting_system_settings')
                ->where('setting_key', 'notification.telegram_bot_token')
                ->whereNotNull('setting_value')
                ->where('setting_value', '!=', '')
                ->get();

            foreach ($tokenRows as $tokenRow) {
                $tenantId = $tokenRow->tenant_id;
                $existing = NotificationProvider::where('channel', 'telegram')
                    ->where(function ($q) use ($tenantId) {
                        if ($tenantId !== null) {
                            $q->where('tenant_id', $tenantId);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })
                    ->first();

                $chatId = DB::table('meeting_system_settings')
                    ->where('setting_key', 'notification.telegram_default_chat_id')
                    ->where(function ($q) use ($tenantId) {
                        if ($tenantId !== null) {
                            $q->where('tenant_id', $tenantId);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })->value('setting_value') ?? '';

                $enabled = DB::table('meeting_system_settings')
                    ->where('setting_key', 'notification.telegram_enabled')
                    ->where(function ($q) use ($tenantId) {
                        if ($tenantId !== null) {
                            $q->where('tenant_id', $tenantId);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })->value('setting_value');

                if (!$existing) {
                    $provider = new NotificationProvider();
                    $provider->uuid = (string) Str::uuid();
                    $provider->tenant_id = $tenantId;
                    $provider->name = 'Telegram Meeting Alerts';
                    $provider->channel = 'telegram';
                    $provider->provider = 'telegram';
                    $provider->environment = 'all';
                    $provider->is_enabled = $enabled === 'true' || $enabled === '1';
                    $provider->credentials = [
                        'bot_token' => $tokenRow->setting_value,
                        'chat_id' => $chatId,
                    ];
                    $provider->settings = [
                        'default_chat_id' => $chatId,
                    ];
                    $provider->save();
                }

                // Delete raw plaintext token row from meeting_system_settings
                DB::table('meeting_system_settings')
                    ->where('id', $tokenRow->id)
                    ->delete();
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
};

