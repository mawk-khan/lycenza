<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-18 (C34-N3, owner decision D-C35-9, 2026-11-13): "never one's own" is person-level for
 * every separation write and for administrative leave withdraw and cancel.
 *
 * Rule 145 kept the current link as HR's answer to "is this the actor's own record" (OD-HR-25). C34
 * protected the separation and leave DECISIONS against known-same accounts; the other writes still
 * compared only the current link, so an old (ended, uncorrected) or second account of the separating
 * person could cancel, finalize, clear or complete their own case, and an administrator's old account
 * could withdraw or cancel their own leave. The owner amended OD-HR-25 for HR administration.
 *
 * Each guard now also refuses an actor who, or whose known-same account (rule 152,
 * `employee_account_link_same_person_accounts`), holds an uncorrected link to the subject Employee:
 * - `hr_employment_separation_cases_guard`: the recorder at INSERT; every later recorded actor other
 *   than the decider (withdrawn, cancelled, finalized, access-retained, completed), EACH newly set column
 *   on its own (a raw writer setting two at once is judged on both); the decider is C34's;
 * - `hr_separation_clearance_items_guard`: whoever clears an item;
 * - `leave_decisions_refuse_submitter`: an administrative `withdrawn` or `cancelled` decision. This also
 *   gives the CURRENT link a database backstop there for the first time (the service already refused it).
 *
 * Evaluated at the write; no row is read for rewriting or changed. Patched in the LIVE definitions
 * (exactly-once anchors), never restated; `down()` removes exactly what it added.
 */
return new class extends Migration
{
    /**
     * signature => list of [the live clause this change follows, what it adds after it].
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const GUARDS = [
        'public.hr_employment_separation_cases_guard()' => [
            [
                <<<'SQL'
                            RAISE EXCEPTION 'nobody records their own separation (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                        END IF;
                SQL,
                <<<'SQL'

                        -- HR.R1-C35 WO-18: nor through an old or known-same account of the separating person.
                        IF EXISTS (SELECT 1 FROM public.employment_records er
                                     JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id
                                    WHERE er.school_id = NEW.school_id AND er.id = NEW.employment_record_id AND l.voided_at IS NULL
                                      AND (l.user_id = NEW.requested_by_user_id
                                           OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.requested_by_user_id) k))) THEN
                            RAISE EXCEPTION 'nobody records their own separation through any account known to be theirs (hr_employment_separation_own_case)' USING ERRCODE = 'check_violation';
                        END IF;
                SQL,
            ],
            [
                <<<'SQL'
                        RAISE EXCEPTION 'a separation is decided by an account known to be its recorder or the separating person (hr_employment_separation_same_person)' USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
                <<<'SQL'

                    -- HR.R1-C35 WO-18: EVERY other newly recorded actor (withdrawn, cancelled, finalized, access-retained,
                    -- completed), each column on its own, is never the separating person through any account known to be
                    -- theirs. The decider is C34's clause above.
                    IF EXISTS (
                        SELECT 1 FROM unnest(ARRAY[
                                 CASE WHEN NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id THEN NEW.withdrawn_by_user_id END,
                                 CASE WHEN NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id THEN NEW.cancelled_by_user_id END,
                                 CASE WHEN NEW.finalized_by_user_id IS DISTINCT FROM OLD.finalized_by_user_id THEN NEW.finalized_by_user_id END,
                                 CASE WHEN NEW.access_retained_by_user_id IS DISTINCT FROM OLD.access_retained_by_user_id THEN NEW.access_retained_by_user_id END,
                                 CASE WHEN NEW.completed_by_user_id IS DISTINCT FROM OLD.completed_by_user_id THEN NEW.completed_by_user_id END
                               ]) AS a(user_id)
                          JOIN public.employment_records er ON er.school_id = NEW.school_id AND er.id = NEW.employment_record_id
                          JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id AND l.voided_at IS NULL
                         WHERE a.user_id IS NOT NULL
                           AND (l.user_id = a.user_id
                                OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, a.user_id) k))) THEN
                        RAISE EXCEPTION 'nobody administers their own separation through any account known to be theirs (hr_employment_separation_own_case)' USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
            ],
        ],
        'public.hr_separation_clearance_items_guard()' => [
            [
                <<<'SQL'
                        RAISE EXCEPTION 'nobody clears their own separation (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
                <<<'SQL'

                    -- HR.R1-C35 WO-18: nor through an old or known-same account of the separating person.
                    IF EXISTS (SELECT 1 FROM public.employment_records er
                                 JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id
                                WHERE er.school_id = NEW.school_id AND er.id = v_case.employment_record_id AND l.voided_at IS NULL
                                  AND (l.user_id = NEW.cleared_by_user_id
                                       OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.cleared_by_user_id) k))) THEN
                        RAISE EXCEPTION 'nobody clears their own separation through any account known to be theirs (hr_separation_clearance_own_case)' USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
            ],
        ],
        'public.leave_decisions_refuse_submitter()' => [
            [
                <<<'SQL'
                        RAISE EXCEPTION 'leave_decisions_same_person: leave request % is decided by an account known to be its requester or submitter', NEW.leave_request_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
                <<<'SQL'

                    -- HR.R1-C35 WO-18: an administrator never withdraws or cancels their own leave, through the current link,
                    -- an old (ended, uncorrected) link or an account known to be theirs.
                    IF NEW.path = 'administrative' AND NEW.decision IN ('withdrawn', 'cancelled') AND EXISTS (
                        SELECT 1 FROM public.leave_requests r
                          JOIN public.employee_account_links l ON l.school_id = r.school_id AND l.employee_id = r.employee_id
                         WHERE r.id = NEW.leave_request_id AND r.school_id = NEW.school_id AND l.voided_at IS NULL
                           AND (l.user_id = NEW.decided_by_user_id
                                OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.decided_by_user_id) k))
                    ) THEN
                        RAISE EXCEPTION 'leave_decisions_own_leave: leave request % is withdrawn or cancelled administratively by an account known to be its requester', NEW.leave_request_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                SQL,
            ],
        ],
    ];

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
        foreach (self::GUARDS as $signature => $clauses) {
            foreach ($clauses as [$live, $added]) {
                $this->patch($signature, $live, $live.$added);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::GUARDS, true) as $signature => $clauses) {
            foreach (array_reverse($clauses) as [$live, $added]) {
                $this->patch($signature, $live.$added, $live);
            }
        }
    }
};
