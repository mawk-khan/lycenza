<?php

namespace Tests\Feature\Postgres;

use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationExecutionAttempt;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0L.6 -- the four Automation tables at the raw-SQL/RLS layer on the
 * runtime `pgsql` connection (CLAUDE.md rules 18, 28, 70): RLS enabled and
 * forced, per-School visibility, nothing without context, composite
 * foreign keys refusing a cross-School reference, and append-only attempts
 * and review items.
 */
class AutomationRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const TABLES = ['automation_rule_instances', 'automation_executions', 'automation_execution_attempts', 'automation_review_items'];

    /** @return array{instance: AutomationRuleInstance, execution: AutomationExecution} */
    private function seedSchool(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school): array {
            $owner = $this->createUser();
            $instance = AutomationRuleInstance::query()->create([
                'school_id' => $school->id, 'rule_type' => 'academic_year.setup_review', 'status' => 'enabled',
                'owner_user_id' => $owner->id, 'enabled_at' => now(),
            ]);
            $execution = AutomationExecution::query()->create([
                'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => (string) Str::uuid7(),
                'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
                'subject_id' => (string) Str::uuid7(), 'status' => 'succeeded', 'attempts' => 1,
            ]);
            AutomationExecutionAttempt::query()->create([
                'school_id' => $school->id, 'execution_id' => $execution->id, 'attempt_number' => 1,
                'outcome' => 'succeeded', 'outcome_code' => 'review_item_created', 'finished_at' => now(),
            ]);
            AutomationReviewItem::query()->create([
                'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'execution_id' => $execution->id,
                'item_type' => 'academic_year_setup_review', 'subject_type' => 'academic_year', 'subject_id' => $execution->subject_id,
            ]);

            return ['instance' => $instance, 'execution' => $execution];
        });
    }

    private function useContext(?School $school): void
    {
        if ($school === null) {
            DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

            return;
        }
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
    }

    #[Test]
    public function every_automation_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                "select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
                [$table],
            );
            $this->assertTrue($row->relrowsecurity, "{$table} RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} RLS forced");
        }
    }

    #[Test]
    public function raw_reads_see_only_the_context_school_and_nothing_without_context(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->seedSchool($a);
        $this->seedSchool($b);

        foreach (self::TABLES as $table) {
            $this->useContext($a);
            $schools = array_unique(array_column(DB::connection('pgsql')->select("select school_id from {$table}"), 'school_id'));
            $this->assertSame([$a->id], array_values($schools), "{$table} under A's context");
            $this->assertSame(0, (int) DB::connection('pgsql')->selectOne("select count(*) as c from {$table} where school_id = ?", [$b->id])->c, "{$table}: B invisible to A");

            $this->useContext(null);
            $this->assertSame(0, (int) DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c, "{$table} without context");
        }
    }

    #[Test]
    public function a_row_can_never_reference_another_schools_rule_instance_or_execution(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $bRows = $this->seedSchool($b);
        // A School B execution with no review item yet, so the item insert
        // below meets the foreign key rather than UNIQUE(execution_id).
        $bFreeExecution = app(TenantContext::class)->withSchool($b, fn () => AutomationExecution::query()->create([
            'school_id' => $b->id, 'rule_instance_id' => $bRows['instance']->id, 'trigger_key' => (string) Str::uuid7(),
            'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
            'subject_id' => (string) Str::uuid7(), 'status' => 'pending',
        ]));

        // Structural, not just RLS: the composite (id, school_id) foreign
        // keys reject a School A row pointing at School B's parent even when
        // inserted under A's own context.
        $this->useContext($a);
        foreach ([
            fn () => DB::connection('pgsql')->insert(
                "insert into automation_executions (id, school_id, rule_instance_id, trigger_key, trigger_event_type, subject_type, subject_id, status, attempts, created_at, updated_at) values (?, ?, ?, 'forged', 'academic_year.activated.v1', 'academic_year', ?, 'pending', 0, now(), now())",
                [(string) Str::uuid7(), $a->id, $bRows['instance']->id, (string) Str::uuid7()],
            ),
            fn () => DB::connection('pgsql')->insert(
                "insert into automation_review_items (id, school_id, rule_instance_id, execution_id, item_type, subject_type, subject_id, created_at) values (?, ?, ?, ?, 'academic_year_setup_review', 'academic_year', ?, now())",
                [(string) Str::uuid7(), $a->id, $bRows['instance']->id, $bFreeExecution->id, (string) Str::uuid7()],
            ),
            fn () => DB::connection('pgsql')->insert(
                "insert into automation_execution_attempts (id, school_id, execution_id, attempt_number, outcome, finished_at, created_at) values (?, ?, ?, 9, 'failed', now(), now())",
                [(string) Str::uuid7(), $a->id, $bRows['execution']->id],
            ),
        ] as $i => $insert) {
            try {
                DB::connection('pgsql')->transaction($insert);
                $this->fail("Cross-School insert #{$i} was accepted.");
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23503', '42501'], "Insert #{$i}: foreign-key or RLS rejection, got {$e->getCode()}");
            }
        }

        // And a School A row cannot be written with School B's id.
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->insert(
                "insert into automation_rule_instances (id, school_id, rule_type, status, created_at, updated_at) values (?, ?, 'academic_year.setup_review', 'disabled', now(), now())",
                [(string) Str::uuid7(), $b->id],
            ));
            $this->fail('An insert for another School was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        }
    }

    #[Test]
    public function attempts_and_review_items_are_append_only_for_the_runtime_role(): void
    {
        $a = $this->createSchool();
        $this->seedSchool($a);
        $this->useContext($a);

        foreach (['automation_execution_attempts', 'automation_review_items'] as $table) {
            foreach (["update {$table} set subject_type = subject_type", "delete from {$table}"] as $sql) {
                $sql = $table === 'automation_execution_attempts' ? str_replace('subject_type = subject_type', 'outcome = outcome', $sql) : $sql;
                try {
                    DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement($sql));
                    $this->fail("{$sql} was allowed.");
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->getCode(), $sql);
                }
            }
        }
    }
}
