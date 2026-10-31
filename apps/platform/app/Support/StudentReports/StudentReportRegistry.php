<?php

namespace App\Support\StudentReports;

use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Admissions\Application\Reports\AdmissionApplicationsExporter;
use App\Domain\Guardians\Application\Reports\SiblingReport;
use App\Domain\Students\Application\Documents\DocumentComplianceCohort;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\Reports\Exports\StudentDirectoryExporter;
use App\Domain\Students\Application\Reports\Exports\StudentMovementExporter;
use App\Domain\Students\Application\Reports\SectionCapacityReport;
use App\Domain\Students\Application\Reports\StudentLifecycleReport;
use App\Domain\Students\Application\StudentDirectoryOrder;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition as D;
use Illuminate\Contracts\Container\Container;

/**
 * SIS 11.1: the ONE closed catalogue of SIS operational reports. A report
 * exists only if it is listed here; keys are unique and never reused.
 *
 * - RENDERED reports are StudentReport read models served by the shared
 *   report page; adding one is a class in RENDERED plus its tests.
 * - Existing dedicated report pages (the Directory, the Enrollment
 *   directory, the movement register) are governed entries: their
 *   authority, filters and sorts are declared here and pinned to the page
 *   by StudentReportRegistryTest, so the catalogue can never drift from
 *   what the page actually does.
 *
 * Operational listing only ("which records match these conditions"):
 * never a person count, rate, trend or demographic breakdown (Analytics,
 * ADR 0040, D-01..D-04, D-15), never an export (D-11), and never Data
 * Health (integrity conditions, ADR 0075 §10).
 */
final class StudentReportRegistry
{
    /** @var list<class-string<StudentReport>> */
    public const array RENDERED = [
        StudentLifecycleReport::class,
        SiblingReport::class,
        SectionCapacityReport::class,
    ];

    /**
     * SIS 11.4 (D-11): the ONLY exportable reports and their exporter (built
     * on the report's own query). Every other report -- including
     * `students.siblings` (family relationships stay on screen) and
     * `students.placements` (not in the pilot) -- cannot be exported or
     * printed.
     *
     * @var array<string, class-string<Export\StudentReportExporter>>
     */
    public const array EXPORTERS = [
        'students.directory' => StudentDirectoryExporter::class,
        'students.movements' => StudentMovementExporter::class,
        'students.lifecycle' => StudentLifecycleReport::class,
        'students.section_capacity' => SectionCapacityReport::class,
        'admissions.applications' => AdmissionApplicationsExporter::class,
    ];

    /** @var array<string, D>|null */
    private ?array $definitions = null;

    /** @var array<string, StudentReport> */
    private array $reports = [];

    public function __construct(private readonly Container $container) {}

    /** @return array<string, D> every report, in hub order */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $all = [...self::pages(), ...array_map(fn (string $class) => $this->report($class)->definition(), self::RENDERED)];
        $definitions = [];
        foreach ($all as $definition) {
            if (isset($definitions[$definition->key])) {
                throw new \LogicException("Student report {$definition->key} is registered twice.");
            }
            $definitions[$definition->key] = $definition;
        }
        // By hub category; declaration order within a category (a stable sort).
        $order = array_flip(array_keys(D::CATEGORIES));
        uasort($definitions, fn (D $a, D $b) => $order[$a->category] <=> $order[$b->category]);

