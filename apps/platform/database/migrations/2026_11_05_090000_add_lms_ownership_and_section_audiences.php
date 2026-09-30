<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TCH.5B (ADR 0063 sections 34, 35) -- the LMS ownership and Section
 * audience persistence foundation. Dormant: nothing grants a teacher any
 * access through it (no `lms.*.teacher` capability exists), and the
 * administrative surfaces never write it.
 *
 * Two persisted states per resource (`learning_content`, `assignments`):
 *
 * - LEGACY / ADMIN (Offering-wide): `owner_employee_id` NULL and no
 *   audience row. Every existing row stays exactly this -- nothing is
 *   backfilled from an audit actor, a creating User, an email, the
 *   Timetable or a current TeachingAssignment.
 * - TEACHER-OWNED (Section-targeted): an owner Employee AND one or more
 *   audience Sections, all written by ONE transaction.
 *
 * Invariants, each database-enforced (the journal-entry posting pattern,
 * 2026_08_31_090300_add_journal_entry_posting_invariants):
 *
 * 1. Owner same-School: composite FK (owner_employee_id, school_id) ->
 *    employees(id, school_id).
 * 2. Audience context: every audience row is pinned to its parent's
 *    SubjectOffering (FK to the parent's (id, school_id,
 *    subject_offering_id) key), that Offering's AcademicYear/Campus/
 *    GradeLevel (`subject_offerings_context_unique`) and a Section of the
 *    SAME context (`sections_context_unique`) -- CLAUDE.md rule 70.
 * 3. `ownership_txid` is set by an unconditional BEFORE INSERT trigger to
 *    the creating transaction's id for an owned row (NULL otherwise); a
 *    caller-supplied value is always overwritten.
 * 4. Owner immutability: any change of `owner_employee_id` or
 *    `ownership_txid` is refused for every role (so neither
 *    teacher -> other, teacher -> NULL nor NULL -> teacher).
 * 5. Audience only on an owned row, only in its creating transaction: a
 *    BEFORE INSERT trigger on each bridge refuses a parent with no owner
 *    and a parent whose `ownership_txid` is not the current transaction.
 *    A savepoint does not change `pg_current_xact_id()`, so an owned row
 *    created inside a nested transaction still takes its audience.
 * 6. An owned row has >= 1 audience row: a DEFERRABLE INITIALLY DEFERRED
 *    constraint trigger checks at COMMIT (or SET CONSTRAINTS ... IMMEDIATE).
 * 7. Audience rows are never updated (trigger, every role) and never
 *    deleted by the runtime role (TenantRls::makeAppendOnly). They leave
 *    only with their School (cascade), which is why the parent, owner and
 *    context FKs are NO ACTION (checked at statement end) rather than
 *    RESTRICT: a School's cascade removes children and parents together,
 *    while any lone deletion is still refused.
 *
 * The functions are SECURITY INVOKER and read only the same School's rows,
 * under the tenant context the caller already holds (the journal precedent):
 * an owned row must commit while its School context is set, which every LMS
 * service guarantees (withSchool() around its own transaction).
 *
 * `down()` refuses while any owned row or audience row exists: dropping
 * them would silently turn a teacher's Section-targeted resource into
 * Offering-wide administrative material (ADR 0063 section 34.9).
 */
