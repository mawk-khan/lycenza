<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7E-0 (ADR 0076 §42, D-E0-1 to D-E0-7): an Employee's operational
 * Campus set is the union of the active Campuses of their CURRENT
 * assignments -- active Employee, an `active`/`notice_period` Employment
 * covering the School-local date, an assignment covering it. Data only.
 */
class CurrentEmployeeAssignmentsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->position = $this->createPosition($this->school);
    }

    private function resolver(): CurrentEmployeeAssignments
    {
        return app(CurrentEmployeeAssignments::class);
    }

    /** @param  array<string, mixed>  $employment */
    private function employed(array $employment = [], array $employee = []): array
    {
        $person = $this->createEmployee($this->school, $employee);
        $record = $this->createEmploymentRecord($person, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active', ...$employment]);

        return [$person, $record];
    }

    private function assign(EmploymentRecord $record, ?Campus $campus, array $attributes = []): void
    {
        $this->createEmployeeAssignment($record, $this->position, ['campus_id' => $campus?->id, 'starts_on' => '2026-01-01', 'ends_on' => null, ...$attributes]);
    }

    /** @return list<string> */
    private function campuses(Employee $employee, ?string $date = null): array
    {
        return $this->resolver()->campusesFor($this->school, $employee->id, $date);
    }

    private function sorted(Campus ...$campuses): array
    {
        $ids = array_map(fn (Campus $c) => $c->id, $campuses);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function every_current_assignment_contributes_and_primary_plays_no_part(): void
    {
        [$person, $record] = $this->employed();
        $this->assign($record, $this->a, ['is_primary' => true]);
        $this->assign($record, $this->b, ['is_primary' => false]);
        $this->assign($record, null);

        $this->assertSame($this->sorted($this->a, $this->b), $this->campuses($person, '2026-10-20'), 'the union, not the primary; a NULL-Campus assignment places nowhere');
        $this->assertSame(3, $this->inSchool(fn () => $this->resolver()->current($this->school, '2026-10-20')->count()), 'the NULL-Campus assignment is still current');
    }

    #[Test]
    public function no_current_assignment_means_no_operational_campus(): void
    {
        [$person] = $this->employed();
        $this->assertSame([], $this->campuses($person, '2026-10-20'));
    }

    #[Test]
    public function the_employment_must_be_operational_and_cover_the_date(): void
    {
        foreach (['separated', 'terminated', 'retired', 'deceased', 'pre_joining', 'draft'] as $status) {
            [$person, $record] = $this->employed(['status' => $status, 'ends_on' => $status === 'pre_joining' || $status === 'draft' ? null : '2027-12-31']);
            $this->assign($record, $this->a);
            $this->assertSame([], $this->campuses($person, '2026-10-20'), "{$status}: a date-open assignment under it confers nothing");
        }

        [$notice, $record] = $this->employed(['status' => 'notice_period', 'ends_on' => '2026-11-30']);
        $this->assign($record, $this->a);
        $this->assertSame([$this->a->id], $this->campuses($notice, '2026-10-20'), 'notice period is still operational');
        $this->assertSame([], $this->campuses($notice, '2026-12-01'), 'until the employment ends');

        [$later, $record] = $this->employed(['starts_on' => '2026-11-01']);
        $this->assign($record, $this->a, ['starts_on' => '2026-11-01']);
        $this->assertSame([], $this->campuses($later, '2026-10-20'), 'an employment not yet started');
    }

    #[Test]
    public function future_and_ended_assignments_and_archived_employees_confer_nothing(): void
    {
        [$person, $record] = $this->employed();
        $this->assign($record, $this->a, ['starts_on' => '2026-11-01']);
        $this->assign($record, $this->b, ['starts_on' => '2026-01-01', 'ends_on' => '2026-10-19']);
        $this->assertSame([], $this->campuses($person, '2026-10-20'));
        $this->assertSame([$this->a->id], $this->campuses($person, '2026-11-01'), 'the start date is inclusive');
        $this->assertSame([$this->b->id], $this->campuses($person, '2026-10-19'), 'the end date is inclusive');

        [$archived, $record] = $this->employed([], ['record_status' => 'archived']);
        $this->assign($record, $this->a);
        $this->assertSame([], $this->campuses($archived, '2026-10-20'));
    }

    #[Test]
    public function an_inactive_campus_contributes_nothing_until_reactivated(): void
    {
        [$person, $record] = $this->employed();
        $this->assign($record, $this->a);
        $this->assign($record, $this->b);
        $this->inSchool(fn () => $this->b->forceFill(['status' => 'inactive'])->save());
        $this->assertSame([$this->a->id], $this->campuses($person, '2026-10-20'));

        $this->inSchool(fn () => $this->b->forceFill(['status' => 'active'])->save());
        $this->assertSame($this->sorted($this->a, $this->b), $this->campuses($person, '2026-10-20'), 'no history was rewritten');
    }

    #[Test]
    public function today_is_the_schools_local_date_never_utc(): void
    {
        [$person, $record] = $this->employed();
        $this->assign($record, $this->a, ['starts_on' => '2026-10-20']);
        $this->assign($record, $this->b, ['ends_on' => '2026-10-19']);

        // 20:00 UTC on 19 Oct is 01:30 on 20 Oct in Asia/Kolkata.
        $this->travelTo(Carbon::parse('2026-10-19 20:00:00', 'UTC'));
        $this->assertSame('2026-10-20', $this->resolver()->today($this->school));
        $this->assertSame([$this->a->id], $this->campuses($person));

        // 18:00 UTC on 19 Oct is 23:30 on 19 Oct locally.
        $this->travelTo(Carbon::parse('2026-10-19 18:00:00', 'UTC'));
        $this->assertSame([$this->b->id], $this->campuses($person));
    }

    #[Test]
    public function the_union_restricts_an_employee_query_in_sql(): void
    {
        [$both, $record] = $this->employed();
        $this->assign($record, $this->a);
        $this->assign($record, $this->b);
        [$onlyB, $record] = $this->employed();
        $this->assign($record, $this->b);
        [$ended, $record] = $this->employed(['status' => 'separated', 'ends_on' => '2027-01-01']);
        $this->assign($record, $this->a);
        $this->employed();

        $ids = fn (array $campusIds) => $this->inSchool(fn () => $this->resolver()
            ->whereOperationalOnCampuses(Employee::query(), $this->school, $campusIds, '2026-10-20')->orderBy('id')->pluck('id')->all());
        $this->assertSame([$both->id], $ids([$this->a->id]));
        $this->assertEqualsCanonicalizing([$both->id, $onlyB->id], $ids([$this->b->id]));
        $this->assertSame([], $ids([]));
        $this->assertNotContains($ended->id, $ids([$this->a->id, $this->b->id]));
    }

    #[Test]
    public function another_school_never_participates(): void
    {
        [$person, $record] = $this->employed();
        $this->assign($record, $this->a);
        $other = $this->createSchool();
        $otherCampus = $this->createCampus($other);

        $this->assertSame([], $this->resolver()->campusesFor($other, $person->id, '2026-10-20'), 'another School cannot resolve this Employee');
        $this->assertSame([], $this->inSchool(fn () => $this->resolver()->whereOperationalOnCampuses(Employee::query(), $this->school, [$otherCampus->id], '2026-10-20')->pluck('id')->all()));
        $unknown = $this->resolver()->campusSets($this->school, [(string) Str::uuid(), 'not-a-uuid'], '2026-10-20');
        $this->assertSame([], array_merge(...array_values($unknown)), 'an unknown or malformed id places nowhere');
    }

    #[Test]
    public function many_employees_resolve_in_one_query(): void
    {
        $people = [];
        foreach (range(1, 5) as $i) {
            [$person, $record] = $this->employed();
            $this->assign($record, $i % 2 === 0 ? $this->a : $this->b);
            $people[] = $person->id;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        $sets = $this->resolver()->campusSets($this->school, $people, '2026-10-20');

        $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $q) => str_starts_with(ltrim($q['query']), 'select')), 'one bounded query, never one per Employee');
        $this->assertCount(5, $sets);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }
}
