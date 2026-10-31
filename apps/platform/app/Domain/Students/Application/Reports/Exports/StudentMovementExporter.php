<?php

namespace App\Domain\Students\Application\Reports\Exports;

use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\StudentLifecycleReason;
use App\Models\School;
use App\Support\StudentReports\Export\StudentReportExportColumn as X;
use App\Support\StudentReports\Export\StudentReportExportDefinition;
use App\Support\StudentReports\Export\StudentReportExporter;
use App\Support\StudentReports\StudentReportViewer;

/**
 * SIS 11.4 (D-11): `students.movements` export -- the register's own rows
 * (StudentMovementRegister::rows(), the page's projection, filters and
 * sort). Student lifecycle rows only for a whole-School `students.view`
 * viewer, as on the screen. No actor, free text, Guardian or date of birth.
 */
final class StudentMovementExporter implements StudentReportExporter
{
    private const array CATEGORY = ['entry' => 'Entry', 'movement' => 'Movement', 'exit' => 'Exit'];

    public function __construct(private readonly StudentMovementRegister $register) {}

    public function exportDefinition(): StudentReportExportDefinition
    {
        return new StudentReportExportDefinition('students.export', [
            new X('effective_date', 'Date'),
            new X('student_number', 'Student number'),
            new X('student_name', 'Student name'),
            new X('category', 'Category'),
            new X('movement', 'Movement'),
            new X('from_class', 'From'),
            new X('to_class', 'To'),
            new X('reason', 'Reason'),
        ], 'student-movements');
    }

    public function filterRules(School $school, StudentReportViewer $viewer): array
    {
        return StudentMovementRegister::rules($viewer->holdsWholeSchool('students.view'));
    }

    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int
    {
        return $this->register->count($school, $filters, $viewer->holdsWholeSchool('students.view'));
    }

    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable
    {
        foreach ($this->register->rows($school, $filters, $viewer->holdsWholeSchool('students.view')) as $m) {
            yield [
                'effective_date' => $m['effectiveDate'],
                'student_number' => $m['student']['studentNumber'],
                'student_name' => $m['student']['name'],
                'category' => self::CATEGORY[$m['category']] ?? $m['category'],
                'movement' => $m['typeLabel'],
                'from_class' => self::place($m['from']),
                'to_class' => self::place($m['to']),
                'reason' => $m['reasonCode'] === null ? null : (StudentLifecycleReason::LABELS[$m['reasonCode']] ?? 'Recorded reason'),
            ];
        }
    }

    /** @param  ?array<string, ?string>  $p */
    public static function place(?array $p): ?string
    {
        if ($p === null) {
            return null;
        }
        $class = $p['gradeLevel'] !== null && $p['section'] !== null ? "{$p['gradeLevel']} · {$p['section']}" : $p['gradeLevel'];

        return implode(' · ', array_filter([$class, $p['campus'], $p['academicYear']]));
    }
}
