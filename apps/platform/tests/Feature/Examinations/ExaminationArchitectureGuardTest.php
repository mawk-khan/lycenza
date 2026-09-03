<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Http\Controllers\ExaminationController;
use App\Domain\Examinations\Infrastructure\Examination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4A architecture boundaries, enforced as tests rather than as
 * prose a later checkpoint can quietly drift from. Mirrors
 * Tests\Feature\CurriculumDelivery\CurriculumDeliveryArchitectureGuardTest.
 *
 * Targeted at boundaries that actually matter -- a forbidden column, a
 * forbidden dependency, a write path bypassing the service, or an
 * unsanctioned route -- never at formatting.
 */
class ExaminationArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function moduleSources(): array
    {
        $files = [];
        foreach ([
            app_path('Domain/Examinations'),
            app_path('Http/Controllers/App/Examinations'),
        ] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files[] = resource_path('js/Pages/App/Examinations/Index.vue');

        return $files;
    }

    private function code(string $file): string
    {
        // Strip comments/docblocks: this module's own docblocks
        // legitimately NAME the things it must not depend on.
        return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
    }

    #[Test]
    public function the_table_carries_no_person_paper_or_result_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['examinations'],
        ))->pluck('column_name')->all();

        foreach ([
            // Person identity -- what keeps this row Confidential.
            'student_id', 'student_enrollment_id', 'student_subject_enrollment_id',
            'teacher_id', 'employee_id', 'user_id', 'invigilator_id',
            // Forbidden parents / dependencies.
            'academic_term_id', 'campus_id', 'grade_level_id', 'subject_id',
            'subject_offering_id', 'section_id', 'syllabus_unit_id', 'curriculum_delivery_id',
            'timetable_entry_id', 'attendance_session_id',
            // Paper / marks / results.
            'max_marks', 'paper_id', 'starts_at', 'ends_at', 'duration_minutes',
            'marks', 'score', 'grade', 'grade_scale_id', 'result_status', 'published_at',
            // Free text and attachments.
            'description', 'instructions', 'notes', 'attachment_id', 'document_id',
            // Ordering that belongs to no invariant here.
            'sequence',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on examinations -- see docs/modules/EXAMINATIONS.md.");
        }
    }

    #[Test]
    public function the_module_references_no_forbidden_domain(): void
    {
        // Phase 0H.4B added ExaminationPaper, whose own architecture
        // gate (section 12 of its ADR) EXPLICITLY requires it to
        // reference SubjectOffering via a composite FK -- unlike plain
        // Examination (0H.4A), which correctly never may. The bare
        // 'SubjectOffering' class-name ban therefore no longer applies
        // module-wide; it is checked instead, precisely, by
        // ExaminationPaperArchitectureGuardTest against every file
        // EXCEPT ExaminationPaper's own. Every other forbidden
        // namespace/class below still applies to ALL module files
        // without exception, including ExaminationPaper's (Timetable,
        // Attendance, Students, Syllabus, CurriculumDelivery, Documents,
        // and SubjectOfferingRosterReadService remain just as forbidden
        // for ExaminationPaper -- see that module's own spec).
        $examinationPaperFiles = array_filter($this->moduleSources(), fn ($f) => str_contains(basename($f), 'ExaminationPaper') || str_contains(basename($f), 'SubjectOfferingNotAvailable'));

        foreach ($this->moduleSources() as $file) {
            $code = $this->code($file);

            foreach ([
                'App\\Domain\\Timetable',
                'App\\Domain\\Attendance',
                'App\\Domain\\Students',
                'App\\Domain\\StudentEnrollment',
                'App\\Domain\\Syllabus',
                'App\\Domain\\CurriculumDelivery',
                'App\\Domain\\Documents',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not reference '.$forbidden.' -- Examination Foundation depends outward on Academic Structure only.');
            }

            $forbiddenClasses = [
                'AcademicTerm', 'SyllabusUnit', 'CurriculumDelivery',
                'TimetableEntry', 'AttendanceSession', 'StudentEnrollment',
                'SubjectOfferingRosterReadService',
            ];
            if (! in_array($file, $examinationPaperFiles, true)) {
                $forbiddenClasses[] = 'SubjectOffering';
            }

            foreach ($forbiddenClasses as $forbiddenClass) {
                $this->assertStringNotContainsString($forbiddenClass, $code,
                    basename($file).' must not use '.$forbiddenClass.'.');
            }
        }
    }

    #[Test]
    public function the_module_dispatches_no_domain_event(): void
    {
        foreach ($this->moduleSources() as $file) {
            $code = $this->code($file);

            foreach (['event(', 'Event::dispatch', '::dispatch(', 'DomainEvent', 'domain_event_outbox', 'WebhookEventRegistry'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must emit ZERO domain events -- no consumer exists (CLAUDE.md rule 2).');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_model(): void
    {
        // Phase 0H.4B added a SECOND entity (and sole writer) to this
        // same module directory -- ExaminationPaperService, exactly as
        // legitimate a writer of ExaminationPaper as ExaminationService
        // is of Examination. Both are excluded here; neither writes the
        // OTHER's model (proven by ExaminationPaperArchitectureGuardTest
        // for the new entity, and unchanged by this exclusion for the
        // original one).
        $services = [
            app_path('Domain/Examinations/Application/ExaminationService.php'),
            app_path('Domain/Examinations/Application/ExaminationPaperService.php'),
        ];

        foreach ($this->moduleSources() as $file) {
            if (in_array($file, $services, true)) {
                continue;
            }

            $code = $this->code($file);

            foreach ([
                'Examination::create', 'Examination::query()->create',
                'Examination::insert', 'Examination::updateOrCreate',
                '->forceFill(', '->save()', '->delete()',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every write to ExaminationService.');
            }
        }
    }

    #[Test]
    public function no_lock_or_overlap_machinery_was_introduced(): void
    {
        // Overlapping examination windows are PERMITTED, so there is no
        // multi-row invariant and therefore nothing to lock.
        foreach ($this->moduleSources() as $file) {
            $code = $this->code($file);

            foreach (['TenantLock', 'lockForUpdate', 'advisory', 'pg_advisory'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must introduce no locking -- Examination has no multi-row invariant.');
            }
        }
    }

    #[Test]
    public function the_status_vocabulary_is_closed_and_is_not_a_state_machine(): void
    {
        $this->assertSame(['active', 'inactive'], Examination::STATUSES);

        foreach (['draft', 'closed', 'archived', 'published'] as $forbidden) {
            $this->assertNotContains($forbidden, Examination::STATUSES,
                'Examination is deliberately NOT a draft/active/closed state machine in 0H.4A.');
        }
    }

    #[Test]
    public function the_registered_route_surface_is_exactly_the_sanctioned_one(): void
    {
        // Phase 0H.4B's ExaminationPaper API/web routes deliberately
        // share the literal path segment "examinations" (e.g.
        // `/examinations/{examination}/examination-papers`,
        // `/app/examinations/{examination}/papers`) -- a URI substring
        // filter would now also catch them. Filtered by controller
        // class instead (the same technique
        // ExaminationOpenApiCoverageTest::liveOperations() already
        // uses), so this assertion stays exactly what it always was:
        // Examination's own four API + three web routes, unchanged.
        $apiController = ExaminationController::class;
        $webController = \App\Http\Controllers\App\Examinations\ExaminationController::class;

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($r) use ($apiController, $webController) {
                $action = $r->getAction('controller');
                if (! is_string($action)) {
                    return false;
                }
                [$controller] = explode('@', $action, 2) + [null];

                return $controller === $apiController || $controller === $webController;
            })
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /api/v1/schools/{school}/academic-years/{academicYear}/examinations',
            'GET /api/v1/schools/{school}/examinations/{examination}',
            'GET /app/examinations',
            'PATCH /api/v1/schools/{school}/examinations/{examination}',
            'PATCH /app/examinations/{examination}',
            'POST /api/v1/schools/{school}/academic-years/{academicYear}/examinations',
            'POST /app/examinations',
        ], $routes, 'Four API operations plus three web routes -- no delete, activate/deactivate, paper, scheduling, marks, grade-scale, result, search, bulk or reporting route.');

        $api = array_filter($routes, fn ($r) => str_contains($r, '/api/'));
        $web = array_filter($routes, fn ($r) => ! str_contains($r, '/api/'));

        $this->assertCount(4, $api);
        $this->assertCount(3, $web);
        $this->assertCount(7, $routes);
    }
}
