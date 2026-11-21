<?php

namespace Tests\Concerns;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR.R1-C35 WO-26 (R1-06, rule 28, C38-N1): the raw cross-School checker for one tenant table, run on the
 * runtime connection (`pgsql`, `school_os_app`) with no Eloquent and no SchoolScope, so only PostgreSQL stands
 * between the statement and the rows. It returns violations (each naming table, context, operation, expected and
 * actual) instead of stopping at the first, so one run reports every broken table.
 *
 * The legs, for a table holding at least one row of School A:
 * - role: the connection is the runtime role, not superuser, not BYPASSRLS (a probe run as the owner proves nothing);
 * - positive control: in A's context A's rows are visible, and only A's (an empty fixture is a violation, never a pass);
 * - foreign read: in B's context no row of A is visible, by key or by count;
 * - no context: with the setting RESET, and with it empty, nothing is visible;
 * - foreign insert: in B's context a copy of A's row (new id) is refused. With the INSERT privilege the refusal
 *   must be RLS's WITH CHECK, unless the table's declared guard answers first; without it, "permission denied";
 * - foreign update: in B's context an UPDATE aimed at A's rows touches none; in A's context moving a row to B is
 *   refused by RLS or the declared guard. Without the UPDATE privilege, "permission denied";
 * - delete: without the DELETE privilege (every family table, R1-01), "permission denied" in both contexts; with it,
 *   none of A's rows from B;
 * - A's rows are unchanged afterwards.
 *
 * Every write runs in a savepoint and is rolled back. The policy catalogue (forced RLS, one permissive ALL policy
 * to PUBLIC, USING and WITH CHECK exactly `TenantRls::enable()`'s expression) is checked by `policyViolations()`.
 */
trait AssertsHrFamilyIsolation
{
    /** PostgreSQL 16's own deparse of `TenantRls::enable()`'s expression (`school_id = NULLIF(current_setting(...), '')::uuid`). */
    protected static string $canonicalTenantExpression = "(school_id = (NULLIF(current_setting('app.current_school_id'::text, true), ''::text))::uuid)";

    /** @var array<string, string> per "table leg": how the foreign write was refused (for the evidence record) */
    protected array $isolationEvidence = [];

    /** The connection every probe runs on. Only the checker's own self-test ever points it elsewhere. */
    protected string $isolationConnection = 'pgsql';

    private function runtime(): Connection
    {
        return DB::connection($this->isolationConnection);
    }

    private function useSchoolContext(?string $schoolId): void
    {
        if ($schoolId === null) {
            $this->runtime()->statement('RESET '.TenantRls::SESSION_VAR);

            return;
        }
        $this->runtime()->statement('SELECT set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /** @return list<string> */
    protected function roleViolations(): array
    {
        $role = $this->runtime()->selectOne('select current_user as u, r.rolsuper as su, r.rolbypassrls as by from pg_roles r where r.rolname = current_user');
        $out = [];
        if ($role->u !== 'school_os_app' || $role->su || $role->by) {
            $out[] = "connection: expected the runtime role school_os_app (no superuser, no BYPASSRLS); actual {$role->u} super=".var_export((bool) $role->su, true).' bypassrls='.var_export((bool) $role->by, true);
        }

        return $out;
    }

    /** @return list<string> forced RLS and exactly the canonical tenant policy */
    protected function policyViolations(string $table): array
    {
        $c = $this->runtime()->selectOne(
            "select c.relrowsecurity as rls, c.relforcerowsecurity as forced,
                    (select count(*) from information_schema.columns k where k.table_schema = 'public' and k.table_name = c.relname and k.column_name = 'school_id' and k.is_nullable = 'NO') as school_key
               from pg_class c where c.relname = ? and c.relnamespace = 'public'::regnamespace", [$table]);
        if ($c === null) {
            return ["{$table}: table not found"];
        }
        $out = [];
        if (! $c->rls) {
            $out[] = "{$table}: policy: expected RLS enabled; actual disabled";
        }
        if (! $c->forced) {
            $out[] = "{$table}: policy: expected FORCE ROW LEVEL SECURITY; actual not forced";
        }
        if ((int) $c->school_key !== 1) {
            $out[] = "{$table}: policy: expected a NOT NULL school_id; actual none";
        }
        $policies = $this->runtime()->select("select permissive, cmd, roles::text as roles, qual, with_check from pg_policies where schemaname = 'public' and tablename = ?", [$table]);
        if (count($policies) !== 1) {
            $out[] = "{$table}: policy: expected exactly one policy; actual ".count($policies);
        }
        foreach ($policies as $p) {
            if ($p->permissive !== 'PERMISSIVE' || $p->cmd !== 'ALL' || $p->roles !== '{public}') {
                $out[] = "{$table}: policy: expected PERMISSIVE ALL to public; actual {$p->permissive} {$p->cmd} {$p->roles}";
            }
            if ($p->qual !== self::$canonicalTenantExpression) {
                $out[] = "{$table}: policy USING: expected the tenant expression; actual ".($p->qual ?? 'NULL');
            }
            if ($p->with_check !== self::$canonicalTenantExpression) {
                $out[] = "{$table}: policy WITH CHECK: expected the tenant expression; actual ".($p->with_check ?? 'NULL');
            }
        }

        return $out;
    }

    /** @return list<string> every column a statement may supply: not generated, not an identity ALWAYS */
    private function insertableColumns(string $table): array
    {
        return array_map(fn ($r) => $r->attname, $this->runtime()->select(
            "select a.attname from pg_attribute a where a.attrelid = ?::regclass and a.attnum > 0 and not a.attisdropped
                and a.attgenerated = '' and a.attidentity <> 'a' order by a.attnum", ["public.{$table}"]));
    }

    private function privilege(string $table, string $privilege): bool
    {
        return (bool) $this->runtime()->selectOne('select has_table_privilege(current_user, ?, ?) as p', ["public.{$table}", $privilege])->p;
    }

    /** Runs $write in a savepoint that is always rolled back; returns the failure, or null when the statement succeeded. */
    private function attempt(callable $write): ?QueryException
    {
        $failure = null;
        try {
            $this->runtime()->transaction(function () use ($write): void {
                $write();
                throw new WriteSucceeded;
            });
        } catch (WriteSucceeded) {
            return null;
        } catch (QueryException $e) {
            $failure = $e;
        }

        return $failure;
    }

    private static function cause(QueryException $e): string
    {
        $message = (string) ($e->getPrevious()?->getMessage() ?? $e->getMessage());

        return $e->getCode().' '.trim(Str::before(Str::after($message, 'ERROR:'), "\n"));
    }

    /** A refusal for the expected School-boundary reason: RLS, or the table's declared guard. */
    private static function refusedFor(QueryException $e, string $rls, ?string $guard): bool
    {
        $cause = self::cause($e);

        return str_contains($cause, $rls) || ($guard !== null && preg_match($guard, $cause) === 1);
    }

    /**
     * @param  array{insert?: string, move?: string, born?: array<string, mixed>}  $guards  a regex per write leg for a declared
     *                                                                                      guard that answers before RLS; `born` resets a copied row to the state the table admits at insert
     * @return list<string>
     */
    protected function isolationViolations(string $table, string $schoolA, string $schoolB, array $guards = []): array
    {
        $db = $this->runtime();
        $out = [];
        $snapshot = function () use ($db, $table, $schoolA): string {
            $this->useSchoolContext($schoolA);

            return (string) $db->selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from {$table} t where school_id = ?", [$schoolA])->h;
        };

        // Positive control.
        $this->useSchoolContext($schoolA);
        $ownCount = (int) $db->selectOne("select count(*) as c from {$table} where school_id = ?", [$schoolA])->c;
        $visibleSchools = array_map(fn ($r) => $r->s, $db->select("select distinct school_id::text as s from {$table}"));
        if ($ownCount === 0) {
            $out[] = "{$table}: School A context: SELECT own rows: expected at least one fixture row; actual 0 (a vacuous fixture)";

            return $out;
        }
        if ($visibleSchools !== [$schoolA]) {
            $out[] = "{$table}: School A context: SELECT: expected only School A's rows; actual Schools ".implode(',', $visibleSchools);
        }
        $row = (array) $db->selectOne("select to_jsonb(t) as j from {$table} t where school_id = ? order by t::text limit 1", [$schoolA]);
        $copy = json_decode((string) $row['j'], true);
        $before = $snapshot();

        // Foreign read.
        $this->useSchoolContext($schoolB);
        $seen = (int) $db->selectOne("select count(*) as c from {$table} where school_id = ?", [$schoolA])->c;
        if ($seen !== 0) {
            $out[] = "{$table}: School B context: SELECT School A's rows: expected 0; actual {$seen}";
        }
        if (array_key_exists('id', $copy)) {
            $byKey = (int) $db->selectOne("select count(*) as c from {$table} where id::text = ?", [(string) $copy['id']])->c;
            if ($byKey !== 0) {
                $out[] = "{$table}: School B context: SELECT School A's row by id: expected 0; actual {$byKey}";
            }
        }

        // No context, reset and empty.
        foreach (['reset' => null, 'empty' => ''] as $label => $value) {
            if ($value === null) {
                $this->useSchoolContext(null);
            } else {
                $db->statement('SELECT set_config(?, ?, false)', [TenantRls::SESSION_VAR, $value]);
            }
            $none = (int) $db->selectOne("select count(*) as c from {$table}")->c;
            if ($none !== 0) {
                $out[] = "{$table}: no context ({$label}): SELECT: expected 0; actual {$none}";
            }
        }

        // Foreign insert: A's row, copied with a new id (and any declared reset to a born state), claimed from B's context.
        $insert = [...$copy, ...($guards['born'] ?? [])];
        if (array_key_exists('id', $insert)) {
            $insert['id'] = (string) Str::uuid7();
        }
        $columns = $this->insertableColumns($table);
        $list = implode(', ', array_map(fn (string $c) => '"'.$c.'"', $columns));
        $statement = "insert into {$table} ({$list}) select {$list} from jsonb_populate_record(null::{$table}, ?::jsonb)";
        $this->useSchoolContext($schoolB);
        $e = $this->attempt(fn () => $db->insert($statement, [json_encode($insert)]));
        if ($this->privilege($table, 'INSERT')) {
            if ($e === null) {
                $out[] = "{$table}: School B context: INSERT a School A row: expected refusal; actual accepted";
            } elseif (str_contains(self::cause($e), 'row-level security')) {
                $this->isolationEvidence["{$table} insert"] = 'RLS: '.self::cause($e);
            } elseif (self::refusedFor($e, 'row-level security', $guards['insert'] ?? null)) {
                $this->isolationEvidence["{$table} insert"] = 'declared evidence guard: '.self::cause($e);
            } else {
                // A guard answered first. The same row in School A's own context is the control: if the guard refuses
                // there too, its reason has nothing to do with the School and the probe never reached RLS.
                $this->useSchoolContext($schoolA);
                $control = $this->attempt(fn () => $db->insert($statement, [json_encode($insert)]));
                if ($control !== null && self::cause($control) === self::cause($e)) {
                    $out[] = "{$table}: School B context: INSERT a School A row: refused for a reason independent of the School (the same in School A's context), so RLS was never reached; actual ".self::cause($e);
                } else {
                    $this->isolationEvidence["{$table} insert"] = 'guard reading under RLS: '.self::cause($e).' | in its own School: '.($control === null ? 'accepted (rolled back)' : self::cause($control));
                }
            }
        } elseif ($e === null || ! str_contains(self::cause($e), 'permission denied')) {
            $out[] = "{$table}: runtime role without INSERT: expected permission denied; actual ".($e === null ? 'accepted' : self::cause($e));
        } else {
            $this->isolationEvidence["{$table} insert"] = 'no INSERT privilege: '.self::cause($e);
        }

        // Foreign update: aimed at A from B touches nothing; moving A's row to B is refused.
        if ($this->privilege($table, 'UPDATE')) {
            $this->useSchoolContext($schoolB);
            $touched = 0;
            $e = $this->attempt(function () use ($db, $table, $schoolA, &$touched): void {
                $touched = $db->update("update {$table} set school_id = school_id where school_id = ?", [$schoolA]);
            });
            if ($e !== null || $touched !== 0) {
                $out[] = "{$table}: School B context: UPDATE School A's rows: expected 0 rows; actual ".($e === null ? $touched : self::cause($e));
            }
            $this->useSchoolContext($schoolA);
            $where = array_key_exists('id', $copy) ? ['id::text = ?', (string) $copy['id']] : ['t::text = (select min(x::text) from '.$table.' x where x.school_id = ?)', $schoolA];
            $e = $this->attempt(fn () => $db->update("update {$table} t set school_id = ? where {$where[0]}", [$schoolB, $where[1]]));
            if ($e === null) {
                $out[] = "{$table}: School A context: UPDATE moving a row to School B: expected refusal; actual accepted";
            } elseif (! self::refusedFor($e, 'row-level security', $guards['move'] ?? null)) {
                $out[] = "{$table}: School A context: UPDATE moving a row to School B: expected an RLS (or declared guard) refusal; actual ".self::cause($e);
            } else {
                $this->isolationEvidence["{$table} move"] = (str_contains(self::cause($e), 'row-level security') ? 'RLS: ' : 'declared immutability guard: ').self::cause($e);
            }
        } else {
            $this->useSchoolContext($schoolA);
            $e = $this->attempt(fn () => $db->update("update {$table} set school_id = school_id where school_id = ?", [$schoolA]));
            if ($e === null || ! str_contains(self::cause($e), 'permission denied')) {
                $out[] = "{$table}: runtime role without UPDATE: expected permission denied; actual ".($e === null ? 'accepted' : self::cause($e));
            } else {
                $this->isolationEvidence["{$table} move"] = 'no UPDATE privilege: '.self::cause($e);
            }
        }

        // Delete: the runtime role holds no DELETE on a family table (R1-01), so both contexts are a privilege refusal;
        // a table that did grant it would have to touch none of A's rows from B.
        if ($this->privilege($table, 'DELETE')) {
            $this->useSchoolContext($schoolB);
            $deleted = 0;
            $e = $this->attempt(function () use ($db, $table, $schoolA, &$deleted): void {
                $deleted = $db->delete("delete from {$table} where school_id = ?", [$schoolA]);
            });
            if ($e !== null || $deleted !== 0) {
                $out[] = "{$table}: School B context: DELETE School A's rows: expected 0 rows; actual ".($e === null ? $deleted : self::cause($e));
            } else {
                $this->isolationEvidence["{$table} delete"] = 'RLS: 0 rows from the other School';
            }
        } else {
            foreach (['School B' => $schoolB, 'School A' => $schoolA] as $label => $context) {
                $this->useSchoolContext($context);
                $e = $this->attempt(fn () => $db->delete("delete from {$table} where school_id = ?", [$schoolA]));
                if ($e === null || ! str_contains(self::cause($e), 'permission denied')) {
                    $out[] = "{$table}: {$label} context: DELETE without the privilege: expected permission denied; actual ".($e === null ? 'accepted' : self::cause($e));
                }
            }
            $this->isolationEvidence["{$table} delete"] = 'no DELETE privilege';
        }

        if ($snapshot() !== $before) {
            $out[] = "{$table}: School A's rows changed during the probes";
        }
        $this->useSchoolContext(null);

        return $out;
    }
}

/** @internal a write that went through, rolled back on purpose */
final class WriteSucceeded extends \RuntimeException {}
