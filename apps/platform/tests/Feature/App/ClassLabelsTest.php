<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Application\SectionLabel;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * UAT-C4 (UAT-ATT-08): where Sections of several Grades are listed together,
 * every row names its Grade ("Grade 6 · A"), so Grade 6 A, 7 A and 8 A can be
 * told apart without opening each one or reading an id.
 */
class ClassLabelsTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** Grade 6 · A (the world's) and Grade 7 · A, both taught on Mondays. */
    private function twoGrades(): array
    {
        $w = $this->attendanceWorld();
        $this->inSchool($w['school'], function () use ($w) {
            $w['grade']->update(['name' => 'Grade 6', 'sequence' => 6]);
            $w['section']->update(['name' => 'A']);
        });
        $grade7 = $this->createGradeLevel($w['school'], ['name' => 'Grade 7', 'sequence' => 7]);
        $offering7 = $this->createSubjectOffering($w['year'], $w['campus'], $grade7, $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);
        $section7 = $this->createSection($w['year'], $w['campus'], $grade7, ['status' => 'active', 'code' => 'A', 'name' => 'A']);
        $period2 = $this->createTimetablePeriod($w['school'], ['start_time' => '08:00:00', 'end_time' => '08:45:00']);
        $this->createTimetableEntry($offering7, $section7, $w['teacher'], $period2, ['day_of_week' => 1]);

        return $w + ['section7' => $section7];
    }

    private function actor(array $w): static
    {
        return $this->actingAs($w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_register_class_list_names_the_grade_and_is_in_grade_order(): void
    {
        $w = $this->twoGrades();

        $this->actor($w)->get('/app/attendance/take?attendance_date='.self::MONDAY)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('classes', 2)
                ->where('classes.0.classLabel', 'Grade 6 · A')
                ->where('classes.1.classLabel', 'Grade 7 · A')
                ->where('classes.0.sectionCode', 'A'));
    }

    #[Test]
    public function the_timetable_section_picker_names_grade_and_year(): void
    {
        $w = $this->twoGrades();
        $planner = $this->fullTimetableActor($w['school']);

        $labels = collect($this->actingAs($planner)->withHeader('X-School-Id', $w['school']->id)
            ->getJson('/app/timetable-schedule/search/sections?q=A')->assertOk()->json('data'))->pluck('label')->all();

        $this->assertSame(["Grade 6 · A · {$w['year']->name}", "Grade 7 · A · {$w['year']->name}"], $labels);
    }

    #[Test]
    public function the_label_falls_back_to_the_name_and_sorts_by_grade_sequence(): void
    {
        $w = $this->twoGrades();

        $this->inSchool($w['school'], function () use ($w) {
            $six = $w['section']->fresh('gradeLevel');
            $seven = $w['section7']->fresh('gradeLevel');
            $this->assertSame('Grade 6 · A', SectionLabel::of($six));
            $this->assertLessThan(SectionLabel::sortKey($seven), SectionLabel::sortKey($six));
            $this->assertNull(SectionLabel::of(null));
        });
    }
}
