<?php

namespace Tests\Feature\TeachingAssignments;

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
        $writers = array_filter(
            array_merge($this->grep('new TeachingAssignment', 'app'), $this->grep("table('teaching_assignments')", 'app')),
            fn (string $line) => ! str_contains($line, '/TeachingAssignmentService.php:'),
        );

        $this->assertSame([], array_values($writers), 'Only TeachingAssignmentService writes teaching_assignments: '.implode("\n", $writers));
    }
}
