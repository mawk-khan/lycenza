<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C19 (C15-N1): a batched retention expiry deletes at most its batch per call.
 *
 * The 14 batched functions deleted `WHERE x.id IN (SELECT ... ORDER BY id LIMIT n FOR UPDATE [OF a]
 * SKIP LOCKED)`. PostgreSQL may run that subquery as the rescanned inner side of a semi join (it does,
 * for a custom plan, when the School's rows look selective). Each rescan skips the rows the
 * statement already deleted and locks the next ones, so one call deleted more than n, and
 * `RetentionExpiry::run()`, which continues only on a full batch, then stopped with eligible rows
 * left. Only eligible rows were ever deleted: the outer predicates re-apply.
 *
 * `x.id = ANY (ARRAY(SELECT ...))` is an uncorrelated sublink, an InitPlan evaluated once per
 * statement, so the locked batch is fixed before any row is deleted. Every predicate, hold, anchor
 * and identity check is unchanged. Patched in place against each live body (rule 146): the
 * functions keep their owner, grants, SECURITY DEFINER and search_path.
 */
return new class extends Migration
{
    private const FUNCTIONS = [
        'public.retention_expire_api_client_credentials(uuid,timestamp,integer,boolean)',
        'public.retention_expire_cancelled_communication_events(uuid,timestamp,integer,boolean)',
        'public.retention_expire_communication_delivery_policy_decisions(uuid,timestamp,integer,boolean)',
        'public.retention_expire_communication_events(uuid,date,integer,boolean)',
        'public.retention_expire_elective_teaching_assignments(uuid,date,integer,boolean)',
        'public.retention_expire_erasure_cases(timestamp,integer,boolean)',
        'public.retention_expire_group_role_assignments(timestamp,integer,boolean)',
        'public.retention_expire_membership_role_assignments(uuid,timestamp,integer,boolean)',
        'public.retention_expire_platform_audit_events(timestamp,integer,boolean)',
        'public.retention_expire_platform_role_assignments(timestamp,integer,boolean)',
        'public.retention_expire_released_email_suppressions(timestamp,integer,boolean)',
        'public.retention_expire_school_audit_events(uuid,timestamp,integer,boolean)',
        'public.retention_expire_school_elevations(uuid,timestamp,integer,boolean)',
        'public.retention_expire_teaching_assignments(uuid,date,integer,boolean)',
    ];

    public function up(): void
    {
        foreach (self::FUNCTIONS as $function) {
            $this->patch($function, [
                ['/\b(\w+)\.id IN \(SELECT /', '$1.id = ANY (ARRAY(SELECT '],
                ['/FOR UPDATE( OF \w+)? SKIP LOCKED\)/', 'FOR UPDATE$1 SKIP LOCKED))'],
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::FUNCTIONS as $function) {
            $this->patch($function, [
                ['/\b(\w+)\.id = ANY \(ARRAY\(SELECT /', '$1.id IN (SELECT '],
                ['/FOR UPDATE( OF \w+)? SKIP LOCKED\)\)/', 'FOR UPDATE$1 SKIP LOCKED)'],
            ]);
        }
    }

    /** @param  list<array{0: string, 1: string}>  $replacements  each must match exactly once */
    private function patch(string $function, array $replacements): void
    {
        $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [$function])->d;
        foreach ($replacements as [$pattern, $replacement]) {
            if (preg_match_all($pattern, $definition) !== 1) {
                throw new RuntimeException("{$function}: unexpected body shape; refusing to change it.");
            }
            $definition = (string) preg_replace($pattern, $replacement, $definition);
        }
        DB::unprepared($definition);
    }
};
