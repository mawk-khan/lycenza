<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Salary structure revision model") -- each
     * row here IS one immutable revision, not a mutable document with
     * a hidden version history. Logical identity is `(school_id,
     * code)`; `version` increments per revision sharing that code;
     * `status` is `draft`|`active`|`superseded`.
     *
     * Freeze boundary is ACTIVATION, not first assignment -- a
     * revision the UI already shows as "the current active one" can
     * never silently change underneath it, even before anything is
     * assigned. `salary_structures_freeze_active_or_superseded()`
     * below enforces this: a `draft` row may change any column
     * freely; an `active` row may only transition to `superseded`
     * (no other column may change in that same UPDATE); a
     * `superseded` row is fully terminal. Deletion is a separate
     * concern (rule 73's "deactivate, never delete" pattern) --
     * enforced at the Application layer (only a never-activated
     * `draft` row may ever be deleted, since nothing can reference an
     * `active`/`superseded` row's `structure_components` any
     * differently), not by this migration.
     *
     * `UNIQUE(school_id, code) WHERE status='active'` is the real,
     * concurrency-safe "only one active revision per code" guarantee
     * -- directly mirroring `academic_years_one_active_per_school`'s
     * already-proven partial-unique-index pattern (Academic
     * Structure, Phase 0D), not an application-level check-then-update.
     *
     * Uses `CREATE OR REPLACE FUNCTION` (not bare `CREATE FUNCTION`)
     * deliberately: this repository's `migrate:fresh` cycle does not
     * clean up standalone trigger functions between resets (a
     * pre-existing test-infrastructure defect outside Payroll's
     * module boundary -- see this checkpoint's validation notes), so
     * every NEW trigger function Payroll introduces is written to be
     * safe under repeated fresh-migrate cycles regardless, rather than
     * adding to that defect.
     */
    public function up(): void
    {
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('status')->default('draft'); // draft|active|superseded
            $table->timestamps();

            $table->unique(['school_id', 'code', 'version']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement("ALTER TABLE salary_structures ADD CONSTRAINT salary_structures_status_check CHECK (status IN ('draft', 'active', 'superseded'))");
        DB::statement('CREATE UNIQUE INDEX salary_structures_one_active_per_code ON salary_structures (school_id, code) WHERE status = \'active\'');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION salary_structures_freeze_active_or_superseded() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'superseded' THEN
                    RAISE EXCEPTION 'salary_structures: a superseded revision (%) can never be modified.', OLD.id;
                END IF;

                IF OLD.status = 'active' THEN
                    IF NEW.status IS DISTINCT FROM 'superseded'
                        OR NEW.code IS DISTINCT FROM OLD.code
                        OR NEW.version IS DISTINCT FROM OLD.version
                        OR NEW.name IS DISTINCT FROM OLD.name
                        OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    THEN
                        RAISE EXCEPTION 'salary_structures: an active revision (%) may only transition to superseded, with no other column changed.', OLD.id;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_salary_structures_freeze
                BEFORE UPDATE ON salary_structures
                FOR EACH ROW
                EXECUTE FUNCTION salary_structures_freeze_active_or_superseded();
        SQL);

        TenantRls::enable('salary_structures');
    }

    public function down(): void
    {
        TenantRls::disable('salary_structures');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_salary_structures_freeze ON salary_structures');
        DB::unprepared('DROP FUNCTION IF EXISTS salary_structures_freeze_active_or_superseded()');
        Schema::dropIfExists('salary_structures');
    }
};
