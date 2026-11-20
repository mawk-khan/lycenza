<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-23 (WO22-N1, RF1-N1; owner decisions D-RF2-1, D-RF2-1a; rule 102, ADR 0076 §64).
 *
 * A staff task's `submitted` and `returned` states record their actor ONLY on the timeline event (the task row
 * has no submitted-by or returned-by column), so the event is the evidence of that transition. Until now the
 * runtime role could commit either state with no event. Now the database binds them, without knowing who acted:
 * - `workforce_task_events.task_version` names the task version the event documents. It is set only on a
 *   `submitted` or `returned` event (CHECK), and is unique per task (one event per version). Existing events keep
 *   NULL: nothing is back-filled, and no historical event is rewritten;
 * - the event guard refuses a `submitted`/`returned` event unless the task, in the same School, is in exactly
 *   that state at exactly that version. Because every writer updates the task first and inserts the event after
 *   it, the event sees the transition it documents, and a past or future one is refused. The WO-22 same-person
 *   clause stays first, so its refusal is unchanged;
 * - a DEFERRABLE INITIALLY DEFERRED constraint trigger refuses, at commit, a change of the task's status to
 *   `submitted` or `returned` that has no such event. Each UPDATE is checked against its own version, so two
 *   transitions in one transaction each need their event. A task the commit cannot see under RLS is refused
 *   (an empty answer proves nothing; PAY-15).
 * Found by WO-23's independent review and fixed here (owner's choice, 2026-11-20), in `hr_workforce_tasks_guard`:
 * - WO23-N1 (MEDIUM): the branch for the reassignment FK's `SET NULL` action returned before the immutability and
 *   finality checks, so one raw UPDATE that also nulled `reassigned_from_task_id` could rewrite a reassigned task's
 *   assignee, terms or dates, even a completed one (rule 139). That branch now requires every other column unchanged;
 * - review L2: a version advance with no status change minted a "current" version an event could then claim. A
 *   version now advances only with a transition (every writer already changes status with it).
 * Who acted stays an application boundary (rule 102): the event's actor is the writer's claim. The guards are
 * patched in their LIVE definitions (exactly-once anchors), never restated (rule 146); `down()` reverses exactly
 * what this adds. Rolling it back restores the WO22-N1 and WO23-N1 gaps (rule 143: local and testing only).
 */
return new class extends Migration
{
    private const EVENT_ANCHOR = "        RAISE EXCEPTION 'nobody known to be a task''s assignee returns it (hr_workforce_task_review_same_person)' USING ERRCODE = 'check_violation';\n    END IF;\n";

    private const EVENT_CLAUSE = <<<'SQL'
            -- HR.R1-C35 WO-23 (WO22-N1, RF1-N1; rule 102): a submission or return event documents exactly the
            -- transition the task has just made: same School and task, its current status, its current version.
            IF NEW.kind IN ('submitted', 'returned') AND (NEW.task_version IS NULL OR NOT EXISTS (
                SELECT 1 FROM public.workforce_tasks t
                 WHERE t.id = NEW.task_id AND t.school_id = NEW.school_id AND t.status = NEW.kind AND t.version = NEW.task_version)) THEN
                RAISE EXCEPTION 'a submission or return event documents the task version that transition made (hr_workforce_task_event_transition)' USING ERRCODE = 'check_violation';
            END IF;

        SQL;

    /** WO23-N1 (review M-1): the FK SET NULL branch returned before the immutability and finality checks. */
    private const SET_NULL_LIVE = "        AND ROW(NEW.status, NEW.version) = ROW(OLD.status, OLD.version) THEN\n";

    private const SET_NULL_PATCHED = "        AND (to_jsonb(NEW) - 'reassigned_from_task_id') = (to_jsonb(OLD) - 'reassigned_from_task_id') THEN -- WO-23 (WO23-N1): nothing else changes\n";

    private const VERSION_ANCHOR = "        RAISE EXCEPTION 'every staff task change advances its version (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';\n    END IF;\n";

    private const VERSION_CLAUSE = <<<'SQL'
            -- HR.R1-C35 WO-23 (review L2): a version is a transition, so it never advances without one.
            IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a staff task''s version advances only with a transition (hr_workforce_task_version_transition)' USING ERRCODE = 'check_violation';
            END IF;

        SQL;

    /** @return list<array{0: string, 1: string, 2: string}> [function, live text, patched text], applied in order */
    private function patches(): array
    {
        return [
            ['public.hr_workforce_task_events_guard()', self::EVENT_ANCHOR, self::EVENT_ANCHOR.self::EVENT_CLAUSE],
            ['public.hr_workforce_tasks_guard()', self::SET_NULL_LIVE, self::SET_NULL_PATCHED],
            ['public.hr_workforce_tasks_guard()', self::VERSION_ANCHOR, self::VERSION_ANCHOR.self::VERSION_CLAUSE],
        ];
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [$signature])->d;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException("{$signature} no longer has exactly one clause this migration changes.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    public function up(): void
    {
        DB::statement('ALTER TABLE workforce_task_events ADD COLUMN task_version integer');
        DB::statement("ALTER TABLE workforce_task_events ADD CONSTRAINT workforce_task_events_task_version_check CHECK (task_version IS NULL OR (task_version >= 1 AND kind IN ('submitted', 'returned')))");
        DB::statement('CREATE UNIQUE INDEX workforce_task_events_one_per_version ON workforce_task_events (school_id, task_id, task_version) WHERE task_version IS NOT NULL');

        foreach ($this->patches() as [$signature, $live, $patched]) {
            $this->patch($signature, $live, $patched);
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_workforce_task_transition_evidence() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                -- Under RLS an empty answer proves nothing (PAY-15): a commit that cannot see the task is refused.
                IF NOT EXISTS (SELECT 1 FROM public.workforce_tasks t WHERE t.id = NEW.id AND t.school_id = NEW.school_id) THEN
                    RAISE EXCEPTION 'a staff task''s transition cannot be checked without its School context (hr_workforce_task_transition_context)' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.workforce_task_events e
                                WHERE e.school_id = NEW.school_id AND e.task_id = NEW.id AND e.kind = NEW.status AND e.task_version = NEW.version) THEN
                    RAISE EXCEPTION 'a staff task became % at version % with no event documenting it (hr_workforce_task_transition_evidence)', NEW.status, NEW.version USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER trg_workforce_tasks_transition_evidence AFTER UPDATE OF status ON public.workforce_tasks
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('submitted', 'returned'))
                EXECUTE FUNCTION public.hr_workforce_task_transition_evidence();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_workforce_tasks_transition_evidence ON public.workforce_tasks;
            DROP FUNCTION IF EXISTS public.hr_workforce_task_transition_evidence();
            SQL);
        foreach (array_reverse($this->patches()) as [$signature, $live, $patched]) {
            $this->patch($signature, $patched, $live);
        }
        DB::statement('DROP INDEX IF EXISTS workforce_task_events_one_per_version');
        DB::statement('ALTER TABLE workforce_task_events DROP CONSTRAINT IF EXISTS workforce_task_events_task_version_check');
        DB::statement('ALTER TABLE workforce_task_events DROP COLUMN IF EXISTS task_version');
    }
};
