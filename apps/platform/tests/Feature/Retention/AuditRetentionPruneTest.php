<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2B (E21-D1): `platform:audit-prune`, seven calendar years after
 * `occurred_at`, through the narrow retention functions. The fixed clocks are
 * in the past, so the database age floor (real now() minus 7 years) always
 * admits them.
 */
class AuditRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));
        config(['retention.audit_years' => 7, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false, 'retention.batch_size' => 500]);
    }

    private function schoolAudit(School $school, string $occurredAt, string $type = 'test.event'): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => $id, 'school_id' => $school->id, 'occurred_at' => $occurredAt, 'event_type' => $type, 'metadata' => '{}', 'created_at' => $occurredAt,
        ]));

        return $id;
    }

    private function platformAudit(string $occurredAt): string
    {
        $id = (string) Str::uuid7();
        DB::table('platform_audit_events')->insert(['id' => $id, 'occurred_at' => $occurredAt, 'event_type' => 'test.platform', 'metadata' => '{}', 'created_at' => $occurredAt]);

        return $id;
    }

    private function schoolExists(School $school, string $id): bool
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('id', $id)->exists());
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_an_invalid_period_fails(): void
    {
        $school = $this->createSchool();
        $old = $this->schoolAudit($school, '2010-01-01 00:00:00');

        config(['retention.audit_years' => null]);
        $this->artisan('platform:audit-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        config(['retention.audit_years' => '0']);
        $this->artisan('platform:audit-prune')->assertFailed();
        // A period shorter than the adopted floor is refused by the database
        // (inside a savepoint, so the test's own transaction survives).
        config(['retention.audit_years' => 2]);
        try {
            DB::transaction(fn () => $this->artisan('platform:audit-prune')->run());
            $this->fail('a two-year audit period must be refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('retention_floor', $e->getMessage());
        }

        $this->assertTrue($this->schoolExists($school, $old));
    }

    #[Test]
    public function seven_calendar_years_after_occurred_at_with_a_strict_boundary(): void
    {
        // now 2024-06-15 12:00 UTC => cutoff 2017-06-15 12:00:00.
        $school = $this->createSchool();
        $exactly = $this->schoolAudit($school, '2017-06-15 12:00:00');
        $older = $this->schoolAudit($school, '2017-06-15 11:59:59');
        // Employee link history lives only in audit (E21-D6 through D1).
        $oldLink = $this->schoolAudit($school, '2016-03-01 09:00:00', 'employee.user_linked');
        $youngLink = $this->schoolAudit($school, '2020-03-01 09:00:00', 'employee.user_unlinked');
        $platformOld = $this->platformAudit('2017-06-15 11:59:59');
        $platformYoung = $this->platformAudit('2017-06-15 12:00:00');

        $this->artisan('platform:audit-prune')->expectsOutputToContain('Deleted 2 School and 1 platform audit event(s)')->assertSuccessful();

        $this->assertTrue($this->schoolExists($school, $exactly));
        $this->assertFalse($this->schoolExists($school, $older));
        $this->assertFalse($this->schoolExists($school, $oldLink));
        $this->assertTrue($this->schoolExists($school, $youngLink));
        $this->assertFalse(DB::table('platform_audit_events')->where('id', $platformOld)->exists());
        $this->assertTrue(DB::table('platform_audit_events')->where('id', $platformYoung)->exists());
    }

    #[Test]
    public function the_leap_day_boundary_never_overflows_into_march(): void
    {
        // 2024-02-29 minus 7 years = 2017-02-28 (no overflow to 1 March).
        $this->travelTo(Carbon::parse('2024-02-29 12:00:00', 'UTC'));
        $this->assertSame('2017-02-28 12:00:00', RetentionPeriod::yearsBeforeNow(7)->format('Y-m-d H:i:s'));
        $school = $this->createSchool();
        $kept = $this->schoolAudit($school, '2017-02-28 12:00:00');
        $gone = $this->schoolAudit($school, '2017-02-28 11:59:59');

        $this->artisan('platform:audit-prune')->assertSuccessful();

        $this->assertTrue($this->schoolExists($school, $kept));
        $this->assertFalse($this->schoolExists($school, $gone));
    }

    #[Test]
    public function each_school_in_its_own_context_and_holds_delete_nothing(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $held = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id], 'retention.hold_platform' => true]);
        $oldA = $this->schoolAudit($a, '2015-01-01 00:00:00');
        $youngB = $this->schoolAudit($b, '2023-01-01 00:00:00');
        $oldHeld = $this->schoolAudit($held, '2012-01-01 00:00:00');
        $oldPlatform = $this->platformAudit('2012-01-01 00:00:00');

        $this->artisan('platform:audit-prune')->expectsOutputToContain('held: 1 School, 1 platform')->assertSuccessful();

        $this->assertFalse($this->schoolExists($a, $oldA));
        $this->assertTrue($this->schoolExists($b, $youngB));
        $this->assertTrue($this->schoolExists($held, $oldHeld));
        $this->assertTrue(DB::table('platform_audit_events')->where('id', $oldPlatform)->exists());
    }

    #[Test]
    public function dry_run_counts_batches_and_reruns_are_safe(): void
    {
        config(['retention.batch_size' => 2]);
        $school = $this->createSchool();
        $ids = collect(range(1, 5))->map(fn ($i) => $this->schoolAudit($school, "2015-01-0{$i} 00:00:00"));

        $this->artisan('platform:audit-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 5 School')->assertSuccessful();
        $ids->each(fn ($id) => $this->assertTrue($this->schoolExists($school, $id)));

        $this->artisan('platform:audit-prune')->expectsOutputToContain('Deleted 5 School')->assertSuccessful();
        $this->artisan('platform:audit-prune')->expectsOutputToContain('Deleted 0 School')->assertSuccessful();
    }

    #[Test]
    public function the_e21_2b_prunes_are_scheduled_daily_in_dependency_order(): void
    {
        $events = collect(app(Schedule::class)->events());
        $times = [];

        foreach (['platform:audit-prune', 'platform:email-suppressions-prune', 'platform:authority-history-prune'] as $command) {
            /** @var Event|null $event */
            $event = $events->first(fn (Event $event) => str_contains((string) $event->command, $command));
            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertTrue($event->withoutOverlapping);
            $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $event->expression);
            $times[$command] = $event->expression;
        }

        // Audit first, so elevations whose audit events expired become eligible.
        [$auditMinute, $auditHour] = explode(' ', $times['platform:audit-prune']);
        [$authMinute, $authHour] = explode(' ', $times['platform:authority-history-prune']);
        $this->assertLessThan((int) $authHour * 60 + (int) $authMinute, (int) $auditHour * 60 + (int) $auditMinute);
    }
}
