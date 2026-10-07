<?php

namespace Tests\Feature\TeachingAssignments;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 sections 8, 11, 14; DOMAIN-MAP "Teaching Assignments")
 * static guards: the dependency direction and the authority boundary that
 * keep TeachingAssignment dormant substrate until TCH.3 adopts it.
 */
class TeachingAssignmentArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function grep(string $pattern, string $path): array
    {
        $output = [];
        exec('grep -rn -F --include=*.php '.escapeshellarg($pattern).' '.escapeshellarg(base_path($path)).' 2>/dev/null', $output);

        return array_values(array_filter($output, fn (string $line) => ! preg_match('#^[^:]+:\d+:\s*(\*|//)#', $line)));
    }

    #[Test]
    public function hr_and_academic_structure_never_depend_on_teaching_assignments(): void
    {
        foreach (['app/Domain/HR', 'app/Domain/AcademicStructure'] as $module) {
            $this->assertSame([], $this->grep('App\\Domain\\TeachingAssignments', $module), "{$module} must not depend on TeachingAssignments.");
            $this->assertSame([], $this->grep('teaching_assignments', $module));
        }
    }

    #[Test]
    public function only_adopted_surfaces_consume_ownership_and_only_through_teaching_ownership(): void
    {
        // Adopted one surface at a time (ADR 0063 section 16): TCH.3
        // Curriculum Delivery, TCH.4 Attendance, TCH.5C/TCH.5D LMS (Learning
        // Content, Assignments) -- each through the
        // TeachingOwnership read only, never the model, the table or the
        // administrative services.
        foreach (['Timetable', 'Syllabus', 'Examinations'] as $module) {
            $this->assertSame([], $this->grep('TeachingAssignment', "app/Domain/{$module}"), "{$module} is not an adopted TeachingAssignment consumer.");
        }

        foreach (['CurriculumDelivery', 'Attendance', 'LMS'] as $module) {
            $uses = array_filter(
                $this->grep('App\\Domain\\TeachingAssignments', "app/Domain/{$module}"),
                fn (string $line) => ! str_contains($line, 'Application\\TeachingOwnership;') && ! str_contains($line, 'Application\\OwnedTeachingPeriod;'),
            );
            $this->assertSame([], array_values($uses), "{$module} may use TeachingOwnership/OwnedTeachingPeriod only: ".implode("\n", $uses));
            $this->assertSame([], $this->grep('teaching_assignments', "app/Domain/{$module}"));
        }

        $this->assertSame([], $this->grep('TeachingAssignment', 'app/Support/Authorization'), 'CapabilityResolver stays independent of ownership.');
    }

    #[Test]
    public function ownership_is_never_derived_from_the_timetable_or_an_actor_identity(): void
    {
        foreach (['Timetable', 'timetable_entries', 'ActingEmployeeResolver'] as $forbidden) {
            $this->assertSame([], $this->grep($forbidden, 'app/Domain/TeachingAssignments'), "TeachingAssignments must not use {$forbidden}.");
        }
    }

    #[Test]
    public function teaching_assignments_are_written_only_by_their_service(): void
    {
        // `new TeachingAssignment;` / `(`, never the exception classes that share the prefix.
        $writers = array_filter(
            array_merge($this->grep('new TeachingAssignment;', 'app'), $this->grep('new TeachingAssignment(', 'app'), $this->grep("table('teaching_assignments')", 'app')),
            fn (string $line) => ! str_contains($line, '/TeachingAssignmentService.php:'),
        );

        $this->assertSame([], array_values($writers), 'Only TeachingAssignmentService writes teaching_assignments: '.implode("\n", $writers));

        // TCH-E (ADR 0063 section 45): the elective fact has its own single writer.
        $electiveWriters = array_filter(
            array_merge($this->grep('new ElectiveTeachingAssignment', 'app'), $this->grep("table('elective_teaching_assignments')", 'app')),
            fn (string $line) => ! str_contains($line, '/ElectiveTeachingAssignmentService.php:'),
        );
        $this->assertSame([], array_values($electiveWriters), 'Only ElectiveTeachingAssignmentService writes elective_teaching_assignments: '.implode("\n", $electiveWriters));
    }

    /**
     * TCH-E (ADR 0063 section 45): elective ownership is the only new teacher-ownership fact, read only through
     * TeachingOwnership, and it grants no StudentMark authority: no consumer in Examinations, no teacher marks
     * capability or route, no results capability. RES.4 remains NOT AUTHORISED (ADR 0068 section 22).
     */
    #[Test]
    public function elective_ownership_is_the_one_new_fact_and_grants_no_marks_authority(): void
    {
        $ownership = (string) file_get_contents(app_path('Domain/TeachingAssignments/Application/TeachingOwnership.php'));
        preg_match_all('/^use App.Domain.TeachingAssignments.Infrastructure.(\w+);/m', $ownership, $facts);
        $this->assertSame(['ElectiveTeachingAssignment', 'TeachingAssignment'], $facts[1], 'TeachingOwnership reads exactly the two ownership facts.');

        foreach (['TeachingOwnership', 'ElectiveTeachingAssignment', 'elective_teaching_assignments', 'OwnedElectivePeriod'] as $forbidden) {
            $this->assertSame([], $this->grep($forbidden, 'app/Domain/Examinations'), "Examinations must not consume {$forbidden} (RES.4 is NOT AUTHORISED).");
            $this->assertSame([], $this->grep($forbidden, 'app/Domain/Students'));
        }
        foreach (['CurriculumDelivery', 'Attendance', 'LMS'] as $module) {
            $this->assertSame([], $this->grep('OwnedElectivePeriod', "app/Domain/{$module}"), "{$module} adopted required ownership only; electives are a new decision per consumer.");
            $this->assertSame([], $this->grep('holdElective', "app/Domain/{$module}"));
            $this->assertSame([], $this->grep('holdOffering', "app/Domain/{$module}"));
        }

        $seeder = (string) file_get_contents(database_path('seeders/CapabilityAndRoleSeeder.php'));
        $this->assertStringNotContainsString("'examinations.marks.teacher'", $seeder);
        $this->assertDoesNotMatchRegularExpression("/'key'\s*=>\s*'examinations\.results\./", $seeder);
        $this->assertDoesNotMatchRegularExpression("/'key'\s*=>\s*'teaching\.[a-z_.]*elective/", $seeder, 'elective ownership reuses teaching.assignments.*; no new capability');
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('#(^|/)(my-marks|my/marks|teacher-marks)(/|$)#', $route->uri(), 'no teacher marks route');
        }
    }
}
