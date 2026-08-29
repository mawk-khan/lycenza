<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Where employee-specific compensation values
     * live") -- the ONE place an individual Employee's negotiated
     * monetary number is stored. Only `fixed_amount`-type
     * `salary_structure_components` ever get a row here (enforced by
     * the trigger below) -- a `percentage_of_base` component is never
     * separately stored; it is always derived from its base
     * component's own resolved amount at calculation time. This is
     * what lets many Employees share one `salary_structures` revision
     * without forcing a unique structure per Employee.
     *
     * Immutable once written: a compensation change is a NEW
     * `employee_compensation_assignments` row (Checkpoint 9.2) with
     * its OWN new value rows, never an edit to an existing one --
     * mirrors every other "history is append-only, never edited"
     * invariant in this codebase. Enforced here by rejecting UPDATE
     * outright (a genuine correction is a new assignment, not a
     * mutated value).
     */
    public function up(): void
    {
        Schema::create('compensation_assignment_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('assignment_id');
            $table->uuid('salary_structure_component_id');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['assignment_id', 'salary_structure_component_id']);
            $table->index('school_id');

            $table->foreign(['assignment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_compensation_assignments')
                ->cascadeOnDelete();
            $table->foreign(['salary_structure_component_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_structure_components')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE compensation_assignment_values ADD CONSTRAINT compensation_assignment_values_amount_check CHECK (amount >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_assignment_values_require_fixed_component() RETURNS trigger AS $$
            DECLARE
                component_calc_type text;
            BEGIN
                SELECT calculation_type INTO component_calc_type
                FROM salary_structure_components
                WHERE id = NEW.salary_structure_component_id;

                IF component_calc_type IS DISTINCT FROM 'fixed_amount' THEN
                    RAISE EXCEPTION 'compensation_assignment_values: salary_structure_component_id (%) must be a fixed_amount component, got %.', NEW.salary_structure_component_id, component_calc_type;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_compensation_assignment_values_require_fixed_component
                BEFORE INSERT ON compensation_assignment_values
                FOR EACH ROW
                EXECUTE FUNCTION compensation_assignment_values_require_fixed_component();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_assignment_values_reject_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'compensation_assignment_values: rows are immutable once created -- a compensation change is a new assignment, never an edit (row %).', OLD.id;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_compensation_assignment_values_reject_update
                BEFORE UPDATE ON compensation_assignment_values
                FOR EACH ROW
                EXECUTE FUNCTION compensation_assignment_values_reject_update();
        SQL);

        TenantRls::enable('compensation_assignment_values');
        // Grant-level backstop in addition to the trigger above --
        // revokes UPDATE/DELETE for the runtime role entirely (a
        // parent's cascadeOnDelete still works: a FK-cascaded delete
        // is enforced by Postgres itself, not gated by the deleting
        // role's own DELETE privilege on the child table).
        TenantRls::makeAppendOnly('compensation_assignment_values');
    }

    public function down(): void
    {
        TenantRls::disable('compensation_assignment_values');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_compensation_assignment_values_reject_update ON compensation_assignment_values');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_assignment_values_reject_update()');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_compensation_assignment_values_require_fixed_component ON compensation_assignment_values');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_assignment_values_require_fixed_component()');
        Schema::dropIfExists('compensation_assignment_values');
    }
};
