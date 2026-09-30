<?php

namespace Tests\Concerns;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * TCH.5B fixtures: one School with a required SubjectOffering (Grade G,
 * Campus C, active Year Y), two active Sections of that exact context
 * ("A", "B"), a sibling Offering of the same context (another Subject),
 * Sections that differ in exactly one context dimension (grade, campus,
 * year), an inactive Section of the context, and two Employees.
 *
 * Requires CreatesTenancyFixtures.
 */
trait CreatesLmsOwnershipFixtures
{
    /** The two LMS resource kinds, for data providers. */
    public static function lmsResources(): array
    {
        return [
            'Learning Content' => ['learning_content'],
            'Assignment' => ['assignments'],
        ];
    }

    /** @return array{table: string, bridge: string, fk: string, columns: array<string, mixed>} */
    protected function lmsResource(string $table): array
    {
        return match ($table) {
            'learning_content' => ['table' => 'learning_content', 'bridge' => 'learning_content_section_audiences', 'fk' => 'learning_content_id', 'columns' => ['sequence' => 1]],
            'assignments' => ['table' => 'assignments', 'bridge' => 'assignment_section_audiences', 'fk' => 'assignment_id', 'columns' => []],
        };
    }

    /** @return array<string, mixed> */
    protected function ownershipWorld(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, [
            'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31',
            'code' => 'Y'.strtoupper(Str::random(8)), 'name' => 'Year '.Str::random(8),
        ]);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $sibling = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);

        $otherGrade = $this->createGradeLevel($school);
        $otherCampus = $this->createCampus($school);
        $otherYear = $this->createAcademicYear($school, [
            'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31',
            'code' => 'N'.strtoupper(Str::random(8)), 'name' => 'Next '.Str::random(8),
        ]);

        return [
            'school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade,
            'offering' => $offering, 'sibling' => $sibling,
            'sectionA' => $this->createSection($year, $campus, $grade, ['code' => 'A', 'name' => 'A', 'status' => 'active']),
            'sectionB' => $this->createSection($year, $campus, $grade, ['code' => 'B', 'name' => 'B', 'status' => 'active']),
            'inactive' => $this->createSection($year, $campus, $grade, ['code' => 'Z', 'name' => 'Z', 'status' => 'inactive']),
            'otherGrade' => $this->createSection($year, $campus, $otherGrade, ['code' => 'A', 'name' => 'A', 'status' => 'active']),
            'otherCampus' => $this->createSection($year, $otherCampus, $grade, ['code' => 'A', 'name' => 'A', 'status' => 'active']),
            'otherYear' => $this->createSection($otherYear, $campus, $grade, ['code' => 'A', 'name' => 'A', 'status' => 'active']),
            'employee' => $this->createEmployee($school),
            'employee2' => $this->createEmployee($school),
        ];
    }

    protected function withinSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
