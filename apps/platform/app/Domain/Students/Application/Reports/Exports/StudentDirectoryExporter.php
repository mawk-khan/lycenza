<?php

namespace App\Domain\Students\Application\Reports\Exports;

use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentDirectoryQuery;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\StudentReports\Export\StudentReportExportColumn as X;
use App\Support\StudentReports\Export\StudentReportExportDefinition;
use App\Support\StudentReports\Export\StudentReportExporter;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIS 11.4 (D-11): `students.directory` export -- the Directory page's own
 * query (StudentDirectoryQuery) under the viewer's `students.view` scope, and
 * the current class under their `enrollments.view` scope (the class columns
 * need it, as on the screen). Never a date of birth or Guardian data.
 */
final class StudentDirectoryExporter implements StudentReportExporter
{
    private const int CHUNK = 500;

    public function __construct(
        private readonly StudentDirectoryQuery $directory,
        private readonly StudentAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function exportDefinition(): StudentReportExportDefinition
    {
        $placement = ['enrollments.view'];

        return new StudentReportExportDefinition('students.export', [
            new X('student_number', 'Student number'),
            new X('student_name', 'Student name'),
            new X('status', 'Status'),
            new X('academic_year', 'Academic year', $placement),
            new X('campus', 'Campus', $placement),
            new X('grade', 'Grade', $placement),
            new X('section', 'Section', $placement),
            new X('roll_number', 'Roll number', $placement),
        ], 'student-directory');
    }

    public function filterRules(School $school, StudentReportViewer $viewer): array
    {
        return StudentDirectoryQuery::rules();
    }

    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int
    {
        return $this->context->withSchool($school, fn (): int => $this->query($school, $viewer, $filters)->count());
    }

    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable
    {
        $placementScope = $viewer->scope('enrollments.view');
        for ($page = 1; ; $page++) {
            $chunk = $this->context->withSchool($school, function () use ($school, $viewer, $filters, $placementScope, $page): array {
                $students = $this->query($school, $viewer, $filters)->forPage($page, self::CHUNK)->get();
                $placements = $this->access->currentPlacements($school, $placementScope, $students->pluck('id')->all());

                return $students->map(function (Student $s) use ($placements): array {
                    $p = $placements->get($s->id);

                    return [
                        'student_number' => $s->student_number,
                        'student_name' => trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name]))),
                        'status' => $s->status === 'active' ? 'Active' : 'Inactive',
                        'academic_year' => $p?->academicYear?->name,
                        'campus' => $p?->campus?->name,
                        'grade' => $p?->gradeLevel?->name,
                        'section' => $p?->section?->name,
                        'roll_number' => $p?->roll_number,
                    ];
                })->all();
            });
            yield from $chunk;
            if (count($chunk) < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Student>
     */
    private function query(School $school, StudentReportViewer $viewer, array $filters): Builder
    {
        return $this->directory->build($school, $viewer->scope('students.view'), $viewer->scope('enrollments.view'), $filters);
    }
}
