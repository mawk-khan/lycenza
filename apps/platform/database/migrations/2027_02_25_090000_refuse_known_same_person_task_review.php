<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-22 (TRR-6, owner decision D-C35-5; rule 152).
 *
 * A reviewed staff task's review (submitted -> completed | returned) is a second person's decision. The
 * database refused only the assignee's CURRENT account as reviewer. It now also refuses, as rule 152 requires
 * of an HR-family workflow, a reviewer KNOWN to be the assignee: an account with an uncorrected link (current
 * or ended) to the task's Employee, or one known to be the same person as such an account
 * (`employee_account_link_same_person_accounts`, SECURITY INVOKER under RLS):
 * - completing a review: `workforce_tasks.completed_by_user_id` (`hr_workforce_tasks_guard`);
 * - returning it: the `returned` event's actor, the only record of who returned it
 *   (`hr_workforce_task_events_guard`).
 * Nor (review L1) the account that SUBMITTED the task, or one known to be the same person as it -- the
 * submitter is the maker even after an erroneous link of it is corrected.
 * A self-completed task (no review) is unchanged. Who acted stays an application boundary (rule 102); the
 * database refuses the recorded reviewer. Patched in the LIVE definitions (exactly-once anchors), never
 * restated (rule 146); `down()` removes exactly the clauses added.
 */
return new class extends Migration
{
    private const TASK_ANCHOR = "        RAISE EXCEPTION 'nobody reviews their own task (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';\n    END IF;\n";

    private const TASK_CLAUSE = <<<'SQL'
            -- HR.R1-C35 WO-22 (TRR-6, rule 152): nor an account known to be the assignee.
            IF OLD.status = 'submitted' AND NEW.status = 'completed' AND (EXISTS (
                SELECT 1 FROM public.employee_account_links l JOIN public.employment_records er ON er.employee_id = l.employee_id
                 WHERE er.id = NEW.employment_record_id AND l.voided_at IS NULL
                   AND (l.user_id = NEW.completed_by_user_id
                        OR l.user_id IN (SELECT a.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.completed_by_user_id) a)))
                OR EXISTS (SELECT 1 FROM public.workforce_task_events s WHERE s.task_id = NEW.id AND s.kind = 'submitted'
                   AND (s.actor_user_id = NEW.completed_by_user_id
                        OR s.actor_user_id IN (SELECT a.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.completed_by_user_id) a)))) THEN
                RAISE EXCEPTION 'nobody known to be a task''s assignee reviews it (hr_workforce_task_review_same_person)' USING ERRCODE = 'check_violation';
            END IF;

        SQL;

    private const EVENT_ANCHOR = "        RAISE EXCEPTION 'nobody assigns, returns, cancels or reassigns their own task (hr_workforce_task_events_guard)' USING ERRCODE = 'check_violation';\n    END IF;\n";

    private const EVENT_CLAUSE = <<<'SQL'
            -- HR.R1-C35 WO-22 (TRR-6, rule 152): a return is a review; nor by an account known to be the assignee.
            IF NEW.kind = 'returned' AND (EXISTS (
                SELECT 1 FROM public.workforce_tasks t JOIN public.employment_records er ON er.id = t.employment_record_id
                  JOIN public.employee_account_links l ON l.employee_id = er.employee_id
                 WHERE t.id = NEW.task_id AND l.voided_at IS NULL
                   AND (l.user_id = NEW.actor_user_id
                        OR l.user_id IN (SELECT a.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.actor_user_id) a)))
                OR EXISTS (SELECT 1 FROM public.workforce_task_events s WHERE s.task_id = NEW.task_id AND s.kind = 'submitted'
                   AND (s.actor_user_id = NEW.actor_user_id
                        OR s.actor_user_id IN (SELECT a.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.actor_user_id) a)))) THEN
                RAISE EXCEPTION 'nobody known to be a task''s assignee returns it (hr_workforce_task_review_same_person)' USING ERRCODE = 'check_violation';
            END IF;

        SQL;

    /** @return array<string, array{0: string, 1: string}> function => [anchor, anchor + clause] */
    private function patches(): array
    {
        return [
            'public.hr_workforce_tasks_guard()' => [self::TASK_ANCHOR, self::TASK_ANCHOR.self::TASK_CLAUSE],
            'public.hr_workforce_task_events_guard()' => [self::EVENT_ANCHOR, self::EVENT_ANCHOR.self::EVENT_CLAUSE],
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
        foreach ($this->patches() as $signature => [$live, $patched]) {
            $this->patch($signature, $live, $patched);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->patches(), true) as $signature => [$live, $patched]) {
            $this->patch($signature, $patched, $live);
        }
    }
};
