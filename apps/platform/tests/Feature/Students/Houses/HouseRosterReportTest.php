<?php

namespace Tests\Feature\Students\Houses;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Application\Reports\HouseRosterReport;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\StudentReports\StudentReportRegistry;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.7: `students.houses` -- the House roster through the governed SIS
 * report contract. Current members only, Campus-aware rows, closed filters
 * that only narrow, no export, another School never shows.
 */
class HouseRosterReportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private Section $mainA;

    private Section $earlyA;

    private StudentGroup $red;

    private StudentGroup $blue;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $year = $this->createAcademicYear($this->school, ['name' => 'Current', 'code' => 'AYC', 'status' => 'active', 'starts_on' => $today->subMonths(3)->toDateString(), 'ends_on' => $today->addMonths(6)->toDateString()]);
        $grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->mainA = $this->createSection($year, $this->main, $grade, ['name' => 'A', 'code' => 'A']);
        $this->earlyA = $this->createSection($year, $this->early, $grade, ['name' => 'E', 'code' => 'E']);
        $houses = app(HouseAdministrationService::class);
        $this->red = $houses->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED', 'display_order' => 1]);
        $this->blue = $houses->create($this->school, $this->admin, ['name' => 'Blue', 'code' => 'BLUE', 'display_order' => 2]);
    }

    private function member(string $first, string $number, Section $section, StudentGroup $house, ?HouseResponsibility $responsibility = null): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Rao', 'student_number' => $number]);
        app(StudentEnrollmentService::class)->enroll($s, $section, $number, CarbonImmutable::parse(SchoolTimezone::today($this->school))->subMonth()->toDateString(), $this->admin);
        $service = app(StudentHouseService::class);
        $version = fn () => $service->history($this->school, $this->admin, $s->id)['version'];
        $service->assign($this->school, $this->admin, $s->id, $house->id, null, $version());
        if ($responsibility !== null) {
            $service->assignResponsibility($this->school, $this->admin, $s->id, $responsibility, $version());
        }

        return $s;
    }

    private function as(User $user, ?School $school = null): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** @return list<string> "number|house|responsibility" */
    private function rows(array $query = [], ?User $as = null): array
    {
        $props = $this->as($as ?? $this->admin)->get('/app/students/reports/students.houses?'.http_build_query($query))->assertOk()->viewData('page')['props'];

        return array_map(fn (array $r) => "{$r['student']['studentNumber']}|{$r['house']}|{$r['responsibility']}", $props['rows']['data']);
    }

    #[Test]
    public function the_roster_is_a_governed_rendered_report_without_export(): void
    {
        $definition = app(HouseRosterReport::class)->definition();
        $this->assertSame(['students.houses.view'], $definition->authority);
        $this->assertContains(HouseRosterReport::class, StudentReportRegistry::RENDERED);
        $this->assertArrayNotHasKey('students.houses', StudentReportRegistry::EXPORTERS, 'browser-only: House membership has no export yet');
        $this->as($this->admin)->post('/app/students/reports/students.houses/export', ['format' => 'csv', 'columns' => ['student']])->assertNotFound();
        $this->as($this->admin)->get('/app/students/reports/students.houses/print?columns[]=student')->assertNotFound();
    }

    #[Test]
    public function current_members_are_listed_and_closed_filters_only_narrow(): void
    {
        $asha = $this->member('Asha', 'S-1', $this->mainA, $this->red, HouseResponsibility::HOUSE_CAPTAIN);
        $this->member('Ravi', 'S-2', $this->earlyA, $this->red, HouseResponsibility::PREFECT);
        $this->member('Meera', 'S-3', $this->mainA, $this->blue);
        $service = app(StudentHouseService::class);
        $leaver = $this->member('Old', 'S-4', $this->mainA, $this->blue);
        $service->end($this->school, $this->admin, $leaver->id, HouseMembershipReason::SCHOOL_DECISION, $service->history($this->school, $this->admin, $leaver->id)['version']);

        $this->assertSame(['S-1|Red|House Captain', 'S-2|Red|Prefect', 'S-3|Blue|—'], $this->rows(), 'ended memberships are history, never on the roster');
        $this->assertSame(['S-3|Blue|—'], $this->rows(['house_id' => $this->blue->id]));
        $this->assertSame(['S-1|Red|House Captain'], $this->rows(['responsibility' => 'house_captain']));
        $this->assertSame(['S-2|Red|Prefect'], $this->rows(['campus_id' => $this->early->id]));
        $this->assertSame(['S-1|Red|House Captain'], $this->rows(['q' => 'Asha']));
        $this->as($this->admin)->get('/app/students/reports/students.houses?house_id=not-a-uuid')->assertSessionHasErrors('house_id');
        $this->assertSame([], $this->rows(['house_id' => (string) Str::uuid7()]), 'a forged id matches nothing');
        $this->as($this->admin)->get('/app/students/reports/students.houses?responsibility=head_boy')->assertSessionHasErrors('responsibility');

        $first = $this->as($this->admin)->get('/app/students/reports/students.houses')->viewData('page')['props']['rows']['data'][0];
        $this->assertSame("/app/students/{$asha->id}", $first['student']['url']);
        $this->assertSame('Main', $first['placement']['campus']);
    }

    #[Test]
    public function a_campus_reader_sees_only_in_scope_members_and_teachers_see_nothing(): void
    {
        $this->member('Asha', 'S-1', $this->mainA, $this->red);
        $this->member('Ravi', 'S-2', $this->earlyA, $this->red);
        $campus = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campus, $this->school), 'principal', $this->main->id);

        $this->assertSame(['S-1|Red|—'], $this->rows([], $campus));
        $this->assertSame([], $this->rows(['campus_id' => $this->early->id], $campus), 'a filter never widens the scope');
        $this->assertNull($this->as($campus)->get('/app/students/reports/students.houses')->viewData('page')['props']['rows']['data'][0]['student']['url'], 'no link to the whole-School profile');

        $classless = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($classless, $this->school), $this->createFixtureRole(['students.houses.view']));
        $row = $this->as($classless)->get('/app/students/reports/students.houses')->viewData('page')['props']['rows']['data'][0];
        $this->assertArrayNotHasKey('placement', array_filter($row, fn ($v) => $v !== null), 'the class column needs enrollments.view');

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $this->as($teacher)->get('/app/students/reports/students.houses')->assertForbidden();
        $this->as($teacher)->get('/app/students/reports')->assertForbidden();
    }

    #[Test]
    public function another_school_never_appears_on_the_roster(): void
    {
        $this->member('Asha', 'S-1', $this->mainA, $this->red);
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        app(HouseAdministrationService::class)->create($other, $otherAdmin, ['name' => 'Red', 'code' => 'RED']);

        $this->as($otherAdmin, $other);
        $props = $this->get('/app/students/reports/students.houses?house_id='.$this->red->id)->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['rows']['data']);
        $houses = collect($props['filters'])->firstWhere('key', 'house_id')['options'];
        $this->assertSame(['Red'], array_column($houses, 'label'), 'only the School\'s own Houses are offered');
    }
}
