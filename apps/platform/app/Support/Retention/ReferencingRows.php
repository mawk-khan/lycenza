<?php

namespace App\Support\Retention;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E21.2D: which other tables still hold rows that reference given parent
 * rows, read from the live foreign-key catalog (`pg_constraint`), never
 * from a hand-maintained list.
 *
 * A retention purge removes only the dependents it explicitly handles. Any
 * other referencing row, in any table, is a dependency the purge must not
 * cascade away: the purge treats it as `dependency_blocked` and keeps the
 * parent. A table added later that references a parent therefore blocks
 * purges until a checkpoint classifies it (fail-closed by construction).
 *
 * The parents form a closed list (no caller-supplied table name). The
 * referencing tables and columns come only from the catalog, and every
 * identifier is quoted. Queries run under the caller's tenant context.
 */
final class ReferencingRows
{
    /** Parents a retention purge may ask about. */
    public const PARENTS = [
        'students', 'student_enrollments', 'student_subject_enrollments', 'student_guardian_relationships',
        'attendance_records', 'enrollment_rollover_items', 'documents',
    ];

    /** @var array<string, list<array{table: string, column: string, tenant: bool}>> */
    private array $references = [];

    /**
     * Every (table, column) whose foreign key points at `$parent.id`, and
     * whether that table carries `school_id`.
     *
     * @return list<array{table: string, column: string, tenant: bool}>
     */
    public function to(string $parent): array
    {
        if (! in_array($parent, self::PARENTS, true)) {
            throw new InvalidArgumentException("Not a retention parent: {$parent}");
        }

        return $this->references[$parent] ??= array_map(
            fn (object $row): array => ['table' => $row->tbl, 'column' => $row->col, 'tenant' => (bool) $row->tenant],
            DB::select(
                "SELECT DISTINCT c.conrelid::regclass::text AS tbl, a.attname AS col,
                        EXISTS (SELECT 1 FROM pg_attribute s WHERE s.attrelid = c.conrelid AND s.attname = 'school_id' AND NOT s.attisdropped) AS tenant
                   FROM pg_constraint c
                   CROSS JOIN LATERAL unnest(c.conkey, c.confkey) AS k(src, dst)
                   JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.src
                   JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.dst
                  WHERE c.contype = 'f' AND c.confrelid = ?::regclass AND fa.attname = 'id'
                  ORDER BY 1, 2",
                ['public.'.$parent],
            ),
        );
    }

    /**
     * The first referencing table (excluding `$handled`) that still holds a
     * row of `$schoolId` pointing at any of `$ids`, or null when none does.
     * Only existence decides, so it stops at the first hit. The explicit
     * `school_id` predicate lets the `(school_id, ...)` indexes serve it.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $handled  tables the caller removes itself
     */
    public function first(string $parent, string $schoolId, array $ids, array $handled = []): ?string
    {
        if ($ids === []) {
            return null;
        }

        foreach ($this->to($parent) as $reference) {
            if (in_array($reference['table'], $handled, true)) {
                continue;
            }

            $table = '"'.str_replace('"', '""', $reference['table']).'"';
            $column = '"'.str_replace('"', '""', $reference['column']).'"';
            $bindings = ['{'.implode(',', $ids).'}'];
            $tenant = '';
            if ($reference['tenant']) {
                $tenant = ' AND school_id = ?';
                $bindings[] = $schoolId;
            }

            if (DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$table} WHERE {$column} = ANY (?::uuid[]){$tenant}) AS hit", $bindings)->hit) {
                return $reference['table'];
            }
        }

        return null;
    }
}
