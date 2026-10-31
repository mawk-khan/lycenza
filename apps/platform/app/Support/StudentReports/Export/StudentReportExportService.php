<?php

namespace App\Support\StudentReports\Export;

use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\StudentReports\StudentReportAccess;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SIS 11.4 (D-11): the ONE governed SIS export -- CSV download and the print
 * view -- over the closed report registry. Synchronous and streamed: no file
 * is written, nothing persists but the audit event.
 *
 * Order, all decided at generation time (there is no deferred job, so no
 * stale grant can be used later):
 *  1. the report's own authority AND its dataset export capability
 *     (StudentReportAccess::authorizeExport);
 *  2. the report page's own filter and sort rules (validation errors, never
 *     a 500);
 *  3. the columns: only the export's declared columns, each also within the
 *     viewer's reach (`requires`); a forged or hidden one is a validation
 *     error, never silently dropped or included;
 *  4. the row count under the viewer's scope: above the format's ceiling the
 *     viewer is asked to narrow the filters;
 *  5. one `student_report.exported` audit event -- report, format, column
 *     keys, row count and the filters as ids, codes and dates (a typed search
 *     is recorded only as "used"): never a row value.
 */
final class StudentReportExportService
{
    /** Free-text filters: recorded in the audit only as used, never their value. */
    private const array SEARCH_FILTERS = ['q', 'name', 'student_number', 'student_name', 'applicant_name', 'roll_number'];

