<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0C.2's tiny infrastructure-only demonstration action
     * (section 18) -- proves the api_idempotency_keys claim/replay/
     * conflict/concurrency guarantees against a REAL state-changing
     * side effect, without touching any real ERP business module. One
     * counter row per School (school_id IS the primary key -- there is
     * exactly one of these per tenant, same pattern as
     * platform_test_records/scheduler_heartbeats-style singleton rows).
     * Tenant-owned/RLS-protected like every other School-scoped table.
     *
     * Not registered under production routes -- see routes/api.php's
     * `app()->environment(['local', 'testing'])` guard around the demo
     * route.
     */
    public function up(): void
    {
        Schema::create('platform_idempotency_demo_counters', function (Blueprint $table) {
            $table->foreignUuid('school_id')->primary()->constrained('schools')->cascadeOnDelete();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        TenantRls::enable('platform_idempotency_demo_counters');
    }

    public function down(): void
    {
        TenantRls::disable('platform_idempotency_demo_counters');
        Schema::dropIfExists('platform_idempotency_demo_counters');
    }
};
