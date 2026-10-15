<?php

namespace Tests\Feature\DataHealth;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.5 (ADR 0075 section 10): the Data Health pages. The hub opens for
 * any check's capability; every check and finding is filtered by the
 * capabilities that show that data at its source; repair links appear
 * only for holders of the owning workflow's capability; the overview has
 * no counts; GET only.
 */
class DataHealthUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function member(School $school, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** A School with one active Student and no placement this year. */
    private function schoolWithUnplacedStudent(): array
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
        $this->createSection($year, $this->createCampus($school), $this->createGradeLevel($school));
        $student = $this->createStudent($school, ['status' => 'active', 'first_name' => 'Ira', 'last_name' => 'Mehta', 'date_of_birth' => '2016-05-05']);

        return [$school, $student];
    }

    /** @return list<string> */
    private function visibleCodes(): array
    {
        return array_column(array_column($this->get('/app/data-health')->assertOk()->viewData('page')['props']['checks'], 'definition'), 'code');
    }

    #[Test]
    public function the_hub_is_refused_without_any_check_capability(): void
    {
        [$school] = $this->schoolWithUnplacedStudent();
        $this->member($school, ['students.view']);

        $this->get('/app/data-health')->assertForbidden();
        $this->get('/app/data-health/student.active_without_current_placement')->assertForbidden();
    }

    #[Test]
    public function each_viewer_sees_only_the_checks_their_capabilities_cover(): void
    {
        [$school] = $this->schoolWithUnplacedStudent();

        $this->member($school, ['enrollments.view']);
        $this->assertEqualsCanonicalizing(['enrollment.active_in_closed_year', 'enrollment.active_in_inactive_section'], $this->visibleCodes());
        // A Student-status check needs students.view as well: opening it directly is refused.
        $this->get('/app/data-health/student.active_without_current_placement')->assertForbidden();

        $this->member($school, ['enrollments.view', 'students.view']);
        $this->assertContains('student.active_without_current_placement', $this->visibleCodes());

        $this->member($school, ['teaching.assignments.view']);
        $this->assertSame(['teaching.assignment_on_inactive_class'], $this->visibleCodes());
    }

    #[Test]
    public function the_overview_reports_presence_only_never_a_count(): void
    {
        [$school] = $this->schoolWithUnplacedStudent();
        $this->member($school, ['enrollments.view', 'students.view']);

        $checks = $this->get('/app/data-health')->assertOk()->viewData('page')['props']['checks'];
        foreach ($checks as $check) {
            $this->assertSame(['definition', 'hasFindings'], array_keys($check));
            $this->assertIsBool($check['hasFindings']);
        }
        $byCode = collect($checks)->keyBy('definition.code');
        $this->assertTrue($byCode['student.active_without_current_placement']['hasFindings']);
        $this->assertFalse($byCode['enrollment.active_in_closed_year']['hasFindings']);
    }

    #[Test]
    public function findings_are_display_safe_and_repair_links_follow_the_owning_capability(): void
    {
        [$school, $student] = $this->schoolWithUnplacedStudent();

        $this->member($school, ['enrollments.view', 'students.view']);
        $props = $this->get('/app/data-health/student.active_without_current_placement')->assertOk()->viewData('page')['props'];
        $row = $props['findings']['data'][0];
        $this->assertSame($student->id, $row['entityId']);
        $this->assertStringContainsString('Ira Mehta', $row['label']);
        $this->assertSame(['Open Student'], array_column($row['links'], 'label'), 'No Enroll link without enrollments.manage.');
        $this->assertSame(1, $props['findings']['total']);
        $this->assertStringNotContainsString('2016-05-05', json_encode($props));

        $this->member($school, ['enrollments.view', 'students.view', 'enrollments.manage']);
        $links = $this->get('/app/data-health/student.active_without_current_placement')->viewData('page')['props']['findings']['data'][0]['links'];
        $enroll = collect($links)->firstWhere('label', 'Enroll');
        $this->assertSame("/app/students/{$student->id}/enrollments/create", $enroll['url']);
        $this->assertTrue($enroll['isRepair']);
    }

    #[Test]
    public function an_unknown_check_is_not_found_and_another_schools_records_never_show(): void
    {
        [$school] = $this->schoolWithUnplacedStudent();
        [, $foreignStudent] = $this->schoolWithUnplacedStudent();
        $this->member($school, ['enrollments.view', 'students.view']);

        $this->get('/app/data-health/student.does_not_exist')->assertNotFound();
        $data = $this->get('/app/data-health/student.active_without_current_placement')->viewData('page')['props']['findings']['data'];
        $this->assertNotContains($foreignStudent->id, array_column($data, 'entityId'));
    }

    #[Test]
    public function the_data_health_routes_are_get_only(): void
    {
        [$school] = $this->schoolWithUnplacedStudent();
        $this->member($school, ['enrollments.view', 'students.view', 'enrollments.manage', 'students.manage']);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method}('/app/data-health/student.active_without_current_placement')->assertStatus(405);
        }
    }

    #[Test]
    public function a_finding_list_runs_a_bounded_number_of_queries(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
        $closed = $this->createAcademicYear($school, ['code' => 'C'.Str::random(5), 'starts_on' => '2024-04-01', 'ends_on' => '2025-03-31', 'status' => 'closed']);
        $section = $this->createSection($closed, $this->createCampus($school), $this->createGradeLevel($school));
        $this->member($school, ['enrollments.view']);

        $count = function (int $rows) use ($school, $section): int {
            for ($i = 0; $i < $rows; $i++) {
                $this->createStudentEnrollment($this->createStudent($school), $section, ['status' => 'active', 'roll_number' => (string) Str::uuid()]);
            }
            $this->get('/app/data-health/enrollment.active_in_closed_year')->assertOk(); // warm-up
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/data-health/enrollment.active_in_closed_year')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(12), 'No query per finding.');
        $this->assertNotNull($year);
    }
}
