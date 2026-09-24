<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0L.6 (ADR 0043 §7): one row per (School, rule instance,
     * trigger occurrence) -- the idempotency unit. The UNIQUE constraint is
     * the only duplicate guard (CLAUDE.md rule 30); `trigger_key` is the
     * outbox event id for event triggers. Status is claimed with a
     * conditional UPDATE and a processing lease (rule 48); retries are
     * domain-level and bounded (rule 59). Identifiers and codes only: no
     * event payload, no error text.
     */
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('rule_instance_id');
            $table->string('trigger_key', 100);
            $table->string('trigger_event_type', 150);
            $table->string('subject_type', 50);
            $table->uuid('subject_id');
            $table->uuid('correlation_id')->nullable();
            $table->string('status', 20); // pending|running|succeeded|skipped|failed|abandoned
            $table->string('outcome_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'rule_instance_id', 'trigger_key'], 'automation_executions_trigger_unique');
            $table->unique(['id', 'school_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['rule_instance_id', 'created_at']);

            $table->foreign(['rule_instance_id', 'school_id'], 'automation_executions_rule_instance_fk')
                ->references(['id', 'school_id'])->on('automation_rule_instances');
        });

        DB::statement(
            'ALTER TABLE automation_executions ADD CONSTRAINT automation_executions_status_check '.
            "CHECK (status IN ('pending', 'running', 'succeeded', 'skipped', 'failed', 'abandoned'))"
        );

        TenantRls::enable('automation_executions');
    }

    public function down(): void
    {
        TenantRls::disable('automation_executions');
        Schema::dropIfExists('automation_executions');
    }
};
