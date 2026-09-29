<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.1 (ADR 0062 §8, decision E): an optional fee is assessed only for
     * a Student with an explicit, active selection.
     *
     * The selection's identity is Student x AcademicYear x fee head
     * (`fee_optional_selections_one_active`), not the line it was made
     * from: a successor structure (ADR 0062 §7.4) replaces the line ids, and
     * keying on the line would silently drop every Student's choice. The
     * line is kept as provenance and must be an OPTIONAL line of a structure
     * in the same AcademicYear for the same fee head
     * (`fees_validate_fee_optional_selection`).
     *
     * Lifecycle `active -> withdrawn` only; a withdrawn row is final and a
     * re-selection is a new row. Withdrawal affects future assessment only
     * and never touches an existing charge. Rows are never deleted
     * (`TenantRls::revokeDelete`). No other module's data (Transport,
     * Hostel, Library) creates a selection automatically.
     */
    public function up(): void
    {
        Schema::create('fee_optional_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->uuid('fee_structure_line_id');
            $table->string('status')->default('active'); // active|withdrawn
            $table->foreignUuid('selected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignUuid('withdrawn_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'fee_structure_line_id']);
            $table->index('fee_head_id');

            $table->foreign(['student_id', 'school_id'], 'fee_optional_selections_student_fk')
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'fee_optional_selections_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_optional_selections_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            // NO ACTION: a draft line may be removed only if no selection
            // references it; checked at statement end so a School's
            // cascade delete stays possible.
            $table->foreign(['fee_structure_line_id', 'school_id'], 'fee_optional_selections_line_fk')
                ->references(['id', 'school_id'])->on('fee_structure_lines');
        });

        DB::statement("ALTER TABLE fee_optional_selections ADD CONSTRAINT fee_optional_selections_status_check CHECK (status IN ('active', 'withdrawn'))");
        DB::statement(
            'ALTER TABLE fee_optional_selections ADD CONSTRAINT fee_optional_selections_withdrawal_shape_check CHECK ('.
            "(status = 'active' AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL) OR ".
            "(status = 'withdrawn' AND withdrawn_at IS NOT NULL))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX fee_optional_selections_one_active ON fee_optional_selections '.
            "(school_id, student_id, academic_year_id, fee_head_id) WHERE status = 'active'"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_optional_selection() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'active' THEN
                        RAISE EXCEPTION 'an optional fee selection is always created active'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM fee_structure_lines l
                        JOIN fee_structures s ON s.id = l.fee_structure_id
                        WHERE l.id = NEW.fee_structure_line_id
                          AND l.school_id = NEW.school_id
                          AND l.is_optional
                          AND l.fee_head_id = NEW.fee_head_id
                          AND s.academic_year_id = NEW.academic_year_id
                    ) THEN
                        RAISE EXCEPTION 'line % is not an optional line of fee head % in academic year %',
                            NEW.fee_structure_line_id, NEW.fee_head_id, NEW.academic_year_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.fee_structure_line_id IS DISTINCT FROM OLD.fee_structure_line_id
                    OR NEW.selected_by_user_id IS DISTINCT FROM OLD.selected_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'optional fee selection % identity is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'withdrawn' AND (
                    NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at
                    OR NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id
                ) THEN
                    RAISE EXCEPTION 'optional fee selection % is withdrawn and final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_optional_selections_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_optional_selections '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_optional_selection()'
        );

        TenantRls::enable('fee_optional_selections');
        TenantRls::revokeDelete('fee_optional_selections');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_optional_selections_guard_trigger ON fee_optional_selections');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_optional_selection()');
        TenantRls::disable('fee_optional_selections');
        Schema::dropIfExists('fee_optional_selections');
    }
};
