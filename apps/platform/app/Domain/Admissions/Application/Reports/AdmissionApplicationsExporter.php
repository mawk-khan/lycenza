<?php

namespace App\Domain\Admissions\Application\Reports;

use App\Domain\Admissions\Application\AdmissionApplicationAccess;
use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\School;
use App\Support\StudentReports\Export\StudentReportExportColumn as X;
use App\Support\StudentReports\Export\StudentReportExportDefinition;
use App\Support\StudentReports\Export\StudentReportExporter;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIS 11.4 (D-11): `admissions.applications` export -- the list page's own
 * query (AdmissionApplicationAccess::directoryQuery()) under the viewer's
 * `admissions.view` Campus scope, with its filters and sort. Each row is an
 * APPLICATION with its own year, Campus and Grade; a conversion is a Yes/No
 * only (never the Student's name or number). No date of birth, contact,
 * decision note or fee; the separate `admissions.export` capability.
 */
final class AdmissionApplicationsExporter implements StudentReportExporter
{
    private const int CHUNK = 500;

    public function __construct(
        private readonly AdmissionApplicationAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function exportDefinition(): StudentReportExportDefinition
    {
        return new StudentReportExportDefinition('admissions.export', [
            new X('applicant_name', 'Applicant'),
            new X('status', 'Status'),
            new X('academic_year', 'Academic year'),
            new X('campus', 'Campus'),
            new X('grade', 'Grade'),
            new X('created_on', 'Created on'),
            new X('converted', 'Converted to a Student'),
        ], 'admission-applications');
    }

    public function filterRules(School $school, StudentReportViewer $viewer): array
    {
        return AdmissionApplicationReadService::listRules();
    }

    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int
    {
        return $this->context->withSchool($school, fn (): int => $this->query($school, $viewer, $filters)->count());
    }

    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable
    {
        $zone = SchoolTimezone::resolve($school);
        for ($page = 1; ; $page++) {
            $batch = $this->context->withSchool($school, fn (): array => $this->query($school, $viewer, $filters)->forPage($page, self::CHUNK)->get()
                ->map(fn (AdmissionApplication $a): array => [
                    'applicant_name' => trim(implode(' ', array_filter([$a->applicant?->first_name, $a->applicant?->middle_name, $a->applicant?->last_name]))),
                    'status' => ucfirst($a->status),
                    'academic_year' => $a->academicYear?->name,
                    // History keeps its Campus; an inactive one is labelled, never hidden.
                    'campus' => $a->campus === null ? null : ($a->campus->status === 'active' ? $a->campus->name : "{$a->campus->name} (inactive)"),
                    'grade' => $a->gradeLevel?->name,
                    'created_on' => $a->created_at->setTimezone($zone)->toDateString(),
                    'converted' => $a->converted_student_id === null ? 'No' : 'Yes',
                ])->all());
            yield from $batch;
            if (count($batch) < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AdmissionApplication>
     */
    private function query(School $school, StudentReportViewer $viewer, array $filters): Builder
    {
        return $this->access->directoryQuery($viewer->scope('admissions.view'), array_filter($filters, fn ($v) => $v !== null), SchoolTimezone::resolve($school));
    }
}
