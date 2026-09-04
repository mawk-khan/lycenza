<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Infrastructure\LearningContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0I.2 architecture boundaries, enforced as tests rather than as
 * prose a later checkpoint can quietly drift from. Mirrors
 * Tests\Feature\CurriculumDelivery\CurriculumDeliveryArchitectureGuardTest/
 * Tests\Feature\Attendance\AttendanceArchitectureGuardTest.
 *
 * ALSO the scope-regression guard the Phase 0I.2 brief explicitly
 * requires: proves this checkpoint introduced no Assignment, no
 * Submission, no grading field, and no external LMS integration
 * surface anywhere in the codebase, not merely in this module's own
 * directory.
 */
class LearningContentArchitectureGuardTest extends TestCase
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

        $files[] = resource_path('js/Pages/App/LMS/Index.vue');

        return $files;
    }

    #[Test]
    public function the_table_carries_no_person_grading_or_external_lms_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['learning_content'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'subject_offering_id', 'title', 'description',
            'sequence', 'status', 'created_at', 'updated_at',
        ], $columns, 'The learning_content column set is closed and reviewed; adding one is an architecture decision.');

        foreach ([
            // Person identity -- what keeps this row Confidential.
            'teacher_id', 'employee_id', 'user_id', 'created_by_employee_id',
            'student_id', 'student_enrollment_id', 'student_subject_enrollment_id',
            // Forbidden cross-module dependencies.
            'timetable_entry_id', 'attendance_session_id', 'attendance_record_id',
            'academic_term_id', 'syllabus_unit_id', 'curriculum_delivery_id',
            // Grading (Examinations' scope).
            'marks', 'score', 'grade', 'weight', 'rubric', 'assessment_id',
            'grade_scale_id', 'result_status',
            // Assignment/Submission (future, separately-gated LMS checkpoints).
            'assignment_id', 'submission_id', 'due_date', 'feedback',
            // External LMS integration -- unscoped by ADR 0037.
            'lti_id', 'onerosterId', 'oneroster_id', 'scorm_package_id',
            'external_id', 'external_source',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on learning_content -- see ADR 0037 / docs/modules/LMS.md.");
        }
    }

    #[Test]
    public function no_assignment_or_submission_table_exists(): void
    {
        $tables = collect(DB::connection('pgsql_admin')->select(
            "select tablename from pg_tables where schemaname = 'public'",
        ))->pluck('tablename')->all();

        foreach (['assignments', 'submissions', 'lms_assignments', 'lms_submissions'] as $forbidden) {
            $this->assertNotContains($forbidden, $tables,
                "`{$forbidden}` must not exist -- Phase 0I.2 implements Learning Content only (ADR 0037's Submission legal-review gate is not bypassed).");
        }
    }

    #[Test]
    public function the_module_references_no_forbidden_domain_or_external_lms_standard(): void
    {
        foreach ($this->moduleSources() as $file) {
            $source = file_get_contents($file);
            // Strip comments/docblocks: this module's own docblocks
            // legitimately NAME the things it must not depend on.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            foreach ([
                'App\\Domain\\Timetable',
                'App\\Domain\\Attendance',
                'App\\Domain\\Examinations',
                'App\\Domain\\CurriculumDelivery',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not reference '.$forbidden.' -- LMS depends outward on Academic Structure and Students/SIS only (ADR 0037).');
            }

            foreach (['LTI', 'OneRoster', 'SCORM', 'QTI', 'xAPI', 'CommonCartridge', 'Caliper', 'Canvas', 'Moodle'] as $standard) {
                $this->assertStringNotContainsString($standard, $code,
                    basename($file).' must not reference the external LMS standard/vendor '.$standard.' -- unscoped by ADR 0037.');
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
                    basename($file).' must emit ZERO domain events in this checkpoint -- ADR 0037 §10 events are illustrative future work only.');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_model(): void
    {
        $service = app_path('Domain/LMS/Application/LearningContentService.php');

        foreach ($this->moduleSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach ([
                'LearningContent::create', 'LearningContent::query()->create',
                'LearningContent::insert', 'LearningContent::updateOrCreate',
                '->forceFill(', '->save()', '->delete()',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every write to LearningContentService.');
            }
        }
    }

    #[Test]
    public function the_status_vocabulary_is_closed_to_exactly_three_states(): void
    {
        $this->assertSame(['draft', 'published', 'archived'], LearningContent::STATUSES);
    }

    #[Test]
    public function the_registered_api_route_surface_is_exactly_the_sanctioned_one(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'learning-content'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /api/v1/schools/{school}/learning-content/{learningContent}',
            'GET /api/v1/schools/{school}/learning-content/{learningContent}/documents',
            'GET /api/v1/schools/{school}/subject-offerings/{subjectOffering}/learning-content',
            'GET /app/learning-content',
            'PATCH /api/v1/schools/{school}/learning-content/{learningContent}',
            'PATCH /app/learning-content/{learningContent}',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/archive',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/documents',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/publish',
            'POST /api/v1/schools/{school}/subject-offerings/{subjectOffering}/learning-content',
            'POST /app/learning-content',
            'POST /app/learning-content/{learningContent}/archive',
            'POST /app/learning-content/{learningContent}/publish',
        ], $routes, 'Six API operations (plus two Documents owner-arm routes) and five web routes -- no delete, no Assignment or Submission route.');
    }
}
