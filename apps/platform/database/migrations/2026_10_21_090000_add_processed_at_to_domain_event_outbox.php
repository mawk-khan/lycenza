<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.4A (ADR 0050 section 10): a durable completion acknowledgement
 * for outbox events. Before this, `dispatched` meant only "a job was
 * queued" -- nothing recorded that every consumer had finished, so a job
 * lost with Redis left the row `dispatched` forever with no way to tell it
 * from a finished one. ProcessOutboxEventJob now sets `processed_at` once
 * every registered consumer holds a receipt; App\Support\Events\
 * OutboxReconciler re-dispatches (or acknowledges, from the receipts) rows
 * still unacknowledged after the full job retry lifecycle.
 *
 * Existing rows are not backfilled: the reconciler decides them from
 * PostgreSQL receipts, never by assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_event_outbox', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('dispatched_at');
        });

        DB::statement("CREATE INDEX domain_event_outbox_unacknowledged ON domain_event_outbox (dispatched_at) WHERE status = 'dispatched' AND processed_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS domain_event_outbox_unacknowledged');

        Schema::table('domain_event_outbox', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};
