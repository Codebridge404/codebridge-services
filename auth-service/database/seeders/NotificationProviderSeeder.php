<?php

namespace Database\Seeders;

use App\Models\NotificationProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NotificationProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $botToken = trim((string) config('services.telegram_security.bot_token', ''));
        $chatId = trim((string) config('services.telegram_security.chat_id', ''));
        $botUsername = trim((string) config('services.telegram_security.bot_username', 'codebridge_alerts_bot'));

        if ($botToken === '' || $chatId === '') {
            $this->command?->info('Skipping NotificationProviderSeeder: TELEGRAM_SECURITY_BOT_TOKEN or TELEGRAM_SECURITY_CHAT_ID is not configured.');

            return;
        }

        $credentials = [
            'bot_token' => $botToken,
            'chat_id' => $chatId,
        ];

        $settings = [
            'bot_username' => $botUsername !== '' ? $botUsername : 'codebridge_alerts_bot',
            'default_chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'purpose' => 'security_alerts',
        ];

        $existing = NotificationProvider::where('channel', 'telegram')
            ->whereNull('tenant_id')
            ->where(function ($q) {
                $q->where('name', 'CodeBridge Security Alert Bot')
                    ->orWhere('settings->purpose', 'security_alerts')
                    ->orWhere(function ($q2) {
                        $q2->where('name', 'not like', 'Telegram Meeting Alerts%');
                    });
            })
            ->first();

        if (! $existing) {
            $provider = new NotificationProvider();
            $provider->uuid = (string) Str::uuid();
            $provider->tenant_id = null;
            $provider->name = 'CodeBridge Security Alert Bot';
            $provider->channel = 'telegram';
            $provider->provider = 'telegram';
            $provider->environment = 'all';
            $provider->is_enabled = true;
            $provider->credentials = $credentials;
            $provider->settings = $settings;
            $provider->save();
        } else {
            $existing->name = 'CodeBridge Security Alert Bot';
            $existing->is_enabled = true;
            $existing->credentials = $credentials;
            $existing->settings = $settings;
            $existing->save();
        }
    }
}

