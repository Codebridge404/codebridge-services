<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Schema: security_eventsNEW · AUTH SECURITY
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique('security_events_uuid_unique');
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->onDelete('restrict');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('restrict');
            $table->string('request_id', 64)->index('security_events_request_id_index');
            $table->char('trace_id', 32)->nullable()->index('security_events_trace_id_index');
            $table->string('service_name', 64)->default('auth-service');
            $table->string('environment', 20)->default('production');
            $table->string('event_type', 64)->index('security_events_event_type_index');
            $table->string('severity', 20)->index('security_events_severity_index');
            $table->string('confidence', 20)->default('observed');
            $table->string('outcome', 20)->default('blocked');
            $table->char('session_ref_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable()->index('security_events_ip_address_index');
            $table->string('user_agent', 512)->nullable();
            $table->string('method', 10)->default('GET');
            $table->string('route_template', 255)->default('');
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->string('rule_code', 64);
            $table->string('summary', 255);
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at', 6)->index('security_events_occurred_at_index');
            $table->timestamp('created_at')->useCurrent();

            // Composite indexes for rapid forensic time-series filtering
            $table->index(['tenant_id', 'occurred_at'], 'security_events_tenant_occurred_index');
            $table->index(['ip_address', 'occurred_at'], 'security_events_ip_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'security_events_type_occurred_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};