        return $this->definitions = $definitions;
    }

    public function find(string $key): ?D
    {
        return $this->definitions()[$key] ?? null;
    }

    /** The read model of a RENDERED report, or null (unknown key, or a dedicated page). */
    public function rendered(string $key): ?StudentReport
    {
        foreach (self::RENDERED as $class) {
            $report = $this->report($class);
            if ($report->definition()->key === $key) {
                return $report;
            }
        }

        return null;
    }

    /** The exporter of an exportable report, or null (unknown key or not exportable). */
    public function exporter(string $key): ?Export\StudentReportExporter
    {
        $class = self::EXPORTERS[$key] ?? null;

        return $class === null || $this->find($key) === null ? null : $this->container->make($class);
    }

    /** @param  class-string<StudentReport>  $class */
    private function report(string $class): StudentReport
    {
        return $this->reports[$class] ??= $this->container->make($class);
    }

    /**
     * The existing dedicated report pages this catalogue governs.
     *
     * @return list<D>
     */
    public static function pages(): array
    {
        $student = new C('student', 'Student', 'student');
        $number = new C('student_number', 'Student number');

        return [
            new D(
                key: 'students.directory',
                title: 'Student directory',
                description: 'Students with their current class. Search by name or number; filter by status and by current Campus, Grade or Section.',
                category: 'students',
                owner: 'Students',
                authority: ['students.view'],
                scope: D::CAMPUS_AWARE,
                route: 'app.students.index',
                filters: ['name', 'student_number', 'status', 'campus_id', 'grade_level_id', 'section_id'],
                columns: [$student, $number, new C('status', 'Status'), new C('current_placement', 'Current class', 'placement', C::RESTRICTED, ['enrollments.view'])],
                sorts: StudentDirectoryOrder::LABELS,
                perPage: StudentDirectoryOrder::PER_PAGE,
                reference: ['SR-03'],
            ),
            new D(
                key: 'students.placements',
                title: 'Placements by academic year',
                description: 'Every placement in a chosen academic year, Campus, Grade or Section, with roll number and placement status.',
                category: 'enrollment',
                owner: 'Students (Enrollment)',
                authority: ['enrollments.view'],
                scope: D::CAMPUS_AWARE,
                route: 'app.enrollments.index',
                filters: ['academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'status', 'student_number', 'student_name', 'roll_number'],
                columns: [$student, $number, new C('placement', 'Class', 'placement'), new C('roll_number', 'Roll number'), new C('status', 'Placement status'), new C('starts_on', 'Starts', 'date')],
                sorts: ['recent' => 'Most recent first'],
                perPage: 20,
                reference: ['SR-03', 'SR-36'],
            ),
            new D(
                key: 'students.movements',
                title: 'Student movements',
                description: 'Entries, Section changes, Campus transfers, promotions and exits from each Student\'s placement history.',
                category: 'enrollment',
                owner: 'Students (Enrollment)',
                authority: ['enrollments.view'],
                scope: D::WHOLE_SCHOOL,
                route: 'app.students.movements',
                filters: ['q', 'category', 'type', 'academic_year_id', 'from', 'to', 'campus_id', 'grade_level_id', 'section_id'],
                columns: [new C('effective_date', 'Date', 'date'), $student, new C('movement', 'Movement'), new C('from', 'From', 'placement'), new C('to', 'To', 'placement'), new C('reason', 'Reason', 'lifecycle_reason')],
                sorts: ['date_desc' => 'Newest first', 'date_asc' => 'Oldest first', 'student' => 'Student name', 'type' => 'Movement type'],
                perPage: StudentMovementRegister::PER_PAGE,
                reference: ['SR-12', 'SR-36'],
            ),
            // SIS 11.5 (owner decision 2026-10-31): the cohort document checklist
            // -- derived requirement STATES per Student for one academic year,
            // never a file, title or download. Whole-School document viewers
            // only, behind current MFA; browser-only (not an exporter).
            new D(
                key: 'students.document_compliance',
                title: 'Document checklist',
                description: 'Each Student\'s required and optional documents for an academic year: missing, pending verification, verified or rejected.',
                category: 'students',
                owner: 'Students (Documents)',
                authority: ['students.view', 'students.documents.view'],
                scope: D::WHOLE_SCHOOL,
                route: 'app.students.document-compliance',
                filters: ['q', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'category', 'status'],
                columns: [$student, $number, new C('placement', 'Class', 'placement'), new C('checklist', 'Documents')],
                sorts: DocumentComplianceCohort::SORTS,
                perPage: DocumentComplianceCohort::PER_PAGE,
                reference: ['SR-21', 'SR-14'],
            ),
            // SIS 11.2: the Campus-aware Admissions application list (ORG.7D-3),
            // with School-local created dates and a closed sort. Applicant !=
            // Application != Student: context is the application's own year,
            // Campus and Grade; a converted Student is a link only for a
            // whole-School `students.view` holder. No fee, date of birth or contact.
            new D(
                key: 'admissions.applications',
                title: 'Admission applications',
                description: 'Applications by status, academic year, Campus, Grade and created date, with a link to the Student once converted.',
                category: 'admissions',
                owner: 'Admissions',
                authority: ['admissions.view'],
                scope: D::CAMPUS_AWARE,
                route: 'app.admissions.index',
                filters: ['applicant_name', 'status', 'academic_year_id', 'campus_id', 'grade_level_id', 'created_from', 'created_to'],
                columns: [
                    new C('applicant', 'Applicant'),
                    new C('applying_for', 'Applying for', 'placement'),
                    new C('status', 'Status'),
                    new C('created_on', 'Created', 'date'),
                    new C('converted_student', 'Student', 'student', C::RESTRICTED, ['students.view']),
                ],
                sorts: AdmissionApplicationReadService::SORTS,
                perPage: 20,
                reference: ['SR-39'],
            ),
        ];
    }
}
