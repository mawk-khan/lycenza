<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Http\Controllers\ExaminationPaperController as ApiExaminationPaperController;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Http\Controllers\App\Examinations\ExaminationPaperController as WebExaminationPaperController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4B architecture boundaries, enforced as tests. Scoped
 * precisely to ExaminationPaper's OWN files -- never the whole
 * `Domain/Examinations` directory, which also holds Examination's
 * distinct 0H.4A files (that module keeps its own, separately-scoped
 * ExaminationArchitectureGuardTest, updated in this same change only
 * where its old loose scanning collided with ExaminationPaper's
 * legitimate new presence in the same directory -- see that file's
 * comments).
 */
class ExaminationPaperArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function paperSources(): array
    {
        return [
            app_path('Domain/Examinations/Infrastructure/ExaminationPaper.php'),
            app_path('Domain/Examinations/Application/ExaminationPaperService.php'),
            app_path('Domain/Examinations/Http/Controllers/ExaminationPaperController.php'),
            app_path('Http/Controllers/App/Examinations/ExaminationPaperController.php'),
            app_path('Domain/Examinations/Application/Exceptions/DuplicateExaminationPaperException.php'),
            app_path('Domain/Examinations/Application/Exceptions/ExaminationPaperDateOutsideWindowException.php'),
            app_path('Domain/Examinations/Application/Exceptions/ExaminationPaperTimeOrderException.php'),
            app_path('Domain/Examinations/Application/Exceptions/ExaminationPaperInvalidMaxMarksException.php'),
            app_path('Domain/Examinations/Application/Exceptions/ExaminationNotActiveException.php'),
            app_path('Domain/Examinations/Application/Exceptions/SubjectOfferingNotAvailableException.php'),
            app_path('Domain/Examinations/Application/Exceptions/ExaminationPaperAcademicYearMismatchException.php'),
            resource_path('js/Pages/App/Examinations/Papers/Index.vue'),
        ];
    }

    private function code(string $file): string
    {
        return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
    }

    #[Test]
    public function the_table_carries_no_forbidden_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['examination_papers'],
        ))->pluck('column_name')->all();

        foreach ([
            // Section / roster / person identity.
            'section_id', 'student_id', 'student_enrollment_id', 'student_subject_enrollment_id',
            'teacher_id', 'employee_id', 'user_id', 'invigilator_id', 'room_id',
            // Forbidden parents / dependencies.
            'timetable_entry_id', 'academic_term_id', 'syllabus_unit_id', 'curriculum_delivery_id',
            // Subject duplicate column (subject_offering_id is the sole legitimate one).
            'subject_id',
            // Identity/document fields.
            'code', 'title', 'component', 'paper_number', 'document_id', 'attachment_id', 'file_id',
            // Marks / grading / results.
            'marks', 'score', 'grade', 'grade_scale_id', 'pass_marks', 'pass_percentage',
            'result_status', 'published_at',
            // Free text.
            'description', 'instructions', 'notes',
            // Ordering that belongs to no invariant here.
            'sequence',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on examination_papers -- see docs/modules/EXAMINATIONS.md.");
        }
    }

    #[Test]
    public function the_module_references_no_forbidden_domain(): void
    {
        foreach ($this->paperSources() as $file) {
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
                    basename($file).' must not reference '.$forbidden.' -- ExaminationPaper depends outward on Academic Structure and its own Examination parent only.');
            }

            foreach ([
                'AcademicTerm', 'SyllabusUnit', 'CurriculumDelivery', 'TimetableEntry',
                'AttendanceSession', 'StudentEnrollment', 'SubjectOfferingRosterReadService',
                'Section', 'Employee', 'Room',
            ] as $forbiddenClass) {
                $this->assertStringNotContainsString($forbiddenClass, $code,
                    basename($file).' must not use '.$forbiddenClass.'.');
            }
        }
    }

    #[Test]
    public function the_module_dispatches_no_domain_event(): void
    {
        foreach ($this->paperSources() as $file) {
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
        $service = app_path('Domain/Examinations/Application/ExaminationPaperService.php');

        foreach ($this->paperSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = $this->code($file);

            foreach ([
                'ExaminationPaper::create', 'ExaminationPaper::query()->create',
                'ExaminationPaper::insert', 'ExaminationPaper::updateOrCreate',
                '->forceFill(', '->save()', '->delete()',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every write to ExaminationPaperService.');
            }
        }
    }

    #[Test]
    public function no_lock_or_overlap_machinery_was_introduced(): void
    {
        // Overlapping sittings across different SubjectOfferings are
        // PERMITTED, so there is no multi-row invariant and therefore
        // nothing to lock.
        foreach ($this->paperSources() as $file) {
            $code = $this->code($file);

            foreach (['TenantLock', 'lockForUpdate', 'advisory', 'pg_advisory'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must introduce no locking -- ExaminationPaper has no multi-row invariant.');
            }
        }
    }

    #[Test]
    public function no_marks_grade_scale_or_result_machinery_was_introduced(): void
    {
        foreach ($this->paperSources() as $file) {
            $code = $this->code($file);

            foreach (['GradeScale', 'StudentMark', 'passMarks', 'pass_marks', 'resultStatus', 'ReportCard', 'Transcript'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not introduce '.$forbidden.' -- marks/results/report cards/transcripts are later checkpoints.');
            }
        }
    }

    #[Test]
    public function the_status_vocabulary_is_closed_and_is_not_a_state_machine(): void
    {
        $this->assertSame(['active', 'inactive'], ExaminationPaper::STATUSES);

        foreach (['draft', 'scheduled', 'completed', 'closed', 'cancelled', 'published'] as $forbidden) {
            $this->assertNotContains($forbidden, ExaminationPaper::STATUSES,
                'ExaminationPaper is deliberately NOT a multi-state lifecycle machine.');
        }
    }

    #[Test]
    public function the_registered_route_surface_is_exactly_the_sanctioned_one(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($r) {
                $action = $r->getAction('controller');
                if (! is_string($action)) {
                    return false;
                }
                [$controller] = explode('@', $action, 2) + [null];

                return $controller === ApiExaminationPaperController::class || $controller === WebExaminationPaperController::class;
            })
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /api/v1/schools/{school}/examination-papers/{examinationPaper}',
            'GET /api/v1/schools/{school}/examinations/{examination}/examination-papers',
            'GET /app/examinations/{examination}/papers',
            'PATCH /api/v1/schools/{school}/examination-papers/{examinationPaper}',
            'PATCH /app/examinations/{examination}/papers/{examinationPaper}',
            'POST /api/v1/schools/{school}/examinations/{examination}/examination-papers',
            'POST /app/examinations/{examination}/papers',
        ], $routes, 'Four API operations plus three web routes -- no delete and no activate/deactivate route.');

        $api = array_filter($routes, fn ($r) => str_contains($r, '/api/'));
        $web = array_filter($routes, fn ($r) => ! str_contains($r, '/api/'));

        $this->assertCount(4, $api);
        $this->assertCount(3, $web);
        $this->assertCount(7, $routes);

        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $route);
            $this->assertStringNotContainsStringIgnoringCase('activate', $route);
            $this->assertStringNotContainsStringIgnoringCase('archive', $route);
        }
    }
}
