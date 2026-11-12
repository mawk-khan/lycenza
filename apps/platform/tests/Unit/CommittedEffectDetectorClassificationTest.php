<?php

namespace Tests\Unit;

use App\Support\Idempotency\CommittedEffectDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR.R1-C30 (C14-N2): which statements CommittedEffectDetector treats as writes. A false "read" could release a
 * 5xx that followed a commit, so every doubtful shape is a write (review LOW-3).
 */
class CommittedEffectDetectorClassificationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function statements(): array
    {
        return [
            'plain select' => ['select * from "employees" where "id" = ?', false],
            'select for update' => ['select * from "employees" where "id" = ? for update', false],
            'select for no key update' => ['select * from employees where id = ? FOR NO KEY UPDATE', false],
            'select for share' => ['select 1 from schools where id = ? for share', false],
            'select with updated_at column' => ['select "updated_at", "deleted_at" from employees', false],
            'read cte' => ['with x as (select 1) select * from x', false],
            'show / set / reset / discard' => ['reset app.current_school_id', false],
            'set_config select' => ["select set_config('app.current_school_id', ?, false)", false],
            'plain explain' => ['explain select 1', false],
            'insert' => ['insert into "employee_notes" ("id") values (?)', true],
            'update' => ['UPDATE employees SET updated_at = now()', true],
            'delete' => ['delete from x where id = ?', true],
            'data-changing cte' => ['with d as (delete from x returning id) select * from d', true],
            'select into' => ['select * into new_table from employees', true],
            'two statements' => ['select 1; delete from employees', true],
            'explain analyze' => ['explain analyze delete from employees', true],
            'explain (analyze)' => ['explain (analyze, buffers) update employees set x = 1', true],
            'setval' => ["select setval('s', 5)", true],
            'large object' => ['select lo_unlink(?)', true],
            'notify' => ["notify c30, 'x'", true],
            'volatile public function' => ['select retention_hold_place(?, ?, ?, ?, ?)', true],
            'schema-qualified volatile function' => ['select public.retention_lock_rows(?, ?, ?)', true],
            'quoted volatile function' => ['select "retention_unlink_announcement_message"(?, ?)', true],
            'unknown verb' => ['vacuum employees', true],
            'leading parenthesis and whitespace' => ["  (\n select 1)", false],
        ];
    }

    #[DataProvider('statements')]
    #[Test]
    public function a_statement_is_a_write_unless_it_is_a_plain_read(string $sql, bool $write): void
    {
        $detector = app(CommittedEffectDetector::class);
        $detector->begin();
        try {
            $this->assertSame($write, $detector->isWrite($sql), $sql);
        } finally {
            $detector->end();
        }
    }
}
