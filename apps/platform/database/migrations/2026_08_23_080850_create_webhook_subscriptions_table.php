<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data (RLS-protected). Which event types are sent to
     * a given webhook_endpoint (Phase 0C.3 section 11) -- a distinct
     * concept and lifecycle from the endpoint itself (section 4).
     *
     * `unique(['webhook_endpoint_id', 'event_type'])` is the section 11
     * duplicate-prevention constraint, enforced by Postgres, not an
     * application pre-check.
     *
     * `foreign(['webhook_endpoint_id', 'school_id'])` (section 55): a
     * composite FK against webhook_endpoints(id, school_id) makes it a
     * foreign-key violation -- not just an application bug -- to insert
     * a subscription whose school_id doesn't match its own endpoint's
     * school_id (i.e. "School A subscription -> School B endpoint" is
     * structurally impossible), mirroring
     * membership_role_assignments' composite FK against
     * school_memberships(id, school_id).
     */
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('webhook_endpoint_id');
            $table->string('event_type');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'event_type']);
            $table->index(['school_id', 'event_type']);

            $table->foreign(['webhook_endpoint_id', 'school_id'])
                ->references(['id', 'school_id'])->on('webhook_endpoints')
                ->cascadeOnDelete();
        });

        TenantRls::enable('webhook_subscriptions');
    }

    public function down(): void
    {
        TenantRls::disable('webhook_subscriptions');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
