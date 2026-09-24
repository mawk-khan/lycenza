<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0L.6 (ADR 0043 §7): one append-only row per attempt of an
     * execution. Numbering is made safe by UNIQUE(execution_id,
     * attempt_number), never `count() + 1` (CLAUDE.md rule 40). An attempt
     * interrupted by a crash is recorded as `interrupted` when the expired
     * lease is reclaimed. Stable codes only, never exception text.
     */
    public function up(): void
    {
        Schema::create('automation_execution_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('execution_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('outcome', 20); // succeeded|skipped|failed|interrupted
            $table->string('outcome_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['execution_id', 'attempt_number']);

            $table->foreign(['execution_id', 'school_id'], 'automation_execution_attempts_execution_fk')
                ->references(['id', 'school_id'])->on('automation_executions')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE automation_execution_attempts ADD CONSTRAINT automation_execution_attempts_outcome_check '.
            "CHECK (outcome IN ('succeeded', 'skipped', 'failed', 'interrupted'))"
        );

        TenantRls::enable('automation_execution_attempts');
        TenantRls::makeAppendOnly('automation_execution_attempts');
    }

    public function down(): void
    {
        TenantRls::disable('automation_execution_attempts');
        Schema::dropIfExists('automation_execution_attempts');
    }
};
