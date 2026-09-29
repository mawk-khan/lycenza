<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.1 (ADR 0062 §7): fee structures, their lines and the
     * authoritative instalment schedule.
     *
     * - `fee_structures`: AcademicYear x GradeLevel with an optional Campus
     *   override (decision B; there is deliberately no Section column).
     *   Lifecycle `draft -> active -> retired` only
     *   (`fee_structures_lifecycle_trigger`). A non-draft structure is
     *   immutable apart from its retirement. One active structure per
     *   (School, year, grade, campus-or-default) is a partial unique index,
     *   so two concurrent activations can never both win. Amendment is a
     *   successor draft (`supersedes_fee_structure_id`) of the same scope;
     *   at most one successor of a predecessor ever leaves draft.
     * - `fee_structure_lines`: one fee head per structure, yearly amount,
     *   descriptive frequency, optional flag.
     * - `fee_structure_installments`: the stored schedule (decision C).
     *   `billing_period_key` is the future FEE.2 idempotency period (ADR
     *   0062 §11). Periods lie inside the AcademicYear; an AcademicTerm, if
     *   named, belongs to the same year.
     *
     * Lines and instalments can be written only while their structure is
     * `draft` (`fees_reject_non_draft_structure_child_write`). That trigger
     * takes a FOR SHARE lock on the parent structure, so a child write
     * racing an activation either commits first (and is counted by the
     * activation's sum check) or waits and is then refused. Activation
     * itself requires at least one line and every line's instalments to sum
     * exactly to the line amount (`fees_validate_fee_structure_lifecycle`).
     *
     * Structures are never deleted (`TenantRls::revokeDelete`). Draft lines
     * and instalments can be removed while the parent is a draft; the
     * trigger refuses it afterwards.
     */
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->uuid('grade_level_id');
            $table->uuid('campus_id')->nullable();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('status')->default('draft'); // draft|active|retired
            $table->uuid('supersedes_fee_structure_id')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignUuid('activated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->foreignUuid('retired_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'academic_year_id', 'grade_level_id']);
            $table->index(['school_id', 'status']);
            $table->index('supersedes_fee_structure_id');

            $table->foreign(['academic_year_id', 'school_id'], 'fee_structures_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['grade_level_id', 'school_id'], 'fee_structures_grade_level_fk')
                ->references(['id', 'school_id'])->on('grade_levels')->restrictOnDelete();
            $table->foreign(['campus_id', 'school_id'], 'fee_structures_campus_fk')
                ->references(['id', 'school_id'])->on('campuses')->restrictOnDelete();
            // NO ACTION (checked at statement end), so a School's cascade
            // delete can remove a predecessor and its successor together.
            $table->foreign(['supersedes_fee_structure_id', 'school_id'], 'fee_structures_supersedes_fk')
                ->references(['id', 'school_id'])->on('fee_structures');
        });

        DB::statement("ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_status_check CHECK (status IN ('draft', 'active', 'retired'))");
        DB::statement("ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");
        DB::statement('ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_no_self_supersede_check CHECK (supersedes_fee_structure_id IS NULL OR supersedes_fee_structure_id <> id)');
        DB::statement(
            'ALTER TABLE fee_structures ADD CONSTRAINT fee_structures_provenance_shape_check CHECK ('.
            "(status = 'draft' AND activated_at IS NULL AND activated_by_user_id IS NULL AND retired_at IS NULL AND retired_by_user_id IS NULL) OR ".
            "(status = 'active' AND activated_at IS NOT NULL AND retired_at IS NULL AND retired_by_user_id IS NULL) OR ".
            "(status = 'retired' AND activated_at IS NOT NULL AND retired_at IS NOT NULL))"
        );
        DB::statement('CREATE UNIQUE INDEX fee_structures_year_code_ci_unique ON fee_structures (school_id, academic_year_id, upper(code))');
        DB::statement(
            'CREATE UNIQUE INDEX fee_structures_one_active_per_scope ON fee_structures '.
            "(school_id, academic_year_id, grade_level_id, COALESCE(campus_id, '00000000-0000-0000-0000-000000000000'::uuid)) ".
            "WHERE status = 'active'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX fee_structures_one_live_successor ON fee_structures (supersedes_fee_structure_id) '.
            "WHERE supersedes_fee_structure_id IS NOT NULL AND status <> 'draft'"
        );

        Schema::create('fee_structure_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_structure_id');
            $table->uuid('fee_head_id');
            $table->boolean('is_optional')->default(false);
            $table->string('frequency')->default('custom'); // one_time|term|monthly|custom -- descriptive only
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['fee_structure_id', 'fee_head_id']);
            $table->index('fee_head_id');

            $table->foreign(['fee_structure_id', 'school_id'], 'fee_structure_lines_structure_fk')
                ->references(['id', 'school_id'])->on('fee_structures')->cascadeOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_structure_lines_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fee_structure_lines ADD CONSTRAINT fee_structure_lines_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE fee_structure_lines ADD CONSTRAINT fee_structure_lines_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement("ALTER TABLE fee_structure_lines ADD CONSTRAINT fee_structure_lines_frequency_check CHECK (frequency IN ('one_time', 'term', 'monthly', 'custom'))");

        Schema::create('fee_structure_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_structure_line_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('label', 64);
            $table->string('billing_period_key', 32);
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('due_date');
            $table->uuid('academic_term_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['fee_structure_line_id', 'sequence']);
            $table->unique(['fee_structure_line_id', 'billing_period_key']);
            $table->index('academic_term_id');

            $table->foreign(['fee_structure_line_id', 'school_id'], 'fee_structure_installments_line_fk')
                ->references(['id', 'school_id'])->on('fee_structure_lines')->cascadeOnDelete();
            $table->foreign(['academic_term_id', 'school_id'], 'fee_structure_installments_term_fk')
                ->references(['id', 'school_id'])->on('academic_terms')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_sequence_positive_check CHECK (sequence > 0)');
        DB::statement('ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_period_order_check CHECK (period_starts_on <= period_ends_on)');
        DB::statement('ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_due_date_check CHECK (due_date >= period_starts_on)');
        DB::statement("ALTER TABLE fee_structure_installments ADD CONSTRAINT fee_structure_installments_period_key_format_check CHECK (billing_period_key ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");

        // Structure lifecycle, immutability, supersession scope and the
        // activation completeness/sum guard.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_structure_lifecycle() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                predecessor RECORD;
                mismatched_line uuid;
            BEGIN
                IF NEW.supersedes_fee_structure_id IS NOT NULL
                   AND (TG_OP = 'INSERT' OR NEW.supersedes_fee_structure_id IS DISTINCT FROM OLD.supersedes_fee_structure_id) THEN
                    SELECT academic_year_id, grade_level_id, campus_id INTO predecessor
                    FROM fee_structures
                    WHERE id = NEW.supersedes_fee_structure_id AND school_id = NEW.school_id;

                    IF NOT FOUND
                       OR predecessor.academic_year_id IS DISTINCT FROM NEW.academic_year_id
                       OR predecessor.grade_level_id IS DISTINCT FROM NEW.grade_level_id
                       OR predecessor.campus_id IS DISTINCT FROM NEW.campus_id THEN
                        RAISE EXCEPTION 'fee structure % may only supersede a structure of the same year, grade and campus', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'a fee structure is always created as a draft'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NOT (
                    (OLD.status = NEW.status)
                    OR (OLD.status = 'draft' AND NEW.status = 'active')
                    OR (OLD.status = 'active' AND NEW.status = 'retired')
                ) THEN
                    RAISE EXCEPTION 'illegal fee structure transition % -> %', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'draft' AND (
                    NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.code IS DISTINCT FROM OLD.code
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.supersedes_fee_structure_id IS DISTINCT FROM OLD.supersedes_fee_structure_id
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                    OR NEW.activated_by_user_id IS DISTINCT FROM OLD.activated_by_user_id
                    OR (OLD.status = 'retired' AND (
                        NEW.retired_at IS DISTINCT FROM OLD.retired_at
                        OR NEW.retired_by_user_id IS DISTINCT FROM OLD.retired_by_user_id))
                ) THEN
                    RAISE EXCEPTION 'fee structure % is % and immutable', OLD.id, OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'active' THEN
                    IF NOT EXISTS (SELECT 1 FROM fee_structure_lines WHERE fee_structure_id = NEW.id) THEN
                        RAISE EXCEPTION 'fee structure % has no lines and cannot be activated', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT l.id INTO mismatched_line
                    FROM fee_structure_lines l
                    LEFT JOIN fee_structure_installments i ON i.fee_structure_line_id = l.id
                    WHERE l.fee_structure_id = NEW.id
                    GROUP BY l.id, l.amount
                    HAVING COUNT(i.id) = 0 OR COALESCE(SUM(i.amount), 0) <> l.amount
                    LIMIT 1;

                    IF mismatched_line IS NOT NULL THEN
                        RAISE EXCEPTION 'fee structure % line % instalments do not sum to the line amount', NEW.id, mismatched_line
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_structures_lifecycle_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_structures '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_structure_lifecycle()'
        );

        // Draft-only children, parent-scope integrity, period bounds.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_reject_non_draft_structure_child_write() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                target_structure uuid;
                previous_structure uuid;
                structure_status text;
                year_starts date;
                year_ends date;
                structure_year uuid;
                row_data RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    -- A referential cascade (a School being deleted, or a
                    -- draft line removing its own instalments) runs at
                    -- trigger depth > 1; the direct write it came from was
                    -- already checked (or is the School's own deletion).
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;

                    row_data := OLD;
                ELSE
                    row_data := NEW;
                END IF;

                IF TG_TABLE_NAME = 'fee_structure_lines' THEN
                    target_structure := row_data.fee_structure_id;

                    IF TG_OP = 'UPDATE' AND NEW.fee_structure_id IS DISTINCT FROM OLD.fee_structure_id THEN
                        RAISE EXCEPTION 'a fee structure line cannot move to another structure'
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSE
                    IF TG_OP = 'UPDATE' AND NEW.fee_structure_line_id IS DISTINCT FROM OLD.fee_structure_line_id THEN
                        RAISE EXCEPTION 'an instalment cannot move to another line'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT fee_structure_id INTO target_structure
                    FROM fee_structure_lines WHERE id = row_data.fee_structure_line_id;

                    IF target_structure IS NULL THEN
                        -- The parent line is being cascade-deleted with its
                        -- structure's draft line; nothing further to check.
                        IF TG_OP = 'DELETE' THEN
                            RETURN OLD;
                        END IF;

                        RAISE EXCEPTION 'instalment line % does not exist', row_data.fee_structure_line_id
                            USING ERRCODE = 'foreign_key_violation';
                    END IF;
                END IF;

                SELECT s.status, s.academic_year_id, y.starts_on, y.ends_on
                INTO structure_status, structure_year, year_starts, year_ends
                FROM fee_structures s
                JOIN academic_years y ON y.id = s.academic_year_id
                WHERE s.id = target_structure
                FOR SHARE OF s;

                IF structure_status IS NULL THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'fee structure % does not exist', target_structure
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF structure_status <> 'draft' THEN
                    RAISE EXCEPTION 'fee structure % is % and its lines/instalments are immutable', target_structure, structure_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_TABLE_NAME = 'fee_structure_installments' AND TG_OP <> 'DELETE' THEN
                    IF NEW.period_starts_on < year_starts OR NEW.period_ends_on > year_ends THEN
                        RAISE EXCEPTION 'instalment period must lie inside the academic year (% to %)', year_starts, year_ends
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.academic_term_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM academic_terms
                        WHERE id = NEW.academic_term_id AND academic_year_id = structure_year
                    ) THEN
                        RAISE EXCEPTION 'instalment term % does not belong to the structure academic year', NEW.academic_term_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_structure_lines_draft_only_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON fee_structure_lines '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_non_draft_structure_child_write()'
        );
        DB::statement(
            'CREATE TRIGGER fee_structure_installments_draft_only_trigger '.
            'BEFORE INSERT OR UPDATE OR DELETE ON fee_structure_installments '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_non_draft_structure_child_write()'
        );

        TenantRls::enable('fee_structures');
        TenantRls::revokeDelete('fee_structures');
        TenantRls::enable('fee_structure_lines');
        TenantRls::enable('fee_structure_installments');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_structure_installments_draft_only_trigger ON fee_structure_installments');
        DB::statement('DROP TRIGGER IF EXISTS fee_structure_lines_draft_only_trigger ON fee_structure_lines');
        DB::statement('DROP FUNCTION IF EXISTS fees_reject_non_draft_structure_child_write()');
        DB::statement('DROP TRIGGER IF EXISTS fee_structures_lifecycle_trigger ON fee_structures');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_structure_lifecycle()');

        TenantRls::disable('fee_structure_installments');
        Schema::dropIfExists('fee_structure_installments');
        TenantRls::disable('fee_structure_lines');
        Schema::dropIfExists('fee_structure_lines');
        TenantRls::disable('fee_structures');
        Schema::dropIfExists('fee_structures');
    }
};
