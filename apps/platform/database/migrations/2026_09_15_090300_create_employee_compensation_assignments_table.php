<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Compensation effective-dating and overlap")
     * -- an effective-dated binding of an HR `EmploymentRecord` (never
     * bare `Employee` -- an Employee may have multiple historical
     * EmploymentRecords via rehire, each with independent compensation
     * history) to an exact `salary_structures` revision.
     *
     * Overlap policy deliberately does NOT use `EXCLUDE USING gist`/
     * `btree_gist`: `create_academic_years_table.php` and
     * `create_employment_records_table.php` both explicitly document
     * rejecting that mechanism for this exact shape of problem (a
     * low-write-rate, human-initiated, effective-dated record), noting
     * no such extension exists anywhere in this codebase. Following
     * that precedent, overlap is enforced by:
     *   (a) the Application layer (`CompensationService`, Checkpoint
     *       9.2): locks the EmploymentRecord row FOR UPDATE, checks,
     *       then writes, inside one transaction -- the same
     *       lock-then-check-then-write shape
     *       `App\Domain\HR\Application\EmploymentService::create()`
     *       already established;
     *   (b) a database trigger here that INDEPENDENTLY re-validates
     *       the same rule at write time, locking the same
     *       EmploymentRecord row itself (so a raw SQL write bypassing
     *       the Application service is still caught) -- this is
     *       Payroll's own addition, giving this specific invariant a
     *       real structural backstop the two precedent tables do not
     *       have, without introducing the rejected extension.
     * A raw SQL write racing another raw SQL write for the SAME
     * EmploymentRecord still serializes correctly because both lock
     * the same parent row before checking; two DIFFERENT
     * EmploymentRecords are never serialized against each other.
     *
     * A second trigger enforces "assignable only to an ACTIVE
     * structure revision" -- `salary_structures`' own freeze boundary
     * makes `draft`/`superseded` unsafe to assign against.
     */
    public function up(): void
    {
        Schema::create('employee_compensation_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('salary_structure_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['employment_record_id', 'effective_from']);

            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->restrictOnDelete();
            $table->foreign(['salary_structure_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_structures')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE employee_compensation_assignments ADD CONSTRAINT employee_compensation_assignments_date_range_check CHECK (effective_to IS NULL OR effective_from <= effective_to)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_assignments_reject_overlap() RETURNS trigger AS $$
            DECLARE
                conflict_id uuid;
            BEGIN
                -- Serializes concurrent writers targeting the SAME
                -- EmploymentRecord; a write for a DIFFERENT
                -- EmploymentRecord takes a different row lock and is
                -- never blocked by this one.
                PERFORM 1 FROM employment_records WHERE id = NEW.employment_record_id FOR UPDATE;

                SELECT id INTO conflict_id
                FROM employee_compensation_assignments
                WHERE employment_record_id = NEW.employment_record_id
                  AND id IS DISTINCT FROM NEW.id
                  AND NEW.effective_from < COALESCE(effective_to, 'infinity'::date)
                  AND effective_from < COALESCE(NEW.effective_to, 'infinity'::date)
                LIMIT 1;

                IF conflict_id IS NOT NULL THEN
                    RAISE EXCEPTION 'employee_compensation_assignments: effective range overlaps existing assignment % for employment_record %.', conflict_id, NEW.employment_record_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_compensation_assignments_reject_overlap
                BEFORE INSERT OR UPDATE ON employee_compensation_assignments
                FOR EACH ROW
                EXECUTE FUNCTION compensation_assignments_reject_overlap();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_assignments_require_active_structure() RETURNS trigger AS $$
            DECLARE
                structure_status text;
            BEGIN
                SELECT status INTO structure_status FROM salary_structures WHERE id = NEW.salary_structure_id;

                IF structure_status IS DISTINCT FROM 'active' THEN
                    RAISE EXCEPTION 'employee_compensation_assignments: salary_structure_id (%) must reference an active revision, got status %.', NEW.salary_structure_id, structure_status;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_compensation_assignments_require_active_structure
                BEFORE INSERT OR UPDATE ON employee_compensation_assignments
                FOR EACH ROW
                EXECUTE FUNCTION compensation_assignments_require_active_structure();
        SQL);

        TenantRls::enable('employee_compensation_assignments');
    }

    public function down(): void
    {
        TenantRls::disable('employee_compensation_assignments');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_compensation_assignments_require_active_structure ON employee_compensation_assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_assignments_require_active_structure()');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_compensation_assignments_reject_overlap ON employee_compensation_assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_assignments_reject_overlap()');
        Schema::dropIfExists('employee_compensation_assignments');
    }
};
