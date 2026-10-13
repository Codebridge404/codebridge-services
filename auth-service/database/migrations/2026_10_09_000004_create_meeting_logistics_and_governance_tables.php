<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Meeting Materials (Equipment & Catering Inventory)
        if (!Schema::hasTable('meeting_materials')) {
            Schema::create('meeting_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('category', 50)->default('audio_visual'); // audio_visual, stationery, catering, furniture
                $table->integer('quantity_total')->default(1);
                $table->integer('quantity_available')->default(1);
                $table->string('status', 30)->default('active'); // active, maintenance
                $table->timestamps();
            });
        }

        // 2. Meeting Material Allocations
        if (!Schema::hasTable('meeting_material_allocations')) {
            Schema::create('meeting_material_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->index();
                $table->unsignedBigInteger('material_id')->index();
                $table->integer('quantity_allocated')->default(1);
                $table->timestamps();
            });
        }

        // 3. Meeting Staff (Certified Support Personnel)
        if (!Schema::hasTable('meeting_staff')) {
            Schema::create('meeting_staff', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('role', 50)->default('technician'); // technician, facilitator, receptionist
                $table->string('phone', 50)->nullable();
                $table->string('status', 30)->default('active'); // active, busy, inactive
                $table->timestamps();
            });
        }

        // 4. Meeting Staff Allocations
        if (!Schema::hasTable('meeting_staff_allocations')) {
            Schema::create('meeting_staff_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->index();
                $table->unsignedBigInteger('staff_id')->index();
                $table->string('assigned_role', 50)->nullable();
                $table->timestamps();
            });
        }

        // 5. Facility Maintenance & Room Defect Issues
        if (!Schema::hasTable('meeting_room_issues')) {
            Schema::create('meeting_room_issues', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('room_id')->index();
                $table->unsignedBigInteger('reported_by')->nullable()->index();
                $table->string('category', 50)->default('audio_visual'); // audio_visual, air_conditioning, cleanliness, network, furniture
                $table->string('priority', 30)->default('medium'); // low, medium, high, critical
                $table->string('status', 30)->default('reported'); // reported, under_inspection, resolved, cancelled
                $table->text('description');
                $table->text('resolution_notes')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        // 6. Dedicated Meeting Action Items
        if (!Schema::hasTable('meeting_action_items')) {
            Schema::create('meeting_action_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->date('due_date')->nullable();
                $table->string('status', 30)->default('pending'); // pending, in_progress, completed, cancelled
                $table->timestamps();
            });
        }

        // 7. Dynamic Runtime System Settings
        if (!Schema::hasTable('meeting_system_settings')) {
            Schema::create('meeting_system_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('setting_key', 100)->index();
                $table->text('setting_value')->nullable();
                $table->string('category', 50)->default('booking_policy'); // role_permissions, booking_policy, scheduling_defaults, notifications
                $table->string('data_type', 30)->default('string'); // string, number, boolean
                $table->string('display_name', 150);
                $table->string('description', 500)->nullable();
                $table->boolean('is_public')->default(true);
                $table->timestamps();
            });
        }

        // 8. Meeting File Attachments
        if (!Schema::hasTable('meeting_attachments')) {
            Schema::create('meeting_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('file_name', 255);
                $table->string('file_type', 100)->nullable();
                $table->longText('file_url')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable()->index();
                $table->timestamps();
            });
        }

        // 9. Meeting Notifications (In-app alert drawer)
        if (!Schema::hasTable('meeting_notifications')) {
            Schema::create('meeting_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('meeting_id')->nullable()->index();
                $table->string('type', 50)->default('invitation'); // invitation, reminder, approval_required, cancelled, defect_reported
                $table->string('title', 255);
                $table->text('message')->nullable();
                $table->string('status', 30)->default('unread'); // unread, read
                $table->timestamps();
            });
        }

        // 10. Alter Meetings Table: Add approvals, early end, recurring, and cancellation reason
        if (Schema::hasTable('meetings')) {
            Schema::table('meetings', function (Blueprint $table) {
                if (!Schema::hasColumn('meetings', 'requires_approval')) {
                    $table->boolean('requires_approval')->default(false)->after('meeting_type');
                }
                if (!Schema::hasColumn('meetings', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable()->after('requires_approval');
                }
                if (!Schema::hasColumn('meetings', 'approved_at')) {
                    $table->dateTime('approved_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('meetings', 'recurring_series_id')) {
                    $table->string('recurring_series_id', 100)->nullable()->after('approved_at');
                }
                if (!Schema::hasColumn('meetings', 'recurrence_type')) {
                    $table->string('recurrence_type', 30)->default('none')->after('recurring_series_id');
                }
                if (!Schema::hasColumn('meetings', 'ended_early_at')) {
                    $table->dateTime('ended_early_at')->nullable()->after('recurrence_type');
                }
                if (!Schema::hasColumn('meetings', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('ended_early_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_notifications');
        Schema::dropIfExists('meeting_attachments');
        Schema::dropIfExists('meeting_system_settings');
        Schema::dropIfExists('meeting_action_items');
        Schema::dropIfExists('meeting_room_issues');
        Schema::dropIfExists('meeting_staff_allocations');
        Schema::dropIfExists('meeting_staff');
        Schema::dropIfExists('meeting_material_allocations');
        Schema::dropIfExists('meeting_materials');

        if (Schema::hasTable('meetings')) {
            Schema::table('meetings', function (Blueprint $table) {
                $columns = ['requires_approval', 'approved_by', 'approved_at', 'recurring_series_id', 'recurrence_type', 'ended_early_at', 'cancellation_reason'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('meetings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
