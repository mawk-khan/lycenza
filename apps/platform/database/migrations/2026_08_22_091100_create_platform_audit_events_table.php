<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform ledger (ADR 0017): platform-level security
     * events not scoped to one School -- authentication success/
     * failure, platform role grants, School creation/suspension. See
     * school_audit_events for the tenant-owned counterpart.
     *
     * Append-only guarantee: TenantRls::makeAppendOnly() revokes
     * UPDATE/DELETE from the runtime app role at the database level --
     * not cryptographically immutable (no hash chaining/signing), but
     * a real privilege the application's own SQL connection cannot
     * exercise even via a bug or injection, only the separate
     * migration/admin role could. See ADR 0017 and
     * docs/architecture/adr/0022-tenant-context-propagation.md.
     */
    public function up(): void
    {
        Schema::create('platform_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_type');
            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
        });

        TenantRls::makeAppendOnly('platform_audit_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
    }
};
