<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0C.2 (API idempotency foundation). Tenant-owned data
     * (RLS-protected) -- an idempotency key is created and consumed
     * entirely within one already-authenticated, already-School-scoped
     * request, and there is no cross-School background process that
     * needs to see every School's keys at once (unlike the outbox,
     * which a central dispatcher must scan across every School). RLS
     * makes "School A cannot collide with or read School B's key"
     * structurally impossible, not just unlikely via a compound unique
     * index -- see docs/architecture/RELIABILITY.md.
     *
     * Uniqueness scope is School + actor + route/action + key
     * (`api_idempotency_keys_scope_unique`), not key alone -- the same
     * client-generated key from two different actors, or reused
     * against two different endpoints, must never collide. This
     * constraint is the sole source of the concurrency guarantee
     * (section 30/38): two simultaneous identical requests race to
     * INSERT the same tuple, Postgres allows exactly one to succeed,
     * and the loser detects the conflict via
     * UniqueConstraintViolationException -- never an application-level
     * existence check followed by a separate insert.
     */
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            // Not a single FK -- an idempotency claim can belong to a
            // User OR a ServiceIdentity (Phase 0C section 25-27), and
            // the two id spaces are not guaranteed disjoint.
            $table->string('actor_type');
            $table->uuid('actor_id');

            // Canonical route/action identity (the route NAME, not the
            // raw path -- stable across route-parameter values, unlike
            // a URL). See App\Support\Idempotency\RequestFingerprint.
            $table->string('route_action');

            $table->string('idempotency_key');
            $table->char('request_fingerprint', 64); // sha256 hex digest
            $table->string('request_method', 10);

            $table->string('status')->default('processing');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->jsonb('response_body')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['school_id', 'actor_type', 'actor_id', 'route_action', 'idempotency_key'],
                'api_idempotency_keys_scope_unique',
            );
            $table->index('expires_at');
            $table->index(['school_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_status_check '.
            "CHECK (status IN ('processing', 'completed', 'failed', 'expired'))"
        );
        DB::statement(
            'ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_actor_type_check '.
            "CHECK (actor_type IN ('user', 'service_identity'))"
        );

        TenantRls::enable('api_idempotency_keys');
    }

    public function down(): void
    {
        TenantRls::disable('api_idempotency_keys');
        Schema::dropIfExists('api_idempotency_keys');
    }
};
