<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Academic Structure integrity (ADR 0068 §27.11 S1; ADR 0069): a
 * SubjectOffering's required/elective classification is frozen once any
 * dependent evidence exists, and no evidence is recorded against the wrong
 * classification. Database-enforced, race-free, symmetric.
 *
 * Evidence (any row, any status -- ended, cancelled and withdrawn rows are
 * history too; P3 counts cancelled intervals):
 *   required-only: teaching_assignments, timetable_entries,
 *                  curriculum_deliveries, attendance_sessions
 *   elective-only: student_subject_enrollments, elective_teaching_assignments
 *   either:        examination_papers (P3 and StudentMark judge a paper's
 *                  roster by the classification; marks need a paper)
 *
 * 1. subject_offering_classification_freeze (BEFORE UPDATE OF is_required on
 *    subject_offerings): an actual change (not a no-op) is refused while any
 *    evidence row exists. The UPDATE has locked the Offering row first, so
 *    the check runs after every concurrent evidence writer holding the row
 *    FOR SHARE has committed (and sees its row).
 * 2. subject_offering_evidence_guard(kind) (BEFORE INSERT OR UPDATE OF
 *    subject_offering_id on each evidence table): takes the Offering row FOR
 *    SHARE -- it conflicts with the flip's row lock, so a flip and the first
 *    evidence serialize -- and refuses evidence that does not match the
 *    (now stable) classification.
 *
 * The preflight refuses to install the rule over data that already
 * contradicts it (an earlier flip): it reports the counts and changes
 * nothing, so an operator decides; nothing is rewritten silently.
 */
return new class extends Migration
{
    /** @var array<string, string> evidence table => required | elective | any */
    private const EVIDENCE = [
        'teaching_assignments' => 'required',
        'timetable_entries' => 'required',
        'curriculum_deliveries' => 'required',
        'attendance_sessions' => 'required',
        'student_subject_enrollments' => 'elective',
        'elective_teaching_assignments' => 'elective',
        'examination_papers' => 'any',
    ];

    public function up(): void
    {
        // Forced RLS binds the owner too: count per School under its own tenant context, never with an empty one.
        $mismatches = [];
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::transaction(function () use ($schoolId, &$mismatches): void {
                DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
                foreach (self::EVIDENCE as $table => $kind) {
                    if ($kind === 'any') {
                        continue;
                    }
                    $count = (int) DB::selectOne("SELECT count(*) AS c FROM {$table} e JOIN subject_offerings o ON o.id = e.subject_offering_id AND o.school_id = e.school_id
                        WHERE e.school_id = ? AND o.is_required IS DISTINCT FROM ?", [$schoolId, $kind === 'required'])->c;
                    if ($count > 0) {
                        $mismatches[] = "{$table}: {$count}";
                    }
                }
            });
        }
        if ($mismatches !== []) {
            throw new RuntimeException('Refusing to freeze SubjectOffering classification: existing evidence contradicts its Offering\'s classification ('.implode('; ', $mismatches).'). Resolve the data deliberately first; nothing was changed.');
        }

        $exists = implode("\n                    OR ", array_map(
            fn (string $table) => "EXISTS (SELECT 1 FROM public.{$table} e WHERE e.subject_offering_id = OLD.id AND e.school_id = OLD.school_id)",
            array_keys(self::EVIDENCE),
        ));

        DB::unprepared(<<<SQL
            CREATE FUNCTION subject_offering_classification_freeze() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
            BEGIN
                IF NEW.is_required IS NOT DISTINCT FROM OLD.is_required THEN
                    RETURN NEW;
                END IF;
                IF {$exists} THEN
                    RAISE EXCEPTION 'subject_offering_classification_locked: the Subject Offering has dependent academic evidence; its required/elective classification cannot change'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION subject_offering_classification_freeze() FROM PUBLIC;

            CREATE TRIGGER trg_subject_offering_classification_freeze
                BEFORE UPDATE OF is_required ON subject_offerings
                FOR EACH ROW EXECUTE FUNCTION subject_offering_classification_freeze();

            CREATE FUNCTION subject_offering_evidence_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
            DECLARE
                offering_required boolean;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.subject_offering_id IS NOT DISTINCT FROM OLD.subject_offering_id THEN
                    RETURN NEW;
                END IF;
                -- FOR SHARE: waits for (and then sees) a concurrent classification change; makes a later one wait for this row.
                SELECT o.is_required INTO offering_required FROM public.subject_offerings o
                 WHERE o.id = NEW.subject_offering_id AND o.school_id = NEW.school_id
                 FOR SHARE;
                IF NOT FOUND THEN
                    -- Not visible in this tenant context (another School, no context): RLS and the composite
                    -- foreign keys refuse such a row with their own errors -- this guard does not pre-empt them.
                    RETURN NEW;
                END IF;
                IF (TG_ARGV[0] = 'required' AND offering_required IS NOT TRUE) OR (TG_ARGV[0] = 'elective' AND offering_required IS NOT FALSE) THEN
                    RAISE EXCEPTION 'subject_offering_classification_mismatch: % requires a % Subject Offering', TG_TABLE_NAME, TG_ARGV[0]
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION subject_offering_evidence_guard() FROM PUBLIC;
            SQL);

        foreach (self::EVIDENCE as $table => $kind) {
            DB::unprepared("CREATE TRIGGER trg_subject_offering_evidence BEFORE INSERT OR UPDATE OF subject_offering_id ON {$table}
                FOR EACH ROW EXECUTE FUNCTION subject_offering_evidence_guard('{$kind}')");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::EVIDENCE) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_subject_offering_evidence ON {$table}");
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_subject_offering_classification_freeze ON subject_offerings;
            DROP FUNCTION IF EXISTS subject_offering_evidence_guard();
            DROP FUNCTION IF EXISTS subject_offering_classification_freeze();
            SQL);
    }
};
