<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO17-N1, Option A (owner decision D-WO18-1, 2026-11-13: MEDIUM).
 *
 * WO-17 made the self-beneficiary refusal at DECISION time match an account known to be the same person
 * as the paid Employee. After a decision, three functions still asked "is this approver the Employee of a
 * change" through the approver's own links only:
 * - `payroll_run_first_paid_compensation`: an approval by the Employee does not discharge a change;
 * - `payroll_run_independence_exception`: an approved run whose approver is the Employee of a change it
 *   now pays first lost its independence (attestation before posting, rule 144);
 * - `payroll_reversal_affected_runs`: which posted runs a reversal implicates (an obligation, rule 144),
 *   both its "discharged" step and its candidate filter.
 * Reproduced at 99ba7df0: a run approved by the Employee's second, known-same account, then made to pay
 * the raise first by a reversal, posted with no attestation; the Employee's own account needed one.
 *
 * AUTHOR side (owner decision D-WO17N1-1, 2026-11-13, MEDIUM, found by this unit's review): the same three
 * functions compared the change's AUTHOR with the approver by account id only, so an approval by an account
 * known to be the author also discharged a change and kept a run "independent". They now treat it like the
 * author's own approval, through the same `known` set.
 *
 * Each now also matches an account known to be the same person as the approver, through the one relation
 * (`employee_account_link_same_person_accounts`, rule 152). PERFORMANCE (review M-1): the relation is a
 * non-inlinable function, so it is asked ONCE per distinct approver in a materialized `known` set at the
 * top of each function, never per row: a per-row form exceeded 30 s at 10,000 entries under the exclusive
 * compensation lock (`PayrollKnownSameLargePopulationTest`). The existing materialization (HR.R1-C7-R1) is
 * kept. Evaluated when asked; no approval, run, posting or attestation is read for rewriting or changed.
 * Patched in the LIVE definitions (exactly-once anchors), never restated (rule 146); `down()` restores
 * exactly those clauses.
 */
return new class extends Migration
{
    /** The distinct approvers of this School's standing regular runs, each asked once. */
    private const KNOWN_APPROVERS = <<<'SQL'
        known AS MATERIALIZED (
                SELECT a.approver, k.user_id
                  FROM (SELECT DISTINCT o.approved_by_user_id AS approver FROM public.payroll_runs o
                         WHERE o.school_id = p_school AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                           AND o.approved_by_user_id IS NOT NULL) a
                 CROSS JOIN LATERAL public.employee_account_link_same_person_accounts(p_school, a.approver) k
            )
        SQL;

    private static function knownAuthor(string $approver, string $author): string
    {
        return "EXISTS (SELECT 1 FROM known k WHERE k.approver = {$approver} AND k.user_id = {$author})";
    }

    private static function known(string $approver): string
    {
        return "l.voided_at IS NULL AND (l.user_id = {$approver} OR EXISTS (SELECT 1 FROM known k WHERE k.approver = {$approver} AND k.user_id = l.user_id))";
    }

    /**
     * signature => list of [the live clause, its replacement].
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function clauses(): array
    {
        return [
            'public.payroll_run_first_paid_compensation(uuid, uuid, uuid)' => [
                [
                    "    SELECT s.compensation_assignment_id, s.employment_record_id, a.assigned_by_user_id, s.basis::text\n",
                    '    WITH '.self::KNOWN_APPROVERS."\n    SELECT s.compensation_assignment_id, s.employment_record_id, a.assigned_by_user_id, s.basis::text\n",
                ],
                [
                    "              AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id\n",
                    "              AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id\n              AND NOT ".self::knownAuthor('o.approved_by_user_id', 'a.assigned_by_user_id')."\n",
                ],
                [
                    'AND l.user_id = o.approved_by_user_id AND l.voided_at IS NULL))',
                    'AND '.self::known('o.approved_by_user_id').'))',
                ],
            ],
            'public.payroll_run_independence_exception(uuid, uuid)' => [
                [
                    "    SELECT f.compensation_assignment_id, f.employment_record_id\n      FROM public.payroll_runs r\n",
                    '    WITH '.self::KNOWN_APPROVERS."\n    SELECT f.compensation_assignment_id, f.employment_record_id\n      FROM public.payroll_runs r\n",
                ],
                [
                    "AND (f.assigned_by_user_id = r.approved_by_user_id\n",
                    'AND (f.assigned_by_user_id = r.approved_by_user_id OR '.self::knownAuthor('r.approved_by_user_id', 'f.assigned_by_user_id')."\n",
                ],
                [
                    'AND l.user_id = r.approved_by_user_id AND l.voided_at IS NULL))',
                    'AND '.self::known('r.approved_by_user_id').'))',
                ],
            ],
            'public.payroll_reversal_affected_runs(uuid, uuid, uuid)' => [
                [
                    '    WITH reversed_run_paid AS MATERIALIZED (',
                    '    WITH '.self::KNOWN_APPROVERS.', reversed_run_paid AS MATERIALIZED (',
                ],
                [
                    'AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id',
                    'AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id AND NOT '.self::knownAuthor('r.approved_by_user_id', 'a.assigned_by_user_id'),
                ],
                [
                    'AND l.user_id = p.approved_by_user_id AND l.voided_at IS NULL)',
                    'AND '.self::known('p.approved_by_user_id').')',
                ],
                [
                    "AND (o.approved_by_user_id = d.assigned_by_user_id\n",
                    'AND (o.approved_by_user_id = d.assigned_by_user_id OR '.self::knownAuthor('o.approved_by_user_id', 'd.assigned_by_user_id')."\n",
                ],
                [
                    'AND l.user_id = o.approved_by_user_id AND l.voided_at IS NULL))',
                    'AND '.self::known('o.approved_by_user_id').'))',
                ],
            ],
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
        foreach ($this->clauses() as $signature => $pairs) {
            foreach ($pairs as [$live, $patched]) {
                $this->patch($signature, $live, $patched);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->clauses(), true) as $signature => $pairs) {
            foreach (array_reverse($pairs) as [$live, $patched]) {
                $this->patch($signature, $patched, $live);
            }
        }
    }
};
