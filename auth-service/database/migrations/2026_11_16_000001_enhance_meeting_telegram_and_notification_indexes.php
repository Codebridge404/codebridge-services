<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. meeting_system_settings: Add UNIQUE (tenant_id, setting_key) for key-value design
        if (Schema::hasTable('meeting_system_settings')) {
            $hasUqIndex = $this->hasIndexNamed('meeting_system_settings', 'uq_settings_tenant_key');
            if (! $hasUqIndex) {
                Schema::table('meeting_system_settings', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'setting_key'], 'uq_settings_tenant_key');
                });
            }
        }

        // 2. meeting_telegram_configs: Add UNIQUE (tenant_id)
        if (Schema::hasTable('meeting_telegram_configs')) {
            $hasUqTenant = $this->hasIndexNamed('meeting_telegram_configs', 'uq_telegram_configs_tenant');
            if (! $hasUqTenant) {
                Schema::table('meeting_telegram_configs', function (Blueprint $table) {
                    $table->unique(['tenant_id'], 'uq_telegram_configs_tenant');
                });
            }
        }

        // 3. meeting_notification_templates: Add UNIQUE (tenant_id, template_type, channel)
        if (Schema::hasTable('meeting_notification_templates')) {
            $hasUqTemplate = $this->hasIndexNamed('meeting_notification_templates', 'uq_templates_tenant_type_chan');
            if (! $hasUqTemplate) {
                Schema::table('meeting_notification_templates', function (Blueprint $table) {
                    $table->unique(['tenant_id', 'template_type', 'channel'], 'uq_templates_tenant_type_chan');
                });
            }
        }

        // 4. meeting_notifications: Add scheduling & lookup indexes
        if (Schema::hasTable('meeting_notifications')) {
            $hasTenantMeetingIdx = $this->hasIndexNamed('meeting_notifications', 'idx_notifications_tenant_meeting_time');
            if (! $hasTenantMeetingIdx) {
                Schema::table('meeting_notifications', function (Blueprint $table) {
                    $table->index(['tenant_id', 'meeting_id', 'created_at'], 'idx_notifications_tenant_meeting_time');
                });
            }

            $hasStatusIdx = $this->hasIndexNamed('meeting_notifications', 'idx_notifications_status_time');
            if (! $hasStatusIdx) {
                Schema::table('meeting_notifications', function (Blueprint $table) {
                    $table->index(['status', 'created_at'], 'idx_notifications_status_time');
                });
            }
        }

        // 5. meeting_telegram_groups (Proposed New)
        if (! Schema::hasTable('meeting_telegram_groups')) {
            Schema::create('meeting_telegram_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('notification_provider_id')->constrained('notification_providers')->cascadeOnDelete();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('telegram_config_id')->nullable()->constrained('meeting_telegram_configs')->nullOnDelete();
                $table->bigInteger('telegram_chat_id'); // Signed BIGINT preserves negative group/channel IDs
                $table->string('chat_title', 255);
                $table->string('chat_type', 30)->default('group');
                $table->string('bot_status', 30)->default('active');
                $table->string('link_status', 30)->default('pending');
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('linked_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['notification_provider_id', 'telegram_chat_id'], 'uq_provider_chat');
                $table->index(['tenant_id', 'link_status'], 'idx_groups_tenant_link');
            });
        }

        // 6. meeting_telegram_routes (Optional Routing New)
        if (! Schema::hasTable('meeting_telegram_routes')) {
            Schema::create('meeting_telegram_routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('telegram_config_id')->constrained('meeting_telegram_configs')->cascadeOnDelete();
                $table->foreignId('telegram_group_id')->constrained('meeting_telegram_groups')->cascadeOnDelete();
                $table->string('event_type', 100);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['telegram_config_id', 'telegram_group_id', 'event_type'], 'uq_routes_event');
                $table->index(['telegram_config_id', 'event_type', 'is_enabled'], 'idx_routes_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_telegram_routes');
        Schema::dropIfExists('meeting_telegram_groups');

        if (Schema::hasTable('meeting_notifications')) {
            Schema::table('meeting_notifications', function (Blueprint $table) {
                $table->dropIndex('idx_notifications_tenant_meeting_time');
                $table->dropIndex('idx_notifications_status_time');
            });
        }

        if (Schema::hasTable('meeting_notification_templates')) {
            Schema::table('meeting_notification_templates', function (Blueprint $table) {
                $table->dropUnique('uq_templates_tenant_type_chan');
            });
        }

        if (Schema::hasTable('meeting_telegram_configs')) {
            Schema::table('meeting_telegram_configs', function (Blueprint $table) {
                $table->dropUnique('uq_telegram_configs_tenant');
            });
        }

        if (Schema::hasTable('meeting_system_settings')) {
            Schema::table('meeting_system_settings', function (Blueprint $table) {
                $table->dropUnique('uq_settings_tenant_key');
            });
        }
    }

    /**
     * Check if a table has an index with a given name.
     */
    protected function hasIndexNamed(string $table, string $indexName): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
            return ! empty($indexes);
        } catch (\Throwable) {
            return false;
        }
    }
};
