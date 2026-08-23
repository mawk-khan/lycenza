<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected), APPEND-ONLY at the database
     * privilege level (TenantRls::makeAppendOnly, ADR 0021) -- an
     * attempt record is a fact about what actually happened over the
     * wire and is never edited after the fact, only ever inserted
     * (Phase 0C.3 section 17: "Do NOT persist Authorization headers,
     * webhook secret, full sensitive response bodies").
     *
     * `unique(['webhook_delivery_id', 'attempt_number'])` is the
     * section 43 authoritative-attempt-numbering guarantee: two workers
     * racing the same delivery cannot both successfully claim the same
     * attempt_number, and App\Jobs\DeliverWebhookJob computes
     * attempt_number from the delivery's processing-lease claim (itself
     * atomic, see webhook_deliveries' migration) rather than a bare
     * `count() + 1` race.
     *
     * `outcome` is the closed classification from section 38-40:
     * success (2xx) | transient_failure (408/429/5xx, may retry) |
     * permanent_failure (3xx/4xx other than 408/429, never retried) |
     * timeout | network_error. `error_class` carries a short diagnostic
     * label (e.g. "404", "ssrf_rejected", "redirect_not_followed",
     * "read_timeout") -- never a raw exception message or stack trace.
     */
    public function up(): void
    {
        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('webhook_delivery_id');
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome');
            $table->string('error_class', 64)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_delivery_id', 'attempt_number']);
            $table->index('school_id');
            $table->index('webhook_delivery_id');

            $table->foreign(['webhook_delivery_id', 'school_id'])
                ->references(['id', 'school_id'])->on('webhook_deliveries')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE webhook_delivery_attempts ADD CONSTRAINT webhook_delivery_attempts_outcome_check '.
            "CHECK (outcome IN ('success', 'transient_failure', 'permanent_failure', 'timeout', 'network_error'))"
        );

        TenantRls::enable('webhook_delivery_attempts');
        TenantRls::makeAppendOnly('webhook_delivery_attempts');
    }

    public function down(): void
    {
        TenantRls::disable('webhook_delivery_attempts');
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
