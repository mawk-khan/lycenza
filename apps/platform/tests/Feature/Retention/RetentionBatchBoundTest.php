<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C19 (C15-N1): a batched retention expiry deletes at most its batch per call, and a run
 * deletes every eligible row it can lock.
 *
 * The 14 batched retention functions delete `WHERE id IN (SELECT ... ORDER BY id LIMIT n FOR UPDATE
 * SKIP LOCKED)`. PostgreSQL may plan that subquery as the rescanned inner side of a semi join: every
 * rescan skips the rows the statement has already deleted and locks the next ones, so one call
 * deleted more than n. `RetentionExpiry::run()` continues only on a full batch, so the overshoot
 * ended the run with eligible rows left (CI: "Deleted 4" of 5, held 0). Whether the planner chose
 * that shape depended on table statistics, which is why CI failed and local runs passed.
 *
 * These tests force the rescanning shape on the retention session, so the outcome no longer depends
 * on statistics: with the batch evaluated once (an InitPlan), the bound holds under any plan.
 */
class RetentionBatchBoundTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    /** The planner settings under which the batch subquery is a rescanned semi-join inner (verified on PostgreSQL 16). */
    private const RESCAN_PLAN = [
        'plan_cache_mode' => 'force_custom_plan',
        'enable_hashjoin' => 'off',
        'enable_mergejoin' => 'off',
        'enable_material' => 'off',
        'enable_memoize' => 'off',
        'enable_hashagg' => 'off',
        'enable_sort' => 'off',
    ];

    private const CUTOFF = '2017-06-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));
        config(['retention.audit_years' => 7, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false, 'retention.erasure_case_years' => null]);
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getName() === RetentionExpiry::PRIVILEGED_CONNECTION) {
                foreach (self::RESCAN_PLAN as $setting => $value) {
                    $event->connection->statement("SET {$setting} = '{$value}'");
                }
            }
        });
        DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
    }

    /**
     * Inserts $count old audit rows. Ids ascend, but the highest is written right after the first
     * $batch rows: under the rescanning plan that physical order made the first call delete more than
     * a batch yet not everything, the CI symptom (5 rows, batch 2: "Deleted 4", the highest id left).
     */
    private function audits(School $school, int $count, int $batch = 2): void
    {
        $ids = collect()->times($count, fn () => (string) Str::uuid7())->sort()->values()->all();
        if ($count > $batch) {
            $ids = [...array_slice($ids, 0, $batch), $ids[$count - 1], ...array_slice($ids, $batch, $count - $batch - 1)];
        }
        foreach ($ids as $i => $id) {
            app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
                'id' => $id, 'school_id' => $school->id, 'occurred_at' => '2015-01-0'.(($i % 9) + 1).' 00:00:00',
                'event_type' => 'test.event', 'metadata' => '{}', 'created_at' => '2015-01-01 00:00:00',
            ]));
        }
    }

    private function remaining(School $school): int
    {
        return DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $school->id)->count();
    }

    private function expire(School $school, int $batch): array
    {
        return app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, Carbon::parse(self::CUTOFF, 'UTC'), $batch, false);
    }

    /** One call of the function itself, as the retention identity in the School's context. */
    private function oneCall(School $school, int $limit): int
    {
        return DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => app(TenantContext::class)->withSchool($school, fn () => (int) DB::selectOne(
            'SELECT public.retention_expire_school_audit_events(?, ?, ?, false) AS n', [$school->id, self::CUTOFF, $limit],
        )->n));
    }

    #[Test]
    public function the_forced_settings_produce_the_rescanning_shape(): void
    {
        $school = $this->createSchool();
        $this->audits($school, 5);

        // The retention role reads no table directly; the same settings on an owner session, rolled back.
        $owner = DB::connection('pgsql_admin');
        $plan = $owner->transaction(function () use ($owner, $school): string {
            foreach (self::RESCAN_PLAN as $setting => $value) {
                $owner->statement("SET LOCAL {$setting} = '{$value}'");
            }

            return collect($owner->select(
                'EXPLAIN (COSTS OFF) DELETE FROM public.school_audit_events e WHERE e.id IN (SELECT id FROM public.school_audit_events WHERE school_id = ? ORDER BY id LIMIT 2 FOR UPDATE SKIP LOCKED) AND e.school_id = ?',
                [$school->id, $school->id],
            ))->pluck('QUERY PLAN')->implode("\n");
        });

        $this->assertStringContainsString('Nested Loop Semi Join', $plan, 'the forced settings reproduce the shape that overshot in CI');
    }

    #[Test]
    public function one_call_never_deletes_more_than_its_batch(): void
    {
        $school = $this->createSchool();
        $this->audits($school, 5);

        $this->assertSame(2, $this->oneCall($school, 2));
        $this->assertSame(3, $this->remaining($school));
    }

    /** @return array<string, array{int, int}> rows, batch */
    public static function boundaries(): array
    {
        return [
            'none' => [0, 3], 'one' => [1, 3], 'batch minus one' => [2, 3], 'exactly one batch' => [3, 3],
            'batch plus one' => [4, 3], 'two full batches' => [6, 3], 'partial last batch' => [7, 3], 'batch of one' => [5, 1],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function a_run_deletes_every_eligible_row_at_every_batch_boundary(int $rows, int $batch): void
    {
        $school = $this->createSchool();
        $this->audits($school, $rows, $batch);

        $this->assertSame(['eligible' => $rows, 'deleted' => $rows, 'held' => 0], $this->expire($school, $batch));
        $this->assertSame(0, $this->remaining($school));
        $this->assertSame(['eligible' => 0, 'deleted' => 0, 'held' => 0], $this->expire($school, $batch), 'a second run finds nothing');
    }

    #[Test]
    public function ineligible_rows_and_other_schools_are_untouched(): void
    {
        [$a, $b] = [$this->createSchool(), $this->createSchool()];
        $this->audits($a, 4);
        $this->audits($b, 3);
        app(TenantContext::class)->withSchool($a, fn () => DB::table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $a->id, 'occurred_at' => '2020-01-01 00:00:00', 'event_type' => 'test.young', 'metadata' => '{}', 'created_at' => '2020-01-01 00:00:00',
        ]));

        $this->assertSame(['eligible' => 4, 'deleted' => 4, 'held' => 0], $this->expire($a, 2));
        $this->assertSame([1, 3], [$this->remaining($a), $this->remaining($b)], 'the young row and the other School stay');
    }

    #[Test]
    public function a_held_school_keeps_every_row(): void
    {
        $school = $this->createSchool();
        $this->audits($school, 5);
        config(['retention.hold_school_ids' => [$school->id]]);

        $this->assertSame(['eligible' => 5, 'deleted' => 0, 'held' => 5], $this->expire($school, 2));
        $this->assertSame(5, $this->remaining($school));
    }

    #[Test]
    public function a_row_locked_by_another_session_is_skipped_and_the_rest_are_deleted(): void
    {
        $school = $this->createSchool();
        $this->audits($school, 5);
        // The dry run counts all five, and (test harness) aligns their retention anchors before the lock is taken.
        $this->assertSame(['eligible' => 5, 'deleted' => 0, 'held' => 0], app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, Carbon::parse(self::CUTOFF, 'UTC'), 2, true));
        $locked = DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $school->id)->orderBy('id')->value('id');
        config(['database.connections.c19_holder' => config('database.connections.pgsql_admin')]);
        /** @var Connection $holder */
        $holder = DB::connection('c19_holder');
        $holder->beginTransaction();
        try {
            $holder->select('SELECT id FROM school_audit_events WHERE id = ? FOR UPDATE', [$locked]);

            $this->assertSame(['eligible' => 5, 'deleted' => 4, 'held' => 0], $this->expire($school, 2), 'the run ends; the locked row is the only one left');
        } finally {
            $holder->rollBack();
            DB::purge('c19_holder');
        }
        $this->assertSame(1, $this->remaining($school));
        $this->assertSame(['eligible' => 1, 'deleted' => 1, 'held' => 0], $this->expire($school, 2), 'released, the next run takes it');
    }

    #[Test]
    public function the_command_reports_every_row_it_deleted(): void
    {
        config(['retention.batch_size' => 2]);
        $school = $this->createSchool();
        $this->audits($school, 5);

        $this->assertSame(0, Artisan::call('platform:audit-prune'));
        $output = Artisan::output();
        $this->assertStringContainsString('Deleted 5 School', $output, $output);
        $this->assertSame(0, $this->remaining($school));
    }

    #[Test]
    public function no_batched_retention_function_deletes_through_a_rescannable_limit(): void
    {
        $functions = collect(DB::connection('pgsql_admin')->select(
            "SELECT p.proname AS name, pg_get_functiondef(p.oid) AS body FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.prokind = 'f' AND p.proname LIKE 'retention_expire_%'",
        ));

        $batched = $functions->filter(fn ($f) => preg_match('/LIMIT[^;]*FOR UPDATE( OF \w+)? SKIP LOCKED/i', $f->body) === 1);
        $this->assertCount(14, $batched, 'the inventory of batched retention functions (C19)');
        // Every retention function, not only the selected ones: a future lock spelling (FOR NO KEY UPDATE, OF a, b) still cannot hide one.
        foreach ($functions as $function) {
            $this->assertDoesNotMatchRegularExpression('/\bIN\s*\(\s*SELECT [^;]*LIMIT/i', $function->body, "{$function->name}: a LIMIT subquery under IN can be rescanned");
        }
        foreach ($batched as $function) {
            $this->assertStringContainsString('= ANY (ARRAY(SELECT', $function->body, "{$function->name}: the batch is evaluated once");
        }
    }
}
