<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPF.1 (ADR 0067 §14, D7, D8): Transport's two OPF tables. Transport owns
 * them; FEE never reads them (§4).
 *
 * `transport_route_fee_heads` -- the route (pricing tier) -> fee head
 * mapping. Configuration only: NO amount. FEE's structure instalments stay
 * the one source of every Transport fee amount. One mapping per route;
 * several routes may share a fee head (that is a tier). Both ends are
 * pinned to the same School by composite foreign keys. Classified Finance
 * configuration (tenant lifetime), like `canteen_billing_configurations`.
 *
 * `transport_fee_selections` -- the provenance of Transport-recorded fee
 * selection intent: one row per (Student Transport assignment, academic
 * year), naming the fee head the route mapped to and the FEE optional
 * selection it created or reused.
 * - **Unique per assignment x year** (`transport_fee_selections_one_per_year`):
 *   a retry, a re-run carry-forward or a concurrent attempt can never record
 *   duplicate intent.
 * - **Consistent by construction:** a trigger proves the selection belongs
 *   to the assignment's Student, the row's academic year and fee head.
 * - **Insert-only Finance evidence:** the runtime role has no UPDATE or
 *   DELETE (`makeAppendOnly`). Withdrawal is recorded on FEE's selection and
 *   in the audit trail, never by rewriting this row.
 * - **Retention (ADR 0067 §21):** Finance ledger evidence (D8, retained with
 *   the selection it explains). It carries the E21-RH.7 database-recorded
 *   anchor (its links tracked) and the retention delete guard. While it
 *   exists, its assignment is `dependency_blocked` (ReferencingRows), as the
 *   selection already keeps its Student.
 *
 * Rollback drops both tables. That removes Transport's OPF integration
 * entirely; it never restores a retention bypass (the RH.7 fences sit
 * before this migration and are unaffected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_route_fee_heads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('route_id');
            $table->uuid('fee_head_id');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'route_id'], 'transport_route_fee_heads_one_per_route');
            $table->index(['school_id', 'fee_head_id']);

            $table->foreign(['route_id', 'school_id'], 'transport_route_fee_heads_route_fk')
                ->references(['id', 'school_id'])->on('transport_routes')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'transport_route_fee_heads_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });
        TenantRls::enable('transport_route_fee_heads');

        Schema::create('transport_fee_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('transport_student_assignment_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->uuid('fee_optional_selection_id');
            $table->string('link_reason'); // assignment|carry_forward
            $table->string('selection_outcome'); // created|reused
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'transport_student_assignment_id', 'academic_year_id'], 'transport_fee_selections_one_per_year');
            $table->index(['school_id', 'fee_optional_selection_id']);

            $table->foreign(['transport_student_assignment_id', 'school_id'], 'transport_fee_selections_assignment_fk')
                ->references(['id', 'school_id'])->on('transport_student_assignments')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'transport_fee_selections_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'transport_fee_selections_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['fee_optional_selection_id', 'school_id'], 'transport_fee_selections_selection_fk')
                ->references(['id', 'school_id'])->on('fee_optional_selections')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE transport_fee_selections ADD CONSTRAINT transport_fee_selections_reason_check CHECK (link_reason IN ('assignment', 'carry_forward'))");
        DB::statement("ALTER TABLE transport_fee_selections ADD CONSTRAINT transport_fee_selections_outcome_check CHECK (selection_outcome IN ('created', 'reused'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION transport_fee_selections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                      FROM public.fee_optional_selections s
                      JOIN public.transport_student_assignments a
                        ON a.id = NEW.transport_student_assignment_id AND a.school_id = NEW.school_id
                     WHERE s.id = NEW.fee_optional_selection_id
                       AND s.school_id = NEW.school_id
                       AND s.student_id = a.student_id
                       AND s.academic_year_id = NEW.academic_year_id
                       AND s.fee_head_id = NEW.fee_head_id
                ) THEN
                    RAISE EXCEPTION 'transport_fee_selections: the selection is not the assignment Student''s selection of this year and fee head'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION transport_fee_selections_guard() FROM PUBLIC;
            CREATE TRIGGER transport_fee_selections_guard_trigger
                BEFORE INSERT ON transport_fee_selections FOR EACH ROW EXECUTE FUNCTION transport_fee_selections_guard();

            -- E21-RH.7 (ADR 0066 §15): the database-recorded anchor (links tracked) and the retention delete guard.
            ALTER TABLE transport_fee_selections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON transport_fee_selections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('transport_student_assignment_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id');
            CREATE TRIGGER trg_retention_guard_transport_fee_selections AFTER DELETE ON transport_fee_selections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('transport_fee_selections');
        TenantRls::makeAppendOnly('transport_fee_selections');
    }

    public function down(): void
    {
        TenantRls::disable('transport_fee_selections');
        Schema::dropIfExists('transport_fee_selections');
        DB::unprepared('DROP FUNCTION IF EXISTS transport_fee_selections_guard()');
        TenantRls::disable('transport_route_fee_heads');
        Schema::dropIfExists('transport_route_fee_heads');
    }
};
