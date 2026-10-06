<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPF.3 (ADR 0067 §16, D1, D7): Admissions' two OPF tables for the one-time
 * Admission fee, which arises ONLY after an application converted into a
 * Student. Admissions owns them; FEE never reads them (§4). No applicant,
 * and no unconverted application, is ever a financial subject: both rows
 * name the converted Student, and `charges` is untouched.
 *
 * `admission_fee_heads` -- which FEE fee head is the School's Admission fee.
 * Configuration only: NO amount (FEE's structure instalments own it, and
 * FEE's structure already varies by academic year, grade and campus, so the
 * School is the only Admissions-side dimension). One per School
 * (`admission_fee_heads_one_per_school`). Classified Finance configuration
 * (tenant lifetime), like `transport_route_fee_heads`.
 *
 * `admission_fee_selections` -- the provenance of the Admission-fee intent a
 * conversion recorded: one row per application
 * (`admission_fee_selections_one_per_application`), with the converted
 * Student, the application's academic year, the fee head and the FEE
 * optional selection created or reused. A trigger proves the application
 * is converted into exactly that Student in that year, and the selection is
 * that Student's selection of that year and fee head. Insert-only Finance
 * evidence (runtime role: no UPDATE, no DELETE), with the E21-RH.7 anchor
 * (its links tracked) and the retention delete guard; it keeps its
 * application (and so its Student) `dependency_blocked`, as the selection
 * already keeps its Student.
 *
 * Rollback drops both tables; it restores no privilege and no retention
 * bypass (the RH.7 fences sit before it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_fee_heads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_head_id');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id'], 'admission_fee_heads_one_per_school');

            $table->foreign(['fee_head_id', 'school_id'], 'admission_fee_heads_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });
        TenantRls::enable('admission_fee_heads');

        Schema::create('admission_fee_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('admission_application_id');
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->uuid('fee_optional_selection_id');
            $table->string('selection_outcome'); // created|reused
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'admission_application_id'], 'admission_fee_selections_one_per_application');
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'fee_optional_selection_id']);

            $table->foreign(['admission_application_id', 'school_id'], 'admission_fee_selections_application_fk')
                ->references(['id', 'school_id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['student_id', 'school_id'], 'admission_fee_selections_student_fk')
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'admission_fee_selections_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'admission_fee_selections_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['fee_optional_selection_id', 'school_id'], 'admission_fee_selections_selection_fk')
                ->references(['id', 'school_id'])->on('fee_optional_selections')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE admission_fee_selections ADD CONSTRAINT admission_fee_selections_outcome_check CHECK (selection_outcome IN ('created', 'reused'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION admission_fee_selections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                      FROM public.admission_applications a
                     WHERE a.id = NEW.admission_application_id
                       AND a.school_id = NEW.school_id
                       AND a.status = 'converted'
                       AND a.converted_student_id = NEW.student_id
                       AND a.academic_year_id = NEW.academic_year_id
                ) THEN
                    RAISE EXCEPTION 'admission_fee_selections: the application is not converted into this Student in this academic year'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (
                    SELECT 1
                      FROM public.fee_optional_selections s
                     WHERE s.id = NEW.fee_optional_selection_id
                       AND s.school_id = NEW.school_id
                       AND s.student_id = NEW.student_id
                       AND s.academic_year_id = NEW.academic_year_id
                       AND s.fee_head_id = NEW.fee_head_id
                ) THEN
                    RAISE EXCEPTION 'admission_fee_selections: the selection is not the converted Student''s selection of this year and fee head'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION admission_fee_selections_guard() FROM PUBLIC;
            CREATE TRIGGER admission_fee_selections_guard_trigger
                BEFORE INSERT ON admission_fee_selections FOR EACH ROW EXECUTE FUNCTION admission_fee_selections_guard();

            -- E21-RH.7 (ADR 0066 §15): the database-recorded anchor (links tracked) and the retention delete guard.
            ALTER TABLE admission_fee_selections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON admission_fee_selections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('admission_application_id', 'student_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id');
            CREATE TRIGGER trg_retention_guard_admission_fee_selections AFTER DELETE ON admission_fee_selections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('admission_fee_selections');
        TenantRls::makeAppendOnly('admission_fee_selections');
    }

    public function down(): void
    {
        TenantRls::disable('admission_fee_selections');
        Schema::dropIfExists('admission_fee_selections');
        DB::unprepared('DROP FUNCTION IF EXISTS admission_fee_selections_guard()');
        TenantRls::disable('admission_fee_heads');
        Schema::dropIfExists('admission_fee_heads');
    }
};
