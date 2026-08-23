<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data -- same rationale as domain_event_outbox:
     * consumer processing is a cross-School background concern. This is
     * the AUTHORITATIVE (database-unique-constraint-backed, not
     * Redis-only) deduplication record for "did consumer X already
     * process event Y" -- section 11. `school_id` is carried for
     * diagnostics/filtering, not isolation.
     */
    public function up(): void
    {
        Schema::create('event_consumer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('consumer_name');
            $table->uuid('event_id');
            $table->uuid('school_id')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['consumer_name', 'event_id']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_consumer_receipts');
    }
};
