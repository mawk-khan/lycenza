<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\DutyCandidateReader;
use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.15A (X-1, R-HR12-1; owner decision 2026-11-04 "employment only"): every
 * operational staff picker -- Timetable teacher (web + API), Transport
 * driver, Visitor host -- lists only Employees with a planned or current
 * employment covering the School-local today, through HR's one
 * DutyCandidateReader, with directory fields only. The save still
 * re-checks under lock (CurrentEmploymentDutyEligibilityTest).
 */
class DutyCandidateSelectorTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $name, ?array $employment): Employee
    {
        $employee = $this->createEmployee($this->school, ['full_name' => $name]);
        if ($employment !== null) {
            $this->createEmploymentRecord($employee, $employment);
        }

        return $employee;
    }

    /** @return array<string, Employee> */
    private function staff(): array
    {
        $rehired = $this->person('Duty Rehired', ['starts_on' => '2019-01-01', 'ends_on' => '2020-12-31', 'status' => 'separated']);
        $this->createEmploymentRecord($rehired, ['starts_on' => '2026-01-01', 'status' => 'active']);

        return [
            'active' => $this->person('Duty Active', ['starts_on' => '2020-01-01', 'status' => 'active']),
            'notice' => $this->person('Duty Notice', ['starts_on' => '2020-01-01', 'status' => 'notice_period']),
            'separated' => $this->person('Duty Separated', ['starts_on' => '2020-01-01', 'ends_on' => '2026-06-14', 'status' => 'separated']),
            // HR.R1-C2 (HRC-1): an employment whose last day is today has been ENDED (end() writes the last day and a
            // terminal status together) -- an `active` row with an end can no longer exist. No new duty is offered.
            'lastDayToday' => $this->person('Duty Last Day Today', ['starts_on' => '2020-01-01', 'ends_on' => '2026-06-15', 'status' => 'separated']),
            'future' => $this->person('Duty Future Joiner', ['starts_on' => '2026-07-01', 'status' => 'pre_joining']),
            'identityOnly' => $this->person('Duty Identity Only', null),
            'draft' => $this->person('Duty Draft', ['starts_on' => '2020-01-01', 'status' => 'draft']),
            'archived' => tap($this->person('Duty Archived', ['starts_on' => '2020-01-01', 'status' => 'active']), fn (Employee $e) => app(TenantContext::class)
                ->withSchool($this->school, fn () => Employee::query()->whereKey($e->id)->update(['record_status' => 'archived']))),
            'rehired' => $rehired,
        ];
    }

    private function names(array $rows): array
    {
        $names = array_column($rows, 'fullName');
        sort($names);

        return $names;
    }

    #[Test]
    public function only_staff_who_could_take_a_new_duty_today_are_offered(): void
    {
        $this->staff();
        $other = $this->createSchool();
        $this->createEmploymentRecord($this->createEmployee($other, ['full_name' => 'Duty Other School']), ['starts_on' => '2020-01-01', 'status' => 'active']);

        $rows = app(DutyCandidateReader::class)->search($this->school, 'Duty', 25);

        $this->assertSame(['Duty Active', 'Duty Notice', 'Duty Rehired'], $this->names($rows),
            'separated (last day past or today), a future joiner, identity-only, draft, archived and another School are never offered; notice period is (HR.12); a rehire resolves its new employment');
        foreach ($rows as $row) {
            $this->assertSame(['id', 'fullName', 'employeeNumber'], array_keys($row), 'directory fields only');
        }
    }

    #[Test]
    public function the_picker_and_the_save_answer_the_same_question(): void
    {
        $staff = $this->staff();
        $offered = array_column(app(DutyCandidateReader::class)->search($this->school, 'Duty', 25), 'id');

        foreach ($staff as $key => $employee) {
            $covered = DB::transaction(fn () => app(EmploymentCoverage::class)->hold($this->school, $employee->id, '2026-06-15'));
            $this->assertSame($covered === EmploymentCoverage::COVERED, in_array($employee->id, $offered, true), "{$key}: the picker never disagrees with the write-time check");
        }
    }

    #[Test]
    public function a_separation_removes_the_person_from_every_picker(): void
    {
        $employee = $this->createEmployee($this->school, ['full_name' => 'Leaving Teacher']);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $this->hr);
        $timetable = $this->createUserWithCapabilities($this->school, ['timetable.schedule.manage']);
        $transport = $this->createUserWithCapabilities($this->school, ['transport.vehicles.manage']);
        $visitors = $this->createUserWithCapabilities($this->school, ['visitor.visits.manage']);
        $urls = [
            [$timetable, '/app/timetable-schedule/search/teachers?q=Leaving'],
            [$transport, '/app/transport/operations/search/drivers?q=Leaving'],
            [$visitors, '/app/visitor/visits/search/hosts?q=Leaving'],
        ];

        foreach ($urls as [$user, $url]) {
            $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
            $this->assertSame(['Leaving Teacher'], array_column($this->getJson($url)->assertOk()->json('data'), 'fullName'), $url);
        }

        app(EmploymentService::class)->end($employment, '2026-06-14', $this->hr);

        foreach ($urls as [$user, $url]) {
            $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
            $this->assertSame([], $this->getJson($url)->assertOk()->json('data'), "{$url}: a separated person is never offered");
        }
    }

    #[Test]
    public function the_api_teacher_search_uses_the_same_reader_and_the_same_floor(): void
    {
        $this->staff();
        $scheduler = $this->createUserWithCapabilities($this->school, ['timetable.schedule.manage']);
        $names = array_column($this->withHeader('Authorization', 'Bearer '.$scheduler->createToken('test-device')->plainTextToken)->getJson("/api/v1/schools/{$this->school->id}/timetable-entries/search/teachers?q=Duty")->assertOk()->json('data'), 'fullName');
        sort($names);
        $this->assertSame(['Duty Active', 'Duty Notice', 'Duty Rehired'], $names);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $nobody = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        $this->withHeader('Authorization', 'Bearer '.$nobody->createToken('test-device')->plainTextToken)->getJson("/api/v1/schools/{$this->school->id}/timetable-entries/search/teachers?q=Duty")->assertForbidden();
    }

    #[Test]
    public function a_search_is_bounded_ordered_and_one_query_however_many_staff_exist(): void
    {
        foreach (range(1, 40) as $i) {
            $this->createEmploymentRecord($this->createEmployee($this->school, ['full_name' => sprintf('Bulk %02d', $i)]), ['starts_on' => '2020-01-01', 'status' => 'active']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(DutyCandidateReader::class)->search($this->school, 'Bulk', 99);
        $queries = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'employees')));
        DB::disableQueryLog();

        $this->assertCount(DutyCandidateReader::MAX_LIMIT, $rows, 'never more than the page');
        $this->assertSame('Bulk 01', $rows[0]['fullName']);
        $this->assertSame(1, $queries, 'one bounded query');
        $this->assertSame([], app(DutyCandidateReader::class)->search($this->school, '%', 25), 'a LIKE wildcard in the term is matched literally');
    }
}
