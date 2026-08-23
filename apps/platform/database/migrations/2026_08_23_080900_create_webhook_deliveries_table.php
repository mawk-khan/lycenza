<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected). One row per (webhook_endpoint,
     * event) LOGICAL delivery (section 15/20) -- claimed via the UNIQUE
     * constraint below BEFORE any HTTP attempt is made, which is what
     * makes delivery creation idempotent under consumer redelivery
     * (section 57): a duplicate consumer run tries to insert the same
     * (webhook_endpoint_id, event_id) pair, the unique constraint
     * rejects it, and no second logical delivery is ever created.
     *
     * A logical delivery may have MANY real HTTP attempts (section 17)
     * -- those live in their own webhook_delivery_attempts table, never
     * inline here. `status` is the closed set of states from section
     * 16, enforced by a CHECK constraint:
     *
     *   pending -> delivering -> delivered            (success)
     *                         \-> retrying -> delivering -> ...  (transient failure, bounded)
     *                         \-> failed                          (permanent 3xx/4xx)
     *                         \-> abandoned                       (retries exhausted / endpoint gone / SSRF)
     *
     * `processing_lease_expires_at` (section 45): while a worker holds
     * the delivery in `delivering`, this is set to now()+lease and only
     * a conditional UPDATE requiring the lease to be null/expired can
     * move it OUT of `delivering` again from another process -- see
     * App\Jobs\DeliverWebhookJob. This is what makes two workers racing
     * the same delivery job safe (section 44) without an external lock
     * service: Postgres's own row-level UPDATE semantics are the
     * authoritative guard, exactly like App\Support\Idempotency\
     * IdempotencyGuard's reclaim logic.
     *
     * `foreign(['webhook_endpoint_id', 'school_id'])` (section 55):
     * composite FK against webhook_endpoints(id, school_id) -- a
     * delivery's school_id is structurally forced to match its own
     * endpoint's school_id.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('webhook_endpoint_id');
            $table->uuid('event_id');
            $table->string('event_type');
            $table->string('status')->default('pending'); // pending|delivering|delivered|retrying|failed|abandoned
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->index('school_id');
            $table->index(['status', 'next_attempt_at']);

            $table->foreign(['webhook_endpoint_id', 'school_id'])
                ->references(['id', 'school_id'])->on('webhook_endpoints')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check '.
            "CHECK (status IN ('pending', 'delivering', 'delivered', 'retrying', 'failed', 'abandoned'))"
        );

        TenantRls::enable('webhook_deliveries');
    }

    public function down(): void
    {
        TenantRls::disable('webhook_deliveries');
        Schema::dropIfExists('webhook_deliveries');
    }
};
