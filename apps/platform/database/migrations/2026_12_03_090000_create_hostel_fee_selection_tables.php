<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPF.2 (ADR 0067 §15, D7, D8): Hostel's two OPF tables for the recurring
 * accommodation fee. Hostel owns them; FEE never reads them (§4). Deposits
 * stay deferred (D2): nothing here models a deposit, a liability or a refund.
 *
 * `hostel_fee_heads` -- the accommodation tier -> fee head mapping.
 * Configuration only: NO amount (FEE's structure instalments own it).
 * Hostel has no room-category concept, so the minimum durable tier is:
 * - a Hostel's default (`hostel_room_id` NULL; one per Hostel,
 *   `hostel_fee_heads_one_per_hostel`), and
 * - an optional per-room override (one per room,
 *   `hostel_fee_heads_one_per_room`), the room pinned to that Hostel by a
 *   composite foreign key (`hostel_rooms (id, hostel_id, school_id)`).
 * A residency's tier is its bed's room override, else its Hostel's default.
 * Rooms and beds cannot move between Hostels or rooms (no update path), so
 * a residency's tier inputs never change in place. Classified Finance
 * configuration (tenant lifetime), like `transport_route_fee_heads`.
 *
 * `hostel_fee_selections` -- the provenance of Hostel-recorded fee
 * selection intent: one row per (residency, academic year)
 * (`hostel_fee_selections_one_per_year`), with the fee head the tier
 * resolved to, which scope matched (`room` / `hostel`) and the FEE optional
 * selection created or reused. A trigger proves the selection is the
 * residency Student's selection of that year and fee head. Insert-only
 * Finance evidence (runtime role: no UPDATE, no DELETE), with the E21-RH.7
 * anchor (its links tracked) and the retention delete guard; it keeps its
 * residency `dependency_blocked`, as the selection keeps its Student.
 *
 * Rollback drops both tables and the added rooms key; it restores no
 * privilege and no retention bypass (the RH.7 fences sit before it).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A composite foreign-key target so a room override is provably a room of the mapped Hostel.
        DB::statement('ALTER TABLE hostel_rooms ADD CONSTRAINT hostel_rooms_id_hostel_school_unique UNIQUE (id, hostel_id, school_id)');

        Schema::create('hostel_fee_heads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('hostel_id');
            $table->uuid('hostel_room_id')->nullable();
            $table->uuid('fee_head_id');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'fee_head_id']);

            $table->foreign(['hostel_id', 'school_id'], 'hostel_fee_heads_hostel_fk')
                ->references(['id', 'school_id'])->on('hostels')->restrictOnDelete();
            $table->foreign(['hostel_room_id', 'hostel_id', 'school_id'], 'hostel_fee_heads_room_fk')
                ->references(['id', 'hostel_id', 'school_id'])->on('hostel_rooms')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'hostel_fee_heads_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX hostel_fee_heads_one_per_hostel ON hostel_fee_heads (school_id, hostel_id) WHERE hostel_room_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX hostel_fee_heads_one_per_room ON hostel_fee_heads (school_id, hostel_room_id) WHERE hostel_room_id IS NOT NULL');
        TenantRls::enable('hostel_fee_heads');

        Schema::create('hostel_fee_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('hostel_residency_assignment_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->uuid('fee_optional_selection_id');
            $table->string('mapping_scope'); // room|hostel
            $table->string('link_reason'); // residency|carry_forward
            $table->string('selection_outcome'); // created|reused
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'hostel_residency_assignment_id', 'academic_year_id'], 'hostel_fee_selections_one_per_year');
            $table->index(['school_id', 'fee_optional_selection_id']);

            $table->foreign(['hostel_residency_assignment_id', 'school_id'], 'hostel_fee_selections_residency_fk')
                ->references(['id', 'school_id'])->on('hostel_residency_assignments')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'hostel_fee_selections_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'hostel_fee_selections_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['fee_optional_selection_id', 'school_id'], 'hostel_fee_selections_selection_fk')
                ->references(['id', 'school_id'])->on('fee_optional_selections')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hostel_fee_selections ADD CONSTRAINT hostel_fee_selections_scope_check CHECK (mapping_scope IN ('room', 'hostel'))");
        DB::statement("ALTER TABLE hostel_fee_selections ADD CONSTRAINT hostel_fee_selections_reason_check CHECK (link_reason IN ('residency', 'carry_forward'))");
        DB::statement("ALTER TABLE hostel_fee_selections ADD CONSTRAINT hostel_fee_selections_outcome_check CHECK (selection_outcome IN ('created', 'reused'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hostel_fee_selections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                      FROM public.fee_optional_selections s
                      JOIN public.hostel_residency_assignments r
                        ON r.id = NEW.hostel_residency_assignment_id AND r.school_id = NEW.school_id
                     WHERE s.id = NEW.fee_optional_selection_id
                       AND s.school_id = NEW.school_id
                       AND s.student_id = r.student_id
                       AND s.academic_year_id = NEW.academic_year_id
                       AND s.fee_head_id = NEW.fee_head_id
                ) THEN
                    RAISE EXCEPTION 'hostel_fee_selections: the selection is not the residency Student''s selection of this year and fee head'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION hostel_fee_selections_guard() FROM PUBLIC;
            CREATE TRIGGER hostel_fee_selections_guard_trigger
                BEFORE INSERT ON hostel_fee_selections FOR EACH ROW EXECUTE FUNCTION hostel_fee_selections_guard();

            -- E21-RH.7 (ADR 0066 §15): the database-recorded anchor (links tracked) and the retention delete guard.
            ALTER TABLE hostel_fee_selections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON hostel_fee_selections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('hostel_residency_assignment_id', 'academic_year_id', 'fee_head_id', 'fee_optional_selection_id');
            CREATE TRIGGER trg_retention_guard_hostel_fee_selections AFTER DELETE ON hostel_fee_selections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('hostel_fee_selections');
        TenantRls::makeAppendOnly('hostel_fee_selections');
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_fee_selections');
        DB::unprepared('DROP FUNCTION IF EXISTS hostel_fee_selections_guard()');
        Schema::dropIfExists('hostel_fee_heads');
        DB::statement('ALTER TABLE hostel_rooms DROP CONSTRAINT IF EXISTS hostel_rooms_id_hostel_school_unique');
    }
};
