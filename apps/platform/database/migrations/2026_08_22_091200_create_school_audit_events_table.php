<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned ledger (ADR 0017): School-scoped security-relevant
     * events -- membership changes, role assignments, capability-
     * affecting changes, AI tool invocations scoped to this School.
     * RLS-protected like every other tenant-owned table, AND
     * append-only at the database privilege level (see
     * platform_audit_events for the identical rationale).
     */
    public function up(): void
    {
        Schema::create('school_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('request_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('school_id');
            $table->index(['school_id', 'event_type']);
            $table->index('occurred_at');
        });

        TenantRls::enable('school_audit_events');
        TenantRls::makeAppendOnly('school_audit_events');
    }

    public function down(): void
    {
        TenantRls::disable('school_audit_events');
        Schema::dropIfExists('school_audit_events');
    }
};
