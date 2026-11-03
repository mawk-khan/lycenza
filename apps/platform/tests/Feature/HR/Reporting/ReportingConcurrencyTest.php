<?php

namespace Tests\Feature\HR\Reporting;

use App\Domain\HR\Application\ReportingLine;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.3A: reporting lines under REAL concurrency -- two separate OS
 * processes, the second proven blocked on the first's uncommitted work
 * before it commits (Tests\Concerns\ForcesConcurrentOverlap):
 *
 *  1. two simultaneous lines for one subordinate -- raw writes (the
 *     database backstop alone) and through the service;
 *  2. a manager change against the end of an employment, both ways;
 *  3. a manager change against a transfer of the same person.
 *
 * Committed fixtures; cleaned up through deleteSchoolAsAdmin().
 */
class ReportingConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} */
    private function race(array $holder, array $contender): array
    {
        $script = __DIR__.'/../../../Support/hr-reporting-op.php';

        return $this->raceWithHeldHolder(['php', $script, $this->school->id, ...$holder], ['php', $script, $this->school->id, ...$contender]);
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment} */
    private function staff(string $startsOn = '2024-01-01'): array
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => $startsOn, 'ends_on' => null, 'status' => 'active']);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => $startsOn, 'ends_on' => null, 'is_primary' => true]);

        return [$employment, $assignment];
    }

    private function actor(): User
    {
        return $this->fullHrActor($this->school);
    }

    private function today(): string
    {
        return SchoolTimezone::today($this->school);
    }

    /** @return list<array{manager: string, starts: string, ends: ?string}> live lines of the subordinate, oldest first */
    private function liveLines(EmploymentRecord $subordinate): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => ReportingRelationship::query()
            ->where('subordinate_employment_id', $subordinate->id)->live()->orderBy('starts_on')->get()
            ->map(fn (ReportingRelationship $r) => ['manager' => $r->manager_employment_id, 'starts' => $r->starts_on->toDateString(), 'ends' => $r->ends_on?->toDateString()])->all());
    }

    #[Test]
    public function two_concurrent_raw_lines_for_one_subordinate_commit_exactly_once(): void
    {
        $this->school = $this->createSchool();
        [$sub] = $this->staff();
        [$m1] = $this->staff();
        [$m2] = $this->staff();

        // Different start dates and managers: only the trigger's lock + overlap check can serialize them.
        [$held, $contender] = $this->race(['raw-line', $sub->id, $m1->id, '2025-01-01'], ['raw-line', $sub->id, $m2->id, '2025-06-01']);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:23P01', $contender);
        $this->assertSame([['manager' => $m1->id, 'starts' => '2025-01-01', 'ends' => null]], $this->liveLines($sub));
    }

    #[Test]
    public function two_concurrent_raw_opposite_lines_never_create_a_loop(): void
    {
        $this->school = $this->createSchool();
        [$a] = $this->staff();
        [$b] = $this->staff();

        [$held, $contender] = $this->race(['raw-line', $a->id, $b->id, '2025-01-01'], ['raw-line', $b->id, $a->id, '2025-01-01']);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:23514', $contender);
        $this->assertSame([], $this->liveLines($b));
    }

    #[Test]
    public function two_concurrent_assignments_through_the_service_serialize_into_one_line_per_day(): void
    {
        $this->school = $this->createSchool();
        [$sub] = $this->staff();
        [$m1] = $this->staff();
        [$m2] = $this->staff();
        $actor = $this->actor();
        $today = $this->today();

        [$held, $contender] = $this->race(['assign', $sub->id, $m1->id, $today, $actor->id], ['assign', $sub->id, $m2->id, $today, $actor->id]);

        // The contender waited, then saw the holder's committed line and changed it: never two lines on a day.
        $this->assertStringStartsWith('ok:', $held);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame([['manager' => $m2->id, 'starts' => $today, 'ends' => null]], $this->liveLines($sub), 'the first line became the empty interval');
        $this->assertSame($m2->employee_id, app(ReportingLine::class)->managerOf($this->school, $sub->id, $today));
    }

    #[Test]
    public function an_assignment_waiting_on_an_employment_end_sees_the_end_and_is_refused(): void
    {
        $this->school = $this->createSchool();
        [$sub] = $this->staff();
        [$manager] = $this->staff();
        $actor = $this->actor();
        $end = CarbonImmutable::parse($this->today())->addDays(10)->toDateString();
        $after = CarbonImmutable::parse($this->today())->addDays(20)->toDateString();

        [$held, $contender] = $this->race(['end-employment', $sub->id, $end, $actor->id], ['assign', $sub->id, $manager->id, $after, $actor->id]);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertSame('rejected:InvalidReportingChangeException:outside_employment', $contender);
        $this->assertSame([], $this->liveLines($sub));
    }

    #[Test]
    public function a_manager_employment_end_waiting_on_an_assignment_closes_the_new_line(): void
    {
        $this->school = $this->createSchool();
        [$sub] = $this->staff();
        [$manager] = $this->staff();
        $actor = $this->actor();
        $today = $this->today();
        $end = CarbonImmutable::parse($today)->addDays(10)->toDateString();

        [$held, $contender] = $this->race(['assign', $sub->id, $manager->id, $today, $actor->id], ['end-employment', $manager->id, $end, $actor->id]);

        $this->assertStringStartsWith('ok:', $held);
        $this->assertStringStartsWith('ok:', $contender);
        $this->assertSame([['manager' => $manager->id, 'starts' => $today, 'ends' => $end]], $this->liveLines($sub), 'the line ends with the manager\'s employment');
        $this->assertNull(app(ReportingLine::class)->managerOf($this->school, $sub->id, CarbonImmutable::parse($end)->addDay()->toDateString()), 'no silent re-parenting');
    }

    #[Test]
    public function a_reporting_change_waiting_on_a_transfer_lands_on_the_unchanged_employment(): void
    {
        $this->school = $this->createSchool();
        [$sub, $subAssignment] = $this->staff();
        [$manager] = $this->staff();
        $actor = $this->actor();
        $today = $this->today();
        $campus = $this->createCampus($this->school);
        $position = $this->createPosition($this->school);

        [$held, $contender] = $this->race(
            ['transfer', $subAssignment->id, $campus->id, $position->id, $today, $actor->id],
            ['assign', $sub->id, $manager->id, $today, $actor->id],
        );

        $this->assertStringStartsWith('ok:', $held);
        $this->assertStringStartsWith('ok:', $contender);
        // The transfer opened a new assignment; the line joins the employments, so it covers it too.
        $this->assertSame([['manager' => $manager->id, 'starts' => $today, 'ends' => null]], $this->liveLines($sub));
        $this->assertSame($manager->employee_id, app(ReportingLine::class)->managerOf($this->school, $sub->id, $today));
        $this->assertSame(2, app(TenantContext::class)->withSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $sub->id)->count()));
    }
}