    public function __construct(
        private readonly StudentReportAccess $access,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{definition: StudentReportDefinition, exporter: StudentReportExporter, filters: array<string, mixed>, columns: list<StudentReportExportColumn>, count: int}
     *
     * @throws ValidationException
     */
    public function prepare(School $school, StudentReportViewer $viewer, string $key, string $format, array $input): array
    {
        [$definition, $exporter] = $this->access->authorizeExport($viewer, $key);
        $export = $exporter->exportDefinition();

        $rules = $exporter->filterRules($school, $viewer);
        unset($rules['page']);
        $filters = Validator::make(array_intersect_key($input, $rules), $rules)->validate();

        $columns = $this->columns($viewer, $definition, $export, $input['columns'] ?? null);

        $count = $exporter->exportCount($school, $viewer, $filters);
        $max = $format === StudentReportExportDefinition::PRINT ? StudentReportExportDefinition::MAX_PRINT_ROWS : StudentReportExportDefinition::MAX_ROWS;
        if ($count > $max) {
            throw ValidationException::withMessages(['export' => [
                "This would include {$count} rows; ".($format === StudentReportExportDefinition::PRINT ? 'a print view' : 'a download')." holds at most {$max}. Narrow the filters and try again.",
            ]]);
        }

        return ['definition' => $definition, 'exporter' => $exporter, 'filters' => $filters, 'columns' => $columns, 'count' => $count];
    }

    /** @param  array{definition: StudentReportDefinition, filters: array<string, mixed>, columns: list<StudentReportExportColumn>, count: int}  $plan */
    public function audit(School $school, StudentReportViewer $viewer, array $plan, string $format): void
    {
        $filters = [];
        foreach ($plan['filters'] as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $filters[$k] = in_array($k, self::SEARCH_FILTERS, true) ? '(search used)' : (string) $v;
        }

        $this->audit->school($school, 'student_report.exported', actor: $viewer->actor, metadata: [
            'report' => $plan['definition']->key,
            'format' => $format,
            'columns' => array_map(fn (StudentReportExportColumn $c) => $c->key, $plan['columns']),
            'rowCount' => $plan['count'],
            'filters' => $filters,
        ]);
    }

    /** @param  array{definition: StudentReportDefinition, exporter: StudentReportExporter, filters: array<string, mixed>, columns: list<StudentReportExportColumn>, count: int}  $plan */
    public function csv(School $school, StudentReportViewer $viewer, array $plan): StreamedResponse
    {
        $filename = $plan['exporter']->exportDefinition()->filenameBase.'-'.SchoolTimezone::today($school).'.csv';

        return new StreamedResponse(function () use ($school, $viewer, $plan): void {
            // Generated inside the School's context (the response streams after the request's own scope).
            $this->context->withSchool($school, function () use ($school, $viewer, $plan): void {
                $out = fopen('php://output', 'wb');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so spreadsheets read names correctly
                fputcsv($out, array_map(fn (StudentReportExportColumn $c) => CsvCell::safe($c->label), $plan['columns']), escape: '');
                foreach ($plan['exporter']->exportRows($school, $viewer, $plan['filters']) as $row) {
                    fputcsv($out, array_map(fn (StudentReportExportColumn $c) => CsvCell::safe($row[$c->key] ?? null), $plan['columns']), escape: '');
                }
                fclose($out);
            });
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $filename),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The print view's payload: the same rows and columns as a download.
     *
     * @param  array{definition: StudentReportDefinition, exporter: StudentReportExporter, filters: array<string, mixed>, columns: list<StudentReportExportColumn>, count: int}  $plan
     * @return array<string, mixed>
     */
    public function printPayload(School $school, StudentReportViewer $viewer, array $plan): array
    {
        $rows = [];
        foreach ($plan['exporter']->exportRows($school, $viewer, $plan['filters']) as $row) {
            $rows[] = array_map(fn (StudentReportExportColumn $c) => $row[$c->key] ?? null, $plan['columns']);
        }

        return [
            'school' => $school->name,
            'title' => $plan['definition']->title,
            'columns' => array_map(fn (StudentReportExportColumn $c) => $c->label, $plan['columns']),
            'rows' => $rows,
            'filters' => $this->filterSummary($school, $plan['filters']),
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
            'backUrl' => $plan['definition']->url(),
        ];
    }

    /**
     * @return list<StudentReportExportColumn>
     *
     * @throws ValidationException
     */
    private function columns(StudentReportViewer $viewer, StudentReportDefinition $definition, StudentReportExportDefinition $export, mixed $requested): array
    {
        $reachable = array_values(array_filter($export->columns, fn (StudentReportExportColumn $c) => $this->reaches($viewer, $definition, $c)));
        if ($requested === null || $requested === []) {
            return array_values(array_filter($reachable, fn (StudentReportExportColumn $c) => $c->default));
        }
        if (! is_array($requested)) {
            throw ValidationException::withMessages(['columns' => ['Choose columns from the list.']]);
        }

        $byKey = [];
        foreach ($reachable as $c) {
            $byKey[$c->key] = $c;
        }
        $chosen = [];
        foreach ($requested as $key) {
            if (! is_string($key) || ! isset($byKey[$key])) {
                throw ValidationException::withMessages(['columns' => ['A requested column cannot be exported.']]);
            }
            $chosen[$key] = $byKey[$key];
        }

        return array_values($chosen);
    }

    private function reaches(StudentReportViewer $viewer, StudentReportDefinition $definition, StudentReportExportColumn $column): bool
    {
        foreach ($column->requires as $capability) {
            if (! ($definition->scope === StudentReportDefinition::WHOLE_SCHOOL ? $viewer->holdsWholeSchool($capability) : $viewer->holdsAnywhere($capability))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Human "Filter: value" lines for the print header (ids become this
     * School's own names; an unknown id reads "(not found)").
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function filterSummary(School $school, array $filters): array
    {
        return $this->context->withSchool($school, function () use ($filters): array {
            $lines = [];
            foreach ($filters as $key => $value) {
                if ($value === null || $value === '' || $key === 'sort') {
                    continue;
                }
                $label = ucfirst(str_replace(['_id', '_'], ['', ' '], (string) $key));
                $text = match ($key) {
                    'academic_year_id' => AcademicYear::query()->find($value)?->name,
                    'campus_id' => Campus::query()->find($value)?->name,
                    'grade_level_id' => GradeLevel::query()->find($value)?->name,
                    'section_id' => SectionLabel::of(Section::query()->with('gradeLevel')->find($value)),
                    default => (string) $value,
                };
                $lines[] = "{$label}: ".($text ?? '(not found)');
            }

            return $lines;
        });
    }
}
