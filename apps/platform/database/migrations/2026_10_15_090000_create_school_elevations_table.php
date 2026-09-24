<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.3 (ADR 0044 section 4): one row per platform elevation into a
 * School -- the grant being exercised, never a membership.
 *
 * Platform-owned and deliberately WITHOUT RLS: it is read before any
 * School context exists (the web resolver turns a session pointer into
 * an elevation) and no School-scoped route or School capability ever
 * reads it -- the same documented exception as school_memberships and
 * platform_role_assignments (docs/architecture/TENANCY.md).
 *
 * Database-enforced, not just application discipline:
 * - at most one ACTIVE elevation per actor (partial unique index);
 * - codes limited to the closed v1 catalogs, and the fixed 30-minute
 *   maximum lifetime (CHECK constraints);
 * - an active row may only be finished once (active -> ended | expired |
 *   terminated, with ended_at/end_reason written in that same update);
 *   a finished row is immutable and can never become active again, and
 *   actor, School, reason, start and expiry never change (trigger);
 * - the runtime role cannot DELETE a row (evidence), and deleting a
 *   School or User that has elevation history is restricted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_elevations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('reason_code');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('start_request_id')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['status', 'expires_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE school_elevations
                ADD CONSTRAINT school_elevations_reason_code_check
                    CHECK (reason_code IN ('operational_support', 'security_investigation', 'configuration_assistance', 'incident_response')),
                ADD CONSTRAINT school_elevations_status_check
                    CHECK (status IN ('active', 'ended', 'expired', 'terminated')),
                ADD CONSTRAINT school_elevations_lifetime_check
                    CHECK (expires_at > started_at AND expires_at <= started_at + interval '30 minutes'),
                ADD CONSTRAINT school_elevations_end_check
                    CHECK (
                        (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
                        OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
                        OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
                        OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ('actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked'))
                    )
            SQL);

        DB::statement("CREATE UNIQUE INDEX school_elevations_one_active_per_actor ON school_elevations (actor_user_id) WHERE status = 'active'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION school_elevations_guard_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'active' THEN
                    RAISE EXCEPTION 'school_elevations: a finished elevation is immutable';
                END IF;
                IF NEW.status = 'active' THEN
                    RAISE EXCEPTION 'school_elevations: an active elevation may only be finished';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.reason_code IS DISTINCT FROM OLD.reason_code
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.start_request_id IS DISTINCT FROM OLD.start_request_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'school_elevations: actor, School, reason, start and expiry are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER school_elevations_guard_update
                BEFORE UPDATE ON school_elevations
                FOR EACH ROW EXECUTE FUNCTION school_elevations_guard_update();
            SQL);

        TenantRls::revokeDelete('school_elevations');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS school_elevations_guard_update ON school_elevations;
            DROP FUNCTION IF EXISTS school_elevations_guard_update();
            SQL);

        Schema::dropIfExists('school_elevations');
    }
};
