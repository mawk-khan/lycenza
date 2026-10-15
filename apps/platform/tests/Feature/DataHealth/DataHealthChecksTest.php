<?php

namespace Tests\Feature\DataHealth;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use App\Models\User;
use App\Support\DataHealth\DataHealthRegistry;
use App\Support\DataHealth\DataHealthService;
use App\Support\Navigation\StaffNavigation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * V11.5 (ADR 0075 section 10): each Data Health check flags exactly its
 * documented condition -- unhealthy rows appear, healthy and edge rows do
 * not -- and never another School's records.
 */
class DataHealthChecksTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesTeachingAssignmentFixtures;

    private const array ALL_VIEW = ['enrollments.view', 'students.view', 'academics.years.view', 'academics.structure.view', 'timetable.schedule.view', 'teaching.assignments.view'];

    /** @return list<string> the affected entity ids */
    private function found(School $school, string $code, ?User $viewer = null): array
    {
        $viewer ??= $this->createUserWithCapabilities($school, self::ALL_VIEW);

        return array_column(app(DataHealthService::class)->findings($school, $code, $viewer, 1)['findings']->items(), 'entityId');
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /** @return array{school: School, campus: mixed, grade: mixed, active: mixed, closed: mixed, activeSection: Section, closedSection: Section} */
    private function years(?School $school = null): array
    {
        $school ??= $this->createSchool();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $closed = $this->createAcademicYear($school, ['code' => 'C'.Str::random(5), 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31', 'status' => 'closed']);
        $active = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active']);
        $closedSection = $this->createSection($closed, $campus, $grade, ['code' => 'CS'.Str::random(4)]);
        $activeSection = $this->createSection($active, $campus, $grade, ['code' => 'AS'.Str::random(4)]);

        return compact('school', 'campus', 'grade', 'active', 'closed', 'activeSection', 'closedSection');
    }

    #[Test]
    public function every_registered_check_has_a_unique_valid_contract(): void
    {
        $codes = array_keys(app(DataHealthRegistry::class)->all());
        $this->assertCount(8, $codes);
        foreach (app(DataHealthRegistry::class)->all() as $code => $check) {
            $definition = $check->definition();
            $this->assertSame($code, $definition->code);
            $this->assertFalse($definition->automaticRepair, "{$code}: V11.5 is read-only; no automatic repair.");
            $this->assertNotSame('', $definition->repair);
            $this->assertNotEmpty(
                array_intersect($definition->viewCapabilities, StaffNavigation::hubRequirement('data-health')),
                "{$code}: a viewer of this check must be able to open the hub.",
            );
        }
    }

    #[Test]
    public function an_active_placement_in_a_closed_year_is_flagged_and_ended_ones_are_not(): void
    {
        $y = $this->years();
        $stale = $this->createStudentEnrollment($this->createStudent($y['school']), $y['closedSection'], ['status' => 'active']);
        $completed = $this->createStudentEnrollment($this->createStudent($y['school']), $y['closedSection'], ['status' => 'completed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-03-31']);
        $current = $this->createStudentEnrollment($this->createStudent($y['school']), $y['activeSection'], ['status' => 'active']);

        $found = $this->found($y['school'], 'enrollment.active_in_closed_year');
        $this->assertSame([$stale->id], $found);
        $this->assertNotContains($completed->id, $found);
        $this->assertNotContains($current->id, $found);
    }

    #[Test]
    public function an_active_placement_in_a_deactivated_section_is_flagged_except_in_a_closed_year(): void
    {
        $y = $this->years();
        $inactive = $this->createSection($y['active'], $y['campus'], $y['grade'], ['code' => 'IN'.Str::random(4), 'status' => 'inactive']);
        $flagged = $this->createStudentEnrollment($this->createStudent($y['school']), $inactive, ['status' => 'active']);
        $this->in($y['school'], fn () => Section::query()->whereKey($y['closedSection']->id)->update(['status' => 'inactive']));
        $closedYear = $this->createStudentEnrollment($this->createStudent($y['school']), $y['closedSection'], ['status' => 'active']);
        $healthy = $this->createStudentEnrollment($this->createStudent($y['school']), $y['activeSection'], ['status' => 'active']);

        $found = $this->found($y['school'], 'enrollment.active_in_inactive_section');
        $this->assertSame([$flagged->id], $found);
        $this->assertNotContains($closedYear->id, $found, 'A closed year is reported by its own check, not twice.');
        $this->assertNotContains($healthy->id, $found);
    }

    #[Test]
    public function an_inactive_student_with_an_open_placement_is_flagged_for_review_only(): void
    {
        $y = $this->years();
        $flagged = $this->createStudent($y['school'], ['status' => 'inactive']);
        $this->createStudentEnrollment($flagged, $y['activeSection'], ['status' => 'active']);
        $leftProperly = $this->createStudent($y['school'], ['status' => 'inactive']);
        $this->createStudentEnrollment($leftProperly, $y['activeSection'], ['status' => 'withdrawn', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
        $active = $this->createStudent($y['school'], ['status' => 'active']);
        $this->createStudentEnrollment($active, $y['activeSection'], ['status' => 'active']);

        $this->assertSame([$flagged->id], $this->found($y['school'], 'student.inactive_with_active_placement'));
        $this->assertSame('review', app(DataHealthRegistry::class)->find('student.inactive_with_active_placement')->definition()->severity);
    }

    #[Test]
    public function an_active_student_without_a_current_placement_is_flagged_and_optional_data_is_not(): void
    {
        $y = $this->years();
        $unplaced = $this->createStudent($y['school'], ['status' => 'active']);
        $placed = $this->createStudent($y['school'], ['status' => 'active']);
        $this->createStudentEnrollment($placed, $y['activeSection'], ['status' => 'active']);
        // Placed only in the CLOSED year: no current placement.
        $lastYearOnly = $this->createStudent($y['school'], ['status' => 'active']);
        $this->createStudentEnrollment($lastYearOnly, $y['closedSection'], ['status' => 'completed', 'starts_on' => '2025-06-01', 'ends_on' => '2026-03-31']);
        $inactive = $this->createStudent($y['school'], ['status' => 'inactive']);
        // No guardian and no middle name on anyone: optional data is never a finding.

        $found = $this->found($y['school'], 'student.active_without_current_placement');
        $this->assertEqualsCanonicalizing([$unplaced->id, $lastYearOnly->id], $found);
        $this->assertNotContains($placed->id, $found);
        $this->assertNotContains($inactive->id, $found);
    }

    #[Test]
    public function with_no_active_year_only_the_setup_check_fires(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['status' => 'active']);

        $this->assertSame([$school->id], $this->found($school, 'academic_setup.no_active_year'));
        $this->assertSame([], $this->found($school, 'student.active_without_current_placement'));
        $this->assertSame([], $this->found($school, 'academic_setup.active_year_without_sections'));

        $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
        $this->assertSame([], $this->found($school, 'academic_setup.no_active_year'));
    }

    #[Test]
    public function an_active_year_with_only_inactive_sections_is_flagged(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['code' => 'A'.Str::random(5), 'status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $this->createSection($year, $campus, $grade, ['status' => 'inactive']);

        $this->assertSame([$year->id], $this->found($school, 'academic_setup.active_year_without_sections'));

        $this->createSection($year, $campus, $grade, ['code' => 'OK'.Str::random(4)]);
        $this->assertSame([], $this->found($school, 'academic_setup.active_year_without_sections'));
    }

    #[Test]
    public function an_active_section_without_an_active_schedule_is_flagged(): void
    {
        $w = $this->attendanceWorld();
        $unscheduled = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'UN'.Str::random(4)]);

        $found = $this->found($w['school'], 'timetable.section_without_schedule');
        $this->assertContains($unscheduled->id, $found);
        $this->assertNotContains($w['section']->id, $found, 'A Section with an active class is fine.');

        $this->in($w['school'], fn () => TimetableEntry::query()->whereKey($w['entry']->id)->update(['status' => 'inactive']));
        $this->assertContains($w['section']->id, $this->found($w['school'], 'timetable.section_without_schedule'));
    }

    #[Test]
    public function a_current_assignment_on_a_deactivated_class_is_flagged_and_ended_ones_are_not(): void
    {
        $w = $this->teachingWorld();
        $current = $this->assign($w, '2026-06-01');
        $ended = $this->assign($w, '2026-04-01', '2026-04-30', $this->employedTeacher($w['school']));

        $this->assertSame([], $this->found($w['school'], 'teaching.assignment_on_inactive_class'));

        $this->in($w['school'], fn () => SubjectOffering::query()->whereKey($w['offering']->id)->update(['status' => 'inactive']));
        $found = $this->found($w['school'], 'teaching.assignment_on_inactive_class');
        $this->assertContains($current->id, $found);
        $this->assertNotContains($ended->id, $found);
    }

    #[Test]
    public function another_schools_records_never_appear_in_any_check(): void
    {
        $mine = $this->years();
        $theirs = $this->years();
        $foreign = $this->createStudentEnrollment($this->createStudent($theirs['school']), $theirs['closedSection'], ['status' => 'active']);
        $foreignUnplaced = $this->createStudent($theirs['school'], ['status' => 'active']);

        foreach (array_keys(app(DataHealthRegistry::class)->all()) as $code) {
            $found = $this->found($mine['school'], $code);
            $this->assertNotContains($foreign->id, $found, $code);
            $this->assertNotContains($foreignUnplaced->id, $found, $code);
            $this->assertNotContains($theirs['school']->id, $found, $code);
        }
    }
}
