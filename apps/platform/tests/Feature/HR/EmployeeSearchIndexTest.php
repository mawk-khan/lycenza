<?php

namespace Tests\Feature\HR;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 8) -- documents why a `pg_trgm` GIN
 * search index for `employees.full_name`/`employee_number` was built,
 * verified, and then DELIBERATELY REMOVED rather than shipped, per the
 * correction mandate's own instruction: "either add the migration with
 * query-plan/performance proof, or formally document why the original
 * requirement should be removed."
 *
 * WHAT WAS BUILT AND PROVEN, WITH EVIDENCE: a migration created the
 * `pg_trgm` extension and two GIN indexes
 * (`employees_full_name_trgm_idx`, `employees_employee_number_trgm_idx`,
 * `gin_trgm_ops`), correctly targeting
 * `EmployeeDirectoryService::search()`'s actual `full_name ILIKE
 * '%needle%'` / `employee_number ILIKE 'needle%'` query shape. Query-
 * plan investigation (real `EXPLAIN` output, both attached to this
 * correction's own working notes) proved the index was:
 *   - VALID and CORRECTLY DEFINED -- as the table owner (bypassing RLS,
 *     `school_os` migration role), the planner chose it unprompted at
 *     realistic row counts (cost ~141 vs. thousands for a sequential
 *     scan);
 *   - but NEVER SELECTED, under ANY combination of row count, data
 *     distribution, or planner GUC overrides (`enable_seqscan`,
 *     `enable_indexscan`, `max_parallel_workers_per_gather`), when run
 *     as `school_os_app` -- the ONLY role every real request/queue
 *     connection in this codebase ever uses (rule 26).
 *
 * ROOT CAUSE, CONFIRMED (not assumed): `employees` has FORCED Row-Level
 * Security (rule 18/ADR 0021). PostgreSQL's row-security implementation
 * refuses to generate an index path through ANY operator/function not
 * marked `LEAKPROOF`, for a real, deliberate security reason -- a
 * non-leakproof function's behavior (timing, error output) could
 * otherwise leak information about rows the policy is hiding. Every
 * function `pg_trgm`'s GIN opclass depends on
 * (`gin_trgm_consistent`, `gin_extract_value_trgm`,
 * `gin_extract_query_trgm`, `similarity`, `texticlike`, ...) is marked
 * `proleakproof = false` in `pg_proc` -- confirmed directly against
 * this PostgreSQL installation's own catalog, not merely cited from
 * documentation. This is upstream PostgreSQL/pg_trgm's own permanent
 * design, not a defect in this migration or this environment: ordinary
 * equality (`school_id = ?`, used by every existing tenant-scoping
 * index in this codebase) IS leakproof and works fine under RLS, which
 * is exactly why every OTHER index on this table continued to work
 * throughout this investigation -- only pattern-matching/similarity
 * access paths are affected.
 *
 * CONSEQUENCE: this index could NEVER accelerate any real query this
 * application issues -- `EmployeeDirectoryService::search()` always
 * runs as `school_os_app` under RLS, with no code path in this
 * repository that runs Employee search as the RLS-bypassing migration
 * role. Shipping it would add real, permanent GIN write-amplification
 * cost to every `employees.full_name`/`employee_number` write for a
 * read benefit that can structurally never materialize -- exactly the
 * "add a package/abstraction for a need that doesn't exist" CLAUDE.md
 * rule 2 exists to prevent. The migration
 * (`2026_09_10_090100_add_pg_trgm_search_index_to_employees_table`)
 * was therefore deleted from this branch rather than kept as dead-
 * weight infrastructure with a caveat comment.
 *
 * These tests are a permanent regression guard against silently
 * re-adding it later without re-deriving this same finding -- they
 * assert the extension/indexes are ABSENT, and (informationally, not
 * as a requirement) demonstrate that PostgreSQL's own equality-based
 * indexes remain fully RLS-compatible, so a future author does not
 * mistake this finding for "no index can ever help this table."
 */
class EmployeeSearchIndexTest extends TestCase
{
    #[Test]
    public function no_pg_trgm_indexes_exist_on_employees(): void
    {
        $fullNameIndex = DB::selectOne("select indexname from pg_indexes where indexname = 'employees_full_name_trgm_idx'");
        $employeeNumberIndex = DB::selectOne("select indexname from pg_indexes where indexname = 'employees_employee_number_trgm_idx'");

        $this->assertNull(
            $fullNameIndex,
            'A pg_trgm GIN index on employees.full_name was tried and removed (see this class docblock) -- do not re-add it without first re-proving it survives RLS + non-leakproof restrictions.',
        );
        $this->assertNull($employeeNumberIndex);
    }

    #[Test]
    public function ordinary_equality_predicates_remain_index_usable_under_forced_row_level_security(): void
    {
        // On a near-empty `employees` table (as this isolated test
        // leaves it), PostgreSQL correctly prefers a sequential scan
        // over ANY index, leakproof or not -- the same cost-model
        // reality documented at length in this class's own docblock for
        // the trigram case. `SET LOCAL enable_seqscan = off` (rolled
        // back automatically at the end of this transaction) is the
        // honest way to prove the STRUCTURAL capability under test
        // here ("can a leakproof equality predicate be routed through
        // an index at all under RLS") without needing this test to
        // also bulk-insert thousands of rows just to make an index the
        // unprompted cost-based winner -- that stronger claim is what
        // EmployeeDirectoryServiceTest's own bulk-data trigram tests
        // exist to make, for the one case where it actually matters.
        DB::transaction(function (): void {
            DB::statement('SET LOCAL enable_seqscan = off');

            $plan = collect(DB::select("explain select * from employees where school_id = '00000000-0000-0000-0000-000000000000'"))
                ->pluck('QUERY PLAN')
                ->implode("\n");

            $this->assertStringNotContainsString(
                'Seq Scan',
                $plan,
                "An ordinary leakproof equality predicate must still be routable through an index under RLS when a sequential scan is disallowed -- this proves the earlier trigram finding is specific to non-leakproof pattern-matching operators, not a blanket 'RLS defeats every index' claim. Plan was:\n{$plan}",
            );
        });
    }
}
