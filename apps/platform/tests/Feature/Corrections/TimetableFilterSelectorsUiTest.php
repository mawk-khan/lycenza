<?php

namespace Tests\Feature\Corrections;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * X-04 (OD-V11-7, ADR 0075 section 12): the timetable list's Section and
 * teacher filters are choices, not typed ids. Options come only from this
 * School's ACTIVE timetable classes; the section_id / teacher_id request
 * contract is unchanged.
 */
class TimetableFilterSelectorsUiTest extends TestCase
{
    use CreatesTenancyFixtures, CreatesTimetableFixtures;

    /** @return array<string, mixed> two scheduled Sections with different teachers, plus unscheduled noise */
    private function world(?School $school = null): array
    {
        $school ??= $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'Y'.Str::random(5), 'status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);
        $a = $this->createSection($year, $campus, $grade, ['code' => 'A'.Str::random(4), 'name' => '5 A']);
        $b = $this->createSection($year, $campus, $grade, ['code' => 'B'.Str::random(4), 'name' => '5 B']);
        $idle = $this->createSection($year, $campus, $grade, ['code' => 'I'.Str::random(4), 'name' => '5 Idle']);
        $periodOne = $this->createTimetablePeriod($school, ['code' => 'P1'.Str::random(3), 'start_time' => '08:00:00', 'end_time' => '08:45:00', 'sort_order' => 1]);
        $periodTwo = $this->createTimetablePeriod($school, ['code' => 'P2'.Str::random(3), 'start_time' => '09:00:00', 'end_time' => '09:45:00', 'sort_order' => 2]);
        $teacherA = $this->createEmployee($school, ['full_name' => 'Asha Teacher']);
        $teacherB = $this->createEmployee($school, ['full_name' => 'Bilal Teacher']);
        $retired = $this->createEmployee($school, ['full_name' => 'Retired Teacher']);
        $entryA = $this->createTimetableEntry($offering, $a, $teacherA, $periodOne);
        $entryB = $this->createTimetableEntry($offering, $b, $teacherB, $periodTwo);
        // Inactive class: its Section/teacher are not on the active schedule.
        $this->createTimetableEntry($offering, $idle, $retired, $periodOne, ['status' => 'inactive']);

        return compact('school', 'year', 'campus', 'a', 'b', 'idle', 'teacherA', 'teacherB', 'retired', 'entryA', 'entryB');
    }

    private function viewer(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.view']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(string $query = ''): array
    {
        return $this->get('/app/timetable-schedule'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];
    }

    #[Test]
    public function the_options_are_the_sections_and_teachers_on_active_classes_only(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);

        $options = $this->props()['filterOptions'];
        $this->assertEqualsCanonicalizing([$w['a']->id, $w['b']->id], array_column($options['sections'], 'id'));
        $this->assertEqualsCanonicalizing([$w['teacherA']->id, $w['teacherB']->id], array_column($options['teachers'], 'id'));
        $this->assertStringContainsString('5 A', collect($options['sections'])->firstWhere('id', $w['a']->id)['label']);
        $this->assertStringContainsString('Asha Teacher', collect($options['teachers'])->firstWhere('id', $w['teacherA']->id)['label']);
    }

    #[Test]
    public function choosing_a_section_filters_the_list_and_narrows_the_teachers(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);

        $props = $this->props('section_id='.$w['a']->id);
        $this->assertSame([$w['entryA']->id], array_column($props['entries']['data'], 'id'));
        $this->assertSame([$w['teacherA']->id], array_column($props['filterOptions']['teachers'], 'id'));
        $this->assertCount(2, $props['filterOptions']['sections'], 'Sections stay switchable.');

        $this->assertSame([$w['entryB']->id], array_column($this->props('teacher_id='.$w['teacherB']->id)['entries']['data'], 'id'));
        $this->assertSame([], $this->props('section_id='.$w['a']->id.'&teacher_id='.$w['teacherB']->id)['entries']['data'], 'Filters combine.');
    }

    #[Test]
    public function the_options_follow_the_status_filter(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);

        // LC-2: an inactive class is findable, so its Section and teacher are offered there.
        $inactive = $this->props('status=inactive')['filterOptions'];
        $this->assertSame([$w['idle']->id], array_column($inactive['sections'], 'id'));
        $this->assertSame([$w['retired']->id], array_column($inactive['teachers'], 'id'));

        $all = $this->props('status=all')['filterOptions'];
        $this->assertEqualsCanonicalizing([$w['a']->id, $w['b']->id, $w['idle']->id], array_column($all['sections'], 'id'));
    }

    #[Test]
    public function another_schools_options_never_appear_and_its_ids_match_nothing(): void
    {
        $w = $this->world();
        $other = $this->world();
        $this->viewer($w['school']);

        $options = $this->props()['filterOptions'];
        $ids = array_merge(array_column($options['sections'], 'id'), array_column($options['teachers'], 'id'));
        foreach ([$other['a']->id, $other['b']->id, $other['teacherA']->id, $other['teacherB']->id] as $foreign) {
            $this->assertNotContains($foreign, $ids);
        }
        $this->assertSame([], $this->props('section_id='.$other['a']->id)['entries']['data']);
        $this->assertSame([], $this->props('teacher_id='.$other['teacherA']->id)['entries']['data']);
    }

    #[Test]
    public function same_named_sections_in_different_grades_get_distinct_labels(): void
    {
        $w = $this->world();
        $year = $w['year'];
        $otherGrade = $this->createGradeLevel($w['school'], ['name' => 'Grade 9']);
        // Section codes are unique only within a grade: "A" exists in two grades.
        $twin = $this->createSection($year, $w['campus'], $otherGrade, ['code' => $w['a']->code, 'name' => '5 A']);
        $offering = $this->createSubjectOffering($year, $w['campus'], $otherGrade, $this->createSubject($w['school']), ['is_required' => true]);
        $this->createTimetableEntry($offering, $twin, $w['teacherA'], $this->createTimetablePeriod($w['school'], ['code' => 'P9'.Str::random(3), 'start_time' => '11:00:00', 'end_time' => '11:45:00']));
        $this->viewer($w['school']);

        $labels = array_column($this->props()['filterOptions']['sections'], 'label');
        $this->assertCount(3, $labels);
        $this->assertSame($labels, array_values(array_unique($labels)), 'Every Section is distinguishable.');
        $this->assertContains('Grade 9', array_map(fn ($l) => explode(' · ', $l)[0], $labels));
    }

    #[Test]
    public function a_malformed_id_is_still_a_validation_error_and_viewing_still_needs_the_capability(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);
        $this->get('/app/timetable-schedule?section_id=5A')->assertSessionHasErrors('section_id');

        $stranger = $this->createUserWithCapabilities($w['school'], ['students.view']);
        $this->actingAs($stranger)->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/timetable-schedule')->assertForbidden();
    }
}
