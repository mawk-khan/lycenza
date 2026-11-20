<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-24 (WO20-N1, RF1-N2, WO20-N7; owner decision D-RF2-2, RF2 §5.3 items 1a and 5; R-HR12-3 not amended).
 *
 * An unlink no longer clears School access. Two clauses of `hr_separation_retained_grants_guard` (WO-20) become
 * person-level: the accounts read are every account with an uncorrected link, current or ended, to the case's
 * Employee (rules 145, 152) -- the current link included -- instead of the current link alone:
 *
 * - **Recording kept access** (item 1a): the stored set must equal the live `school`-scope grants of EVERY one of
 *   those accounts, so kept access can be recorded after an unlink and an older account's role is bound, not
 *   left outside the set. A forged, partial, padded or other-School set is still refused; immutability, "never
 *   back-filled" and "born empty" are unchanged.
 * - **Completion** (item 5, new): `completed` is refused while any of those accounts holds a live School grant
 *   outside the kept set (no set: any grant -- a case never kept, or a legacy record, D-WO20-2). It judges the
 *   transition only, never an existing completed row, so no completion is rewritten. It needs no actor
 *   identity: who completed stays an application boundary (rule 102).
 *
 * A link corrected as another person's (`voided_at`, rule 145's two-person path) leaves the set, so a genuinely
 * wrong account never traps a case. SECURITY INVOKER under the caller's RLS (the case row is visible only in its
 * School's context, and so are the links and grants read), `search_path` pinned with `pg_temp` last, every
 * reference schema-qualified. Patched in the LIVE definition (exactly-once anchors), never restated; `down()`
 * reverses exactly these replacements. No row is read for rewriting or changed.
 */
return new class extends Migration
{
    private const SIGNATURE = 'public.hr_separation_retained_grants_guard()';

    /** The accounts still believed to be the case's Employee: uncorrected links, plus the current link. */
    private const PERSON = <<<'SQL'
        AND m.user_id IN (SELECT l.user_id FROM public.employment_records er
                                     JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id AND l.voided_at IS NULL
                                    WHERE er.school_id = NEW.school_id AND er.id = NEW.employment_record_id
                                   UNION
                                   SELECT public.hr_separation_subject_user(NEW.employment_record_id))
        SQL;

    /** @return list<array{0: string, 1: string}> [live text, replacement] */
    private function replacements(): array
    {
        return [
            [
                <<<'SQL'
                           AND m.user_id = public.hr_separation_subject_user(NEW.employment_record_id);
                        IF v_live IS NULL OR NEW.access_retained_grant_ids IS DISTINCT FROM v_live THEN
                            RAISE EXCEPTION 'kept access binds exactly the account''s live School role grants (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                SQL,
                <<<'SQL'
                           -- HR.R1-C35 WO-24: every account still believed to be the person, not only the current link.
                SQL."\n           ".self::PERSON.<<<'SQL'
                ;
                        IF v_live IS NULL OR NEW.access_retained_grant_ids IS DISTINCT FROM v_live THEN
                            RAISE EXCEPTION 'kept access binds exactly the live School role grants of every account of the person (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                SQL,
            ],
            [
                <<<'SQL'
                    END IF;

                    RETURN NEW;
                END;
                SQL,
                <<<'SQL'
                    END IF;

                    -- HR.R1-C35 WO-24 (WO20-N1, D-RF2-2): a separation is completed only while no account still believed to be
                    -- the person holds a live School role outside the kept set (none kept, or a legacy record: any role).
                    -- R-HR12-3: access is off-boarded, never cleared by changing which account is linked.
                    IF NEW.status = 'completed' AND OLD.status IS DISTINCT FROM 'completed' AND EXISTS (
                        SELECT 1
                          FROM public.school_memberships m
                          JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL
                          JOIN public.roles r ON r.id = a.role_id AND r.scope = 'school'
                         WHERE m.school_id = NEW.school_id AND m.status = 'active'
                           AND NOT (a.id = ANY (COALESCE(NEW.access_retained_grant_ids, '{}'::uuid[])))
                SQL."\n           ".self::PERSON.<<<'SQL'
                ) THEN
                        RAISE EXCEPTION 'a separation is completed only while no account of the person holds a School role outside the kept set (hr_separation_completion_access)' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
                SQL,
            ],
        ];
    }

    private function patch(string $from, string $to): void
    {
        $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [self::SIGNATURE])->d;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException(self::SIGNATURE.' no longer has exactly one clause this migration changes.');
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    public function up(): void
    {
        foreach ($this->replacements() as [$live, $replacement]) {
            $this->patch($live, $replacement);
        }
    }

    /** Restores WO-20's current-link reading; rollback runs only in local and testing (rule 143), production corrects forward. */
    public function down(): void
    {
        foreach (array_reverse($this->replacements()) as [$live, $replacement]) {
            $this->patch($replacement, $live);
        }
    }
};
