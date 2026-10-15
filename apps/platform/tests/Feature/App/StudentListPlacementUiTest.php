<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): the Students list shows each row's current class
 * (StudentEnrollmentReadService::currentForMany()) -- only to holders of
 * enrollments.view, only from the School's active AcademicYear, in one
 * query per page -- and the paginator's own result total.
 */
class StudentListPlacementUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** @return array<string, mixed> */
    private function props(string $uri = '/app/students'): array
    {
        return $this->get($uri)->assertOk()->viewData('page')['props'];
    }

    /** @return array{school: School, active: mixed, draft: mixed, campus: mixed, grade: mixed} */
    private function context(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school, ['name' => 'Grade 5']);
        $active = $this->createAcademicYear($school, ['code' => 'ACT'.Str::random(4), 'status' => 'active']);
        $draft = $this->createAcademicYear($school, ['code' => 'DRF'.Str::random(4), 'status' => 'draft']);

        return compact('school', 'campus', 'grade', 'active', 'draft');
    }

    #[Test]
    public function enrollment_viewers_see_each_rows_current_class_from_the_active_year_only(): void
    {
        $c = $this->context();
        $sectionA = $this->createSection($c['active'], $c['campus'], $c['grade'], ['name' => '5 A', 'code' => 'G5A']);
        $nextYear = $this->createSection($c['draft'], $c['campus'], $c['grade'], ['name' => '6 A (next year)', 'code' => 'G6A']);

        $placed = $this->createStudent($c['school'], ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'student_number' => 'S-1']);
        $this->createStudentEnrollment($placed, $sectionA, ['roll_number' => '7', 'status' => 'active']);
        // Same name, enrolled only in the draft (next) year: no CURRENT class.
        $nextOnly = $this->createStudent($c['school'], ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'student_number' => 'S-2']);
        $this->createStudentEnrollment($nextOnly, $nextYear, ['status' => 'active']);

        $user = $this->createUserWithCapabilities($c['school'], ['students.view', 'enrollments.view']);
        $this->activate($user, $c['school']);

        $props = $this->props();
        $this->assertTrue($props['canViewEnrollments']);
        $rows = collect($props['students']['data'])->keyBy('studentNumber');
        $this->assertSame(['gradeLevel' => 'Grade 5', 'section' => '5 A', 'rollNumber' => '7'], $rows['S-1']['currentPlacement']);
        $this->assertNull($rows['S-2']['currentPlacement']);
        $this->assertSame(2, $props['students']['total']);
    }

    #[Test]
    public function students_view_alone_never_receives_placement_data(): void
    {
        $c = $this->context();
        $section = $this->createSection($c['active'], $c['campus'], $c['grade']);
        $this->createStudentEnrollment($this->createStudent($c['school']), $section, ['status' => 'active']);

        $user = $this->createUserWithCapabilities($c['school'], ['students.view']);
        $this->activate($user, $c['school']);

        $props = $this->props();
        $this->assertFalse($props['canViewEnrollments']);
        $this->assertArrayNotHasKey('currentPlacement', $props['students']['data'][0]);
    }

    #[Test]
    public function placement_is_loaded_in_one_query_regardless_of_page_size(): void
    {
        $c = $this->context();
        $section = $this->createSection($c['active'], $c['campus'], $c['grade']);
        $user = $this->createUserWithCapabilities($c['school'], ['students.view', 'enrollments.view']);

        $count = function (int $students) use ($c, $section, $user): int {
            for ($i = 0; $i < $students; $i++) {
                $this->createStudentEnrollment($this->createStudent($c['school']), $section, ['status' => 'active', 'roll_number' => (string) Str::uuid()]);
            }
            $this->activate($user, $c['school']);
            // Warm-up: the first request also resolves (and caches) capabilities.
            $this->get('/app/students')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count(2);
        $many = $count(10);
        $this->assertSame($few, $many, 'Placement must not add a query per row.');
    }

    #[Test]
    public function another_schools_enrollment_never_appears_as_a_current_class(): void
    {
        $c = $this->context();
        $other = $this->context();
        $otherSection = $this->createSection($other['active'], $other['campus'], $other['grade'], ['name' => 'Other School 5 A']);
        $this->createStudentEnrollment($this->createStudent($other['school']), $otherSection, ['status' => 'active']);
        $this->createStudent($c['school'], ['student_number' => 'MINE-1']);

        $user = $this->createUserWithCapabilities($c['school'], ['students.view', 'enrollments.view']);
        $this->activate($user, $c['school']);

        $props = $this->props();
        $this->assertSame(['MINE-1'], array_column($props['students']['data'], 'studentNumber'));
        $this->assertStringNotContainsString('Other School', json_encode($props));
    }
}
