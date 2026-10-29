<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-1: Campus / Grade / Section filters on the Student directory --
 * by the CURRENT placement, in SQL, under the viewer's own `enrollments.view`
 * scope. Filters only narrow: a forged, foreign or mismatched id matches
 * nothing, and the options are cut to the viewer's reach.
 */
class StudentDirectoryPlacementFilterTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $main;

    private Campus $early;

    private AcademicYear $year;

    private GradeLevel $g5;

    private GradeLevel $ukg;

    /** @var array<string, Section> */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY26', 'status' => 'active']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 7]);
        $this->ukg = $this->createGradeLevel($this->school, ['name' => 'UKG', 'code' => 'UKG', 'sequence' => 2]);
        $this->sections['G5-A'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A']);
        $this->sections['G5-B'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'B', 'code' => 'B']);
        $this->sections['UKG-A'] = $this->createSection($this->year, $this->early, $this->ukg, ['name' => 'A', 'code' => 'A']);

        foreach (['G5-A' => ['Aarav', 'Meera'], 'G5-B' => ['Ishaan'], 'UKG-A' => ['Diya', 'Neel']] as $key => $names) {
            foreach ($names as $i => $first) {
                $student = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Test', 'student_number' => "S-{$first}"]);
                $this->createStudentEnrollment($student, $this->sections[$key], ['roll_number' => (string) ($i + 1), 'status' => 'active']);
            }
        }
        // Unplaced this year: never matches a placement filter.
        $this->createStudent($this->school, ['first_name' => 'Unplaced', 'student_number' => 'S-U']);
    }

    /** @param list<string> $capabilities */
    private function viewer(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(array $query = []): array
    {
        return $this->get('/app/students?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> */
    private function names(array $query = []): array
    {
        return collect($this->props($query)['students']['data'])->pluck('firstName')->sort()->values()->all();
    }

    #[Test]
    public function campus_grade_and_section_filter_by_the_current_placement_and_compose(): void
    {
        $this->viewer(['students.view', 'enrollments.view']);

        $this->assertSame(['Diya', 'Neel'], $this->names(['campus_id' => $this->early->id]));
        $this->assertSame(['Aarav', 'Ishaan', 'Meera'], $this->names(['grade_level_id' => $this->g5->id]));
        $this->assertSame(['Ishaan'], $this->names(['section_id' => $this->sections['G5-B']->id]));
        $this->assertSame(['Aarav', 'Meera'], $this->names(['campus_id' => $this->main->id, 'grade_level_id' => $this->g5->id, 'section_id' => $this->sections['G5-A']->id]));
        $this->assertSame(['Meera'], $this->names(['campus_id' => $this->main->id, 'name' => 'Meera']), 'placement filters compose with the existing search');

        // An impossible combination (an Early Years Section with the Main Campus) matches nothing.
        $this->assertSame([], $this->names(['campus_id' => $this->main->id, 'section_id' => $this->sections['UKG-A']->id]));
    }

    #[Test]
    public function forged_or_foreign_ids_match_nothing_and_malformed_ids_are_refused(): void
    {
        $this->viewer(['students.view', 'enrollments.view']);
        $elsewhere = $this->createSchool();
        $foreignSection = $this->createSection($this->createAcademicYear($elsewhere, ['status' => 'active']), $this->createCampus($elsewhere), $this->createGradeLevel($elsewhere));

        $this->assertSame([], $this->names(['section_id' => $foreignSection->id]));
        $this->assertSame([], $this->names(['campus_id' => Str::uuid()->toString()]));
        $this->get('/app/students?section_id=not-a-uuid')->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function options_are_cut_to_the_viewers_reach_and_a_campus_reader_cannot_enumerate_another_campus(): void
    {
        $this->viewer(['students.view', 'enrollments.view'], $this->early->id);

        $props = $this->props();
        $this->assertSame([$this->early->id], array_column($props['placementFilters']['campuses'], 'id'));
        $this->assertSame([$this->sections['UKG-A']->id], array_column($props['placementFilters']['sections'], 'id'));
        $this->assertSame(['Diya', 'Neel'], $this->names());
        // Asking for the Main Campus or a Main Section returns nothing, not Main Students.
        $this->assertSame([], $this->names(['campus_id' => $this->main->id]));
        $this->assertSame([], $this->names(['section_id' => $this->sections['G5-A']->id]));
    }

    #[Test]
    public function without_placement_read_there_are_no_filters_and_a_forged_one_reveals_nothing(): void
    {
        $this->viewer(['students.view']);

        $this->assertNull($this->props()['placementFilters']);
        $this->assertSame([], $this->names(['section_id' => $this->sections['G5-A']->id]), 'class membership is placement data: never inferable without enrollments.view');
    }

    #[Test]
    public function the_filtered_directory_query_count_does_not_grow_with_the_students(): void
    {
        $this->viewer(['students.view', 'enrollments.view']);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students?'.http_build_query(['campus_id' => $this->main->id, 'grade_level_id' => $this->g5->id]))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm the per-request caches (capabilities, membership) once
        $few = $count();
        for ($i = 0; $i < 30; $i++) {
            $student = $this->createStudent($this->school, ['student_number' => "S-X{$i}"]);
            $this->createStudentEnrollment($student, $this->sections['G5-A'], ['roll_number' => (string) (100 + $i), 'status' => 'active']);
        }
        $this->assertSame($few, $count(), 'no query per row');
    }
}
