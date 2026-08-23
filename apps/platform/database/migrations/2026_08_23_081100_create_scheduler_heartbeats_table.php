<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: one row per named scheduled task, updated
     * every successful run. Readiness/health checks compare `last_run_at`
     * against an expected interval to detect a stalled scheduler
     * (section 39) -- no incident paging, just the durable signal.
     */
    public function up(): void
    {
        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->string('name')->primary(); // e.g. "outbox-dispatch"
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
    }
};
