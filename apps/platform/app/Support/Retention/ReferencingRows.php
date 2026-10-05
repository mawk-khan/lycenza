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
        // E21.2E (E21-D9): the Employee root, its employment evidence and its sub-records.
        'employees', 'employment_records', 'employee_assignments', 'employee_personal_details', 'employee_documents',
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications',
        'employee_experience_records', 'employee_certifications',
        'employee_compensation_assignments', 'employee_statutory_identifiers', 'employee_tax_profile',
        'employee_pf_status', 'employee_esi_coverage',
        // E21.3B: Student-linked evidence and operational module rows, and portal invitations.
        'student_processing_authorizations', 'admission_applications', 'applicants',
        'communication_domain_consent_events', 'communication_domain_preferences',
        'library_loans', 'transport_student_assignments', 'hostel_residency_assignments',
        'identity_account_invitations',
        // E21.3C: the Guardian root and its sub-records.
        'guardians', 'guardian_contacts', 'student_guardian_account_links',
        // E21.3D: year-bound academic operations.
        'curriculum_deliveries', 'attendance_sessions', 'timetable_entries', 'learning_content', 'assignments',
        // E21.3E: communication and platform residuals.
        'communication_announcements', 'communication_threads', 'transport_route_assignments', 'visitors', 'visitor_visits',
        'automation_executions',
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
     * E21-RH.6: as the retention identity (on its own connection) the same
     * question goes to the read-only database probe
     * `retention_first_reference()`, which applies exactly this rule -- that
     * identity reads none of the referencing tables itself.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $handled  tables the caller removes itself
     */
    public function first(string $parent, string $schoolId, array $ids, array $handled = []): ?string
    {
        if ($ids === []) {
            return null;
        }
        if (! in_array($parent, self::PARENTS, true)) {
            throw new InvalidArgumentException("Not a retention parent: {$parent}");
        }
        if (DB::connection()->getName() === RetentionExpiry::PRIVILEGED_CONNECTION) {
            $hit = DB::selectOne('SELECT retention_first_reference(?, ?, ?::uuid[], ?::text[]) AS t', [
                $parent, $schoolId, '{'.implode(',', $ids).'}', '{'.implode(',', array_map(fn (string $t) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $t).'"', $handled)).'}',
            ])->t;

            return $hit === null ? null : (string) $hit;
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