return new class extends Migration
{
    /** @var array<string, array{bridge: string, fk: string, prefix: string}> */
    private const RESOURCES = [
        'learning_content' => ['bridge' => 'learning_content_section_audiences', 'fk' => 'learning_content_id', 'prefix' => 'lms_learning_content'],
        'assignments' => ['bridge' => 'assignment_section_audiences', 'fk' => 'assignment_id', 'prefix' => 'lms_assignment'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION lms_set_ownership_txid() RETURNS trigger AS $$
            BEGIN
                NEW.ownership_txid := CASE WHEN NEW.owner_employee_id IS NULL THEN NULL ELSE pg_current_xact_id() END;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION lms_refuse_ownership_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: the owner of an LMS resource is immutable (row %)', TG_TABLE_NAME, OLD.id
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION lms_refuse_audience_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: a Section audience is immutable (row %)', TG_TABLE_NAME, OLD.id
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (self::RESOURCES as $table => ['bridge' => $bridge, 'fk' => $fk, 'prefix' => $prefix]) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->uuid('owner_employee_id')->nullable();
                $t->index('owner_employee_id');
                $t->unique(['id', 'school_id', 'subject_offering_id'], "{$table}_offering_key");
                $t->foreign(['owner_employee_id', 'school_id'], "{$table}_owner_employee_fk")
                    ->references(['id', 'school_id'])->on('employees');
            });
            DB::statement("ALTER TABLE {$table} ADD COLUMN ownership_txid xid8");

            Schema::create($bridge, function (Blueprint $t) use ($table, $bridge, $fk) {
                $t->uuid('id')->primary();
                $t->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
                $t->uuid($fk);
                $t->uuid('subject_offering_id');
                $t->uuid('academic_year_id');
                $t->uuid('campus_id');
                $t->uuid('grade_level_id');
                $t->uuid('section_id');
                $t->timestamp('created_at')->nullable();

                $t->unique(['id', 'school_id']);
                $t->unique([$fk, 'section_id'], "{$bridge}_unique");
                $t->index(['school_id', 'section_id'], "{$bridge}_section_idx");

                $t->foreign([$fk, 'school_id', 'subject_offering_id'], "{$bridge}_parent_fk")
                    ->references(['id', 'school_id', 'subject_offering_id'])->on($table);
                $t->foreign(['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'], "{$bridge}_offering_fk")
                    ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])->on('subject_offerings');
                $t->foreign(['section_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'], "{$bridge}_section_fk")
                    ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])->on('sections');
            });

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_set_ownership_txid
                    BEFORE INSERT ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION lms_set_ownership_txid();

                CREATE TRIGGER {$table}_ownership_immutable
                    BEFORE UPDATE ON {$table}
                    FOR EACH ROW
                    WHEN (NEW.owner_employee_id IS DISTINCT FROM OLD.owner_employee_id
                        OR NEW.ownership_txid IS DISTINCT FROM OLD.ownership_txid)
                    EXECUTE FUNCTION lms_refuse_ownership_change();

                CREATE FUNCTION {$prefix}_require_audience() RETURNS trigger AS \$\$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM {$bridge} WHERE {$fk} = NEW.id) THEN
                        RAISE EXCEPTION '{$table}: a teacher-owned resource needs at least one Section audience (row %)', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NULL;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE CONSTRAINT TRIGGER {$table}_owned_requires_audience
                    AFTER INSERT ON {$table}
                    DEFERRABLE INITIALLY DEFERRED
                    FOR EACH ROW
                    WHEN (NEW.owner_employee_id IS NOT NULL)
                    EXECUTE FUNCTION {$prefix}_require_audience();

                CREATE FUNCTION {$prefix}_audience_insert_guard() RETURNS trigger AS \$\$
                DECLARE
                    v_owner uuid;
                    v_txid xid8;
                BEGIN
                    SELECT owner_employee_id, ownership_txid INTO v_owner, v_txid
                        FROM {$table}
                        WHERE id = NEW.{$fk} AND school_id = NEW.school_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION '{$bridge}: parent % not found', NEW.{$fk}
                            USING ERRCODE = 'foreign_key_violation';
                    END IF;

                    IF v_owner IS NULL THEN
                        RAISE EXCEPTION '{$bridge}: an Offering-wide (unowned) resource cannot carry a Section audience (parent %)', NEW.{$fk}
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_txid IS DISTINCT FROM pg_current_xact_id() THEN
                        RAISE EXCEPTION '{$bridge}: the Section audience of % is immutable after its creating transaction', NEW.{$fk}
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE TRIGGER {$bridge}_insert_guard
                    BEFORE INSERT ON {$bridge}
                    FOR EACH ROW EXECUTE FUNCTION {$prefix}_audience_insert_guard();

                CREATE TRIGGER {$bridge}_immutable
                    BEFORE UPDATE ON {$bridge}
                    FOR EACH ROW EXECUTE FUNCTION lms_refuse_audience_update();
                SQL);

            TenantRls::enable($bridge);
            TenantRls::makeAppendOnly($bridge);
        }
    }

    public function down(): void
    {
        $this->refuseWhileOwnershipExists();

        foreach (array_reverse(self::RESOURCES, true) as $table => ['bridge' => $bridge, 'prefix' => $prefix]) {
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$bridge}_immutable ON {$bridge};
                DROP TRIGGER IF EXISTS {$bridge}_insert_guard ON {$bridge};
                DROP FUNCTION IF EXISTS {$prefix}_audience_insert_guard();
                DROP TRIGGER IF EXISTS {$table}_owned_requires_audience ON {$table};
                DROP FUNCTION IF EXISTS {$prefix}_require_audience();
                DROP TRIGGER IF EXISTS {$table}_ownership_immutable ON {$table};
                DROP TRIGGER IF EXISTS {$table}_set_ownership_txid ON {$table};
                SQL);

            TenantRls::disable($bridge);
            Schema::dropIfExists($bridge);

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_owner_employee_fk");
                $t->dropUnique("{$table}_offering_key");
                $t->dropIndex(['owner_employee_id']);
                $t->dropColumn(['owner_employee_id', 'ownership_txid']);
            });
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lms_refuse_audience_update();
            DROP FUNCTION IF EXISTS lms_refuse_ownership_change();
            DROP FUNCTION IF EXISTS lms_set_ownership_txid();
            SQL);
    }

    /**
     * Row-level security is FORCED on every table here, so a plain count on
     * the migration connection could see no rows at all. Validating a CHECK
     * constraint scans every row regardless of RLS: `owner_employee_id IS
     * NULL` fails iff an owned row exists, and `false` fails iff the audience
     * table holds any row. The probe constraints are dropped straight away.
     */
    private function refuseWhileOwnershipExists(): void
    {
        foreach (self::RESOURCES as $table => ['bridge' => $bridge]) {
            foreach ([$table => 'owner_employee_id IS NULL', $bridge => 'false'] as $probed => $expression) {
                try {
                    DB::statement("ALTER TABLE {$probed} ADD CONSTRAINT tch5b_rollback_probe CHECK ({$expression})");
                    DB::statement("ALTER TABLE {$probed} DROP CONSTRAINT tch5b_rollback_probe");
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23514') {
                        throw $e;
                    }

                    throw new RuntimeException("tch5b rollback: refusing -- {$probed} holds teacher ownership or Section audience data; dropping it would turn Section-targeted teacher resources into Offering-wide administrative material. Nothing was changed.", 0, $e);
                }
            }
        }
    }
};
