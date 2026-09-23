<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Infrastructure\Assignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0I.3 architecture boundaries, enforced as tests rather than as
 * prose a later checkpoint can quietly drift from. Mirrors
 * Tests\Feature\LMS\LearningContentArchitectureGuardTest.
 *
 * ALSO the scope-regression guard the Phase 0I.3 brief explicitly
 * requires: proves this checkpoint introduced no Submission model/
 * table, no submission Documents owner arm, no grading/scoring field,
 * no StudentMark coupling, no generic Course aggregate, and no
 * external LMS integration surface anywhere in the codebase.
 */
class AssignmentArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function moduleSources(): array
    {
        $files = [];
        foreach ([
            app_path('Domain/LMS'),
            app_path('Http/Controllers/App/LMS'),
        ] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ([
            resource_path('js/Pages/App/LMS/Index.vue'),
            resource_path('js/Pages/App/LMS/Assignments/Index.vue'),
        ] as $vue) {
            $files[] = $vue;
        }

        return $files;
    }

    #[Test]
    public function the_table_carries_no_person_grading_or_external_lms_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['assignments'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'subject_offering_id', 'title', 'instructions',
            'due_on', 'status', 'created_at', 'updated_at',
        ], $columns, 'The assignments column set is closed and reviewed; adding one is an architecture decision.');

        foreach ([
            // Person identity -- what keeps this row Confidential.
            'teacher_id', 'employee_id', 'user_id', 'created_by_employee_id',
            'student_id', 'student_enrollment_id', 'student_subject_enrollment_id',
            // Forbidden cross-module dependencies.
            'timetable_entry_id', 'attendance_session_id', 'attendance_record_id',
            'academic_term_id', 'syllabus_unit_id', 'curriculum_delivery_id',
            // Grading (Examinations' scope).
            'marks', 'score', 'points', 'percentage', 'grade', 'weight', 'rubric',
            'assessment_id', 'grade_scale_id', 'result_status', 'pass_fail',
            // Submission (future, separately legal-review-gated checkpoint).
            'submission_id', 'learning_content_id', 'sequence',
            // External LMS integration -- unscoped by ADR 0039.
            'lti_id', 'oneroster_id', 'scorm_package_id', 'external_id', 'external_source',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on assignments -- see ADR 0039 / docs/modules/LMS.md.");
        }
    }

    #[Test]
    public function no_submission_grade_or_course_table_exists(): void
    {
        $tables = collect(DB::connection('pgsql_admin')->select(
            "select tablename from pg_tables where schemaname = 'public'",
        ))->pluck('tablename')->all();

        foreach ([
            'submissions', 'lms_submissions', 'lms_grades', 'lms_marks',
            'lms_courses', 'courses', 'lms_sections', 'lms_rosters',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $tables,
                "`{$forbidden}` must not exist -- Phase 0I.3 implements Assignments only (ADR 0039's Submission legal-review gate is not bypassed).");
        }
    }

    #[Test]
    public function the_documents_table_carries_no_submission_owner_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['documents'],
        ))->pluck('column_name')->all();

        $this->assertContains('assignment_id', $columns, 'The assignment owner arm must exist.');
        $this->assertNotContains('submission_id', $columns,
            'submission_id must not exist on documents -- blocked on ADR 0039 §4\'s legal-review gate until Phase 0I.4.');
    }

    #[Test]
    public function the_module_references_no_forbidden_domain_or_external_lms_standard(): void
    {
        foreach ($this->moduleSources() as $file) {
            $source = file_get_contents($file);
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            foreach ([
                'App\\Domain\\Timetable',
                'App\\Domain\\Attendance',
                'App\\Domain\\Examinations',
                'App\\Domain\\CurriculumDelivery',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not reference '.$forbidden.' -- LMS depends outward on Academic Structure and Students/SIS only (ADR 0039).');
            }

            foreach (['LTI', 'OneRoster', 'SCORM', 'QTI', 'xAPI', 'CommonCartridge', 'Caliper', 'Canvas', 'Moodle'] as $standard) {
                $this->assertStringNotContainsString($standard, $code,
                    basename($file).' must not reference the external LMS standard/vendor '.$standard.' -- unscoped by ADR 0039.');
            }
        }
    }

    #[Test]
    public function the_module_dispatches_no_domain_event(): void
    {
        foreach ($this->moduleSources() as $file) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach (['Event::dispatch', '::dispatch(', 'DomainEvent', 'domain_event_outbox', 'WebhookEventRegistry'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must emit ZERO domain events in this checkpoint -- ADR 0039 §11 events remain illustrative future work only.');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_assignment_model(): void
    {
        $service = app_path('Domain/LMS/Application/AssignmentService.php');

        foreach ($this->moduleSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach ([
                'Assignment::create', 'Assignment::query()->create',
                'Assignment::insert', 'Assignment::updateOrCreate',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every Assignment write to AssignmentService.');
            }
        }
    }

    #[Test]
    public function the_status_vocabulary_is_closed_to_exactly_three_states(): void
    {
        $this->assertSame(['draft', 'published', 'closed'], Assignment::STATUSES);
    }

    #[Test]
    public function the_registered_api_route_surface_is_exactly_the_sanctioned_one(): void
    {
        // Filtered by CONTROLLER, not by the literal substring
        // "assignments" in the URI -- Transport/HR/Hostel/Payroll all
        // have their own, wholly unrelated "assignment" concepts
        // (TransportRouteAssignment, EmployeeAssignment,
        // HostelResidencyAssignment, CompensationAssignment) whose
        // routes also happen to contain that word.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($r) {
                $action = $r->getAction('controller');

                return is_string($action) && (
                    str_contains($action, 'LMS\Http\Controllers\AssignmentController')
                    || (str_contains($action, 'Documents\Http\Controllers\DocumentController') && str_contains($r->uri(), 'assignments'))
                );
            })
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /api/v1/schools/{school}/assignments/{assignment}',
            'GET /api/v1/schools/{school}/assignments/{assignment}/documents',
            'GET /api/v1/schools/{school}/subject-offerings/{subjectOffering}/assignments',
            'PATCH /api/v1/schools/{school}/assignments/{assignment}',
            'POST /api/v1/schools/{school}/assignments/{assignment}/close',
            'POST /api/v1/schools/{school}/assignments/{assignment}/documents',
            'POST /api/v1/schools/{school}/assignments/{assignment}/publish',
            'POST /api/v1/schools/{school}/subject-offerings/{subjectOffering}/assignments',
        ], $routes, 'Six API operations plus two Documents owner-arm routes -- no delete, no submit/grade/score route.');
    }

    #[Test]
    public function the_registered_web_route_surface_is_exactly_the_sanctioned_one(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'app/assignments'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /app/assignments',
            'PATCH /app/assignments/{assignment}',
            'POST /app/assignments',
            'POST /app/assignments/{assignment}/close',
            'POST /app/assignments/{assignment}/publish',
        ], $routes);
    }
}
