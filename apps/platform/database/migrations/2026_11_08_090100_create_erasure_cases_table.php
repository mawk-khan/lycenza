<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E21.2F (E21-D10, docs/security/E21-RETENTION-DETERMINATION.md): the
 * reviewed data-subject erasure CASE. It is the proof of a request's
 * lifecycle: scope, subject reference, request, decision, target date,
 * execution and a per-category outcome summary of codes and counts.
 * It never holds a copy of the subject's data.
 *
 * It is a platform compliance record, like `platform_audit_events`. An
 * operator decides and executes cases from the console, so the table has
 * no tenant RLS policy. A School-scoped case names its School (composite
 * checks below), and planning and execution run inside THAT School's
 * context only.
 *
 * The runtime role cannot DELETE a case. A closed case (denied or
 * completed) expires 7 calendar years after it closed (project-adopted,
 * pending ratification). That runs only through the narrow retention
 * function below (E21.2B pattern: fixed predicate, database age floor,
 * capped batch, dry run, EXECUTE for the runtime role only), called by
 * RetentionExpiry.
 *
 * Rollback drops the function and the table. Nothing outside it depends
 * on a case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erasure_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope');
            $table->uuid('school_id')->nullable();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('request_channel');
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason')->nullable();
            $table->date('target_on')->nullable();
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('outcome')->nullable();
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->index(['status', 'target_on']);
            $table->index(['school_id', 'subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE erasure_cases ADD CONSTRAINT erasure_cases_shape_check CHECK (
                scope IN ('school', 'platform')
            AND (scope = 'school') = (school_id IS NOT NULL)
            AND subject_type IN ('student', 'guardian', 'employee', 'user')
            AND (subject_type = 'user') = (scope = 'platform')
            AND request_channel IN ('written', 'email', 'in_person', 'other')
            AND status IN ('requested', 'approved', 'partially_approved', 'denied', 'executing', 'completed')
            AND (status = 'requested') = (decided_at IS NULL)
            AND (decision_reason IS NULL OR decision_reason IN (
                'request_valid', 'request_valid_with_retained_categories', 'identity_not_verified',
                'retention_obligation', 'legal_hold', 'not_applicable'))
            AND ((status IN ('approved', 'partially_approved', 'executing', 'completed')) = (target_on IS NOT NULL))
            AND ((status = 'completed') = (completed_at IS NOT NULL)))");
        DB::statement('REVOKE DELETE ON erasure_cases FROM school_os_app');

        DB::unprepared(<<<'SQL'
            -- E21-D10: a CLOSED erasure case (denied or completed), 7 years after it closed.
            CREATE FUNCTION retention_expire_erasure_cases(p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.erasure_cases
                     WHERE status IN ('denied', 'completed') AND COALESCE(completed_at, decided_at) < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.erasure_cases c
                 WHERE c.id IN (SELECT id FROM public.erasure_cases
                                 WHERE status IN ('denied', 'completed') AND COALESCE(completed_at, decided_at) < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND c.status IN ('denied', 'completed') AND COALESCE(c.completed_at, c.decided_at) < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION retention_expire_erasure_cases(timestamp, integer, boolean) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION retention_expire_erasure_cases(timestamp, integer, boolean) TO school_os_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS retention_expire_erasure_cases(timestamp, integer, boolean)');
        Schema::dropIfExists('erasure_cases');
    }
};
