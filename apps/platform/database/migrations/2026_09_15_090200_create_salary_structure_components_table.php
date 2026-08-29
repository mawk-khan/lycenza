<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Salary structure revision model" /
     * "Compensation model") -- the calculation SHAPE for one
     * `salary_structures` revision. Exactly two calculation types,
     * both structure-level POLICY, never an Employee-specific value
     * (that lives in `compensation_assignment_values`, added next):
     * `fixed_amount` (an Employee-specific number is required
     * elsewhere -- this row carries no amount itself) and
     * `percentage_of_base` (`rate`, a decimal FRACTION 0-1, never a
     * raw "12" needing an implicit /100 -- e.g. 0.400000 for "HRA =
     * 40% of Basic", the same for every Employee on this revision).
     *
     * `base_component_id` is a same-structure self-reference,
     * required only for `percentage_of_base`, and must name an
     * EARLIER-ordered component (`display_order`) -- this is what
     * prevents calculation cycles BY CONSTRUCTION (enforced by the
     * trigger below, since a cross-row ordering comparison cannot be
     * expressed as a plain single-row CHECK constraint) rather than
     * needing cycle-detection code in the calculation kernel.
     *
     * Frozen the instant the parent `salary_structures` row leaves
     * `draft` (mirrors `salary_structures`' own freeze boundary) --
     * enforced by a trigger here rather than there, since this is the
     * table whose rows must actually stop changing.
     */
    public function up(): void
    {
        Schema::create('salary_structure_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('salary_structure_id');
            $table->uuid('salary_component_id');
            $table->string('calculation_type'); // fixed_amount|percentage_of_base
            $table->uuid('base_component_id')->nullable();
            $table->decimal('rate', 9, 6)->nullable();
            $table->unsignedInteger('display_order');
            $table->timestamps();

            $table->unique(['salary_structure_id', 'salary_component_id']);
            $table->unique(['id', 'salary_structure_id']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index('salary_structure_id');

            $table->foreign(['salary_structure_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_structures')
                ->cascadeOnDelete();
            $table->foreign(['salary_component_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_components')
                ->restrictOnDelete();
            $table->foreign(['base_component_id', 'salary_structure_id'])
                ->references(['id', 'salary_structure_id'])->on('salary_structure_components')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE salary_structure_components ADD CONSTRAINT salary_structure_components_calc_type_check CHECK (calculation_type IN ('fixed_amount', 'percentage_of_base'))");

        // A fixed_amount component carries neither base nor rate; a
        // percentage_of_base component requires both. No third shape
        // is representable.
        DB::statement(<<<'SQL'
            ALTER TABLE salary_structure_components ADD CONSTRAINT salary_structure_components_shape_check CHECK (
                (calculation_type = 'fixed_amount' AND base_component_id IS NULL AND rate IS NULL)
                OR
                (calculation_type = 'percentage_of_base' AND base_component_id IS NOT NULL AND rate IS NOT NULL)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION salary_structure_components_reject_after_draft() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                target_structure_id uuid;
            BEGIN
                target_structure_id := COALESCE(NEW.salary_structure_id, OLD.salary_structure_id);

                SELECT status INTO parent_status FROM salary_structures WHERE id = target_structure_id;

                IF parent_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'salary_structure_components: parent salary_structures row (%) is no longer draft -- its components are frozen.', target_structure_id;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_salary_structure_components_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON salary_structure_components
                FOR EACH ROW
                EXECUTE FUNCTION salary_structure_components_reject_after_draft();
        SQL);

        // Cycle prevention by construction: a percentage_of_base
        // component's base must already exist with a strictly smaller
        // display_order in the same structure.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION salary_structure_components_validate_base_order() RETURNS trigger AS $$
            DECLARE
                base_order integer;
            BEGIN
                IF NEW.base_component_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT display_order INTO base_order
                FROM salary_structure_components
                WHERE id = NEW.base_component_id AND salary_structure_id = NEW.salary_structure_id;

                IF base_order IS NULL OR base_order >= NEW.display_order THEN
                    RAISE EXCEPTION 'salary_structure_components: base_component_id (%) must reference an earlier-ordered component in the same structure.', NEW.base_component_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_salary_structure_components_base_order
                BEFORE INSERT OR UPDATE ON salary_structure_components
                FOR EACH ROW
                EXECUTE FUNCTION salary_structure_components_validate_base_order();
        SQL);

        TenantRls::enable('salary_structure_components');
    }

    public function down(): void
    {
        TenantRls::disable('salary_structure_components');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_salary_structure_components_base_order ON salary_structure_components');
        DB::unprepared('DROP FUNCTION IF EXISTS salary_structure_components_validate_base_order()');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_salary_structure_components_freeze ON salary_structure_components');
        DB::unprepared('DROP FUNCTION IF EXISTS salary_structure_components_reject_after_draft()');
        Schema::dropIfExists('salary_structure_components');
    }
};
