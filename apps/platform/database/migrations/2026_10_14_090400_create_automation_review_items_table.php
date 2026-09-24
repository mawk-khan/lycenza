<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0L.6 (ADR 0043 §3 tier 0): the informational output of one
     * execution -- at most one item per execution (UNIQUE execution_id), so
     * a retried or duplicated execution can never produce a second item.
     * References the source record by type and id only (no copy of its
     * data, no foreign key into another module's table: the id comes from
     * the School's own outbox event). Append-only: v1 has no acknowledge,
     * assign or resolve workflow.
     */
    public function up(): void
    {
        Schema::create('automation_review_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('rule_instance_id');
            $table->uuid('execution_id');
            $table->string('item_type', 64);
            $table->string('subject_type', 50);
            $table->uuid('subject_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('execution_id');
            $table->index(['school_id', 'created_at']);

            $table->foreign(['rule_instance_id', 'school_id'], 'automation_review_items_rule_instance_fk')
                ->references(['id', 'school_id'])->on('automation_rule_instances');
            $table->foreign(['execution_id', 'school_id'], 'automation_review_items_execution_fk')
                ->references(['id', 'school_id'])->on('automation_executions')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE automation_review_items ADD CONSTRAINT automation_review_items_item_type_check '.
            "CHECK (item_type IN ('academic_year_setup_review'))"
        );

        TenantRls::enable('automation_review_items');
        TenantRls::makeAppendOnly('automation_review_items');
    }

    public function down(): void
    {
        TenantRls::disable('automation_review_items');
        Schema::dropIfExists('automation_review_items');
    }
};
