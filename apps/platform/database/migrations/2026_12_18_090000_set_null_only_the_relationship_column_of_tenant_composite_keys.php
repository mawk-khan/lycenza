<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.1A (ADR 0076 §24, V-5): five composite same-School foreign keys
 * `(relationship_id, school_id) -> parent(id, school_id)` were declared
 * `ON DELETE SET NULL`. PostgreSQL's plain SET NULL nulls EVERY referencing
 * column, including the NOT NULL tenant key `school_id`, so deleting the
 * parent failed instead of clearing the optional relationship as each
 * table's docblock intends (e.g. "losing its Campus should demote a
 * Department back to School-wide").
 *
 * Each constraint is rebuilt under the SAME name with PostgreSQL 15+'s
 * column list, `ON DELETE SET NULL (relationship_id)`: the child row
 * survives, `school_id` never changes, and the composite same-School check
 * is exactly as strong as before. Existing rows are valid by construction
 * (the constraint already held); re-adding it re-validates them.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> table, constraint, nullable relationship column, parent table */
    private const KEYS = [
        ['hr_departments', 'hr_departments_campus_id_school_id_foreign', 'campus_id', 'campuses'],
        ['hr_departments', 'hr_departments_parent_department_id_school_id_foreign', 'parent_department_id', 'hr_departments'],
        ['employee_assignments', 'employee_assignments_manager_assignment_id_school_id_foreign', 'manager_assignment_id', 'employee_assignments'],
        ['communication_messages', 'communication_messages_reply_to_message_id_school_id_foreign', 'reply_to_message_id', 'communication_messages'],
        ['communication_attachments', 'comm_attachments_message_school_foreign', 'communication_message_id', 'communication_messages'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $constraint, $column, $parent]) {
            $this->rebuild($table, $constraint, $column, $parent, "SET NULL ({$column})");
        }
    }

    /** Restores the previous whole-key SET NULL definitions exactly. */
    public function down(): void
    {
        foreach (self::KEYS as [$table, $constraint, $column, $parent]) {
            $this->rebuild($table, $constraint, $column, $parent, 'SET NULL');
        }
    }

    private function rebuild(string $table, string $constraint, string $column, string $parent, string $onDelete): void
    {
        DB::statement(
            "ALTER TABLE {$table} DROP CONSTRAINT {$constraint}, "
            ."ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}, school_id) "
            ."REFERENCES {$parent} (id, school_id) ON DELETE {$onDelete}"
        );
    }
};
