<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1 §2.7: APPEND-ONLY at the database privilege level
     * (TenantRls::makeAppendOnly, ADR 0021/0017) -- an attempt record is
     * a fact about what actually happened and is never edited after the
     * fact, only inserted. `unique(communication_delivery_id,
     * attempt_number)` is the authoritative-numbering guarantee (root
     * CLAUDE.md rule 40), derived from the delivery's own atomic
     * processing-lease claim in App\Jobs\ProcessCommunicationDeliveryJob,
     * never a bare `count() + 1`.
     *
     * `outcome` mirrors webhook_delivery_attempts' classification
     * exactly (success | transient_failure | permanent_failure), even
     * though the only driver registered in this checkpoint (`in_app`)
     * can only ever produce `success` -- this is what lets a future real
     * provider channel reuse the same job/table without a schema change.
     * Never persists a raw exception message or stack trace (only a
     * short diagnostic label), matching webhook_delivery_attempts'
     * `error_class` convention.
     */
    public function up(): void
    {
        Schema::create('communication_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('communication_delivery_id');
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome');
            $table->string('provider_reference')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['communication_delivery_id', 'attempt_number']);
            $table->index('school_id');
            $table->index('communication_delivery_id');

            $table->foreign(['communication_delivery_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_deliveries')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_delivery_attempts ADD CONSTRAINT communication_delivery_attempts_outcome_check '.
            "CHECK (outcome IN ('success', 'transient_failure', 'permanent_failure'))"
        );

        TenantRls::enable('communication_delivery_attempts');
        TenantRls::makeAppendOnly('communication_delivery_attempts');
    }

    public function down(): void
    {
        TenantRls::disable('communication_delivery_attempts');
        Schema::dropIfExists('communication_delivery_attempts');
    }
};
