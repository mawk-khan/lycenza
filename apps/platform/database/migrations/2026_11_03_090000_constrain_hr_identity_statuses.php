<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * TCH.1 (ADR 0063 section 5): `employees.record_status` and
 * `employment_records.status` become authorization inputs (an
 * ActingEmployee needs an `active` Employee and an `active`/`notice_period`
 * EmploymentRecord), so their documented closed catalogues -- until now
 * application-validated strings only -- become database guarantees. No
 * value is added, removed or reinterpreted.
 *
 * Existing rows are never rewritten. Adding a CHECK makes PostgreSQL scan
 * every row, and that scan ignores row-level security, so it sees every
 * School's rows even where a count query run by a non-superuser migration
 * role would see none (both tables FORCE RLS). A row outside the list
 * therefore makes the migration refuse, naming the constraint, with
 * nothing changed -- the same refuse-never-repair rule as
 * school_memberships_status_check (Phase 0O.12B).
 *
 * down() drops both constraints and nothing else, restoring the prior
 * schema exactly.
 */
return new class extends Migration
{
    private const array CONSTRAINTS = [
        'employees' => [
            'employees_record_status_check',
            "record_status IN ('active', 'archived')",
        ],
        'employment_records' => [
            'employment_records_status_check',
            "status IN ('draft', 'pre_joining', 'active', 'notice_period', 'separated', 'terminated', 'retired', 'deceased')",
        ],
    ];

    public function up(): void
    {
        foreach (self::CONSTRAINTS as $table => [$name, $expression]) {
            try {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23514') {
                    throw new RuntimeException("{$name}: refusing -- {$table} holds at least one row outside the closed catalogue ({$expression}). No row was changed; correct the data deliberately first.", 0, $e);
                }

                throw $e;
            }
        }
    }

    public function down(): void
    {
        foreach (self::CONSTRAINTS as $table => [$name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
