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
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['learning_content'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'subject_offering_id', 'title', 'description',
            'sequence', 'status', 'created_at', 'updated_at',
            // TCH.5B (ADR 0063 section 35): the immutable owner Employee of a
            // teacher-owned row (NULL = Offering-wide) and its creating
            // transaction id -- the reviewed exception to the rule below.
            'owner_employee_id', 'ownership_txid',
            // E21-RH.7 (ADR 0066 §15): the database-recorded retention anchor (no person or grading data).
            'retention_recorded_at',
        ], $columns, 'The learning_content column set is closed and reviewed; adding one is an architecture decision.');

        foreach ([
            // Person identity beyond the one reviewed owner column (the row is
            // Sensitive since TCH.5B; no other person may be named on it).
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
            // External LMS integration -- unscoped by ADR 0039.
            'lti_id', 'onerosterId', 'oneroster_id', 'scorm_package_id',
            'external_id', 'external_source',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on learning_content -- see ADR 0039 / docs/modules/LMS.md.");
        }
    }

    #[Test]
    public function no_submission_table_exists(): void
    {
        // `assignments` is DELIBERATELY not checked here any more --
        // Phase 0I.3 legitimately added it (see
        // Tests\Feature\LMS\AssignmentArchitectureGuardTest, which owns
        // that table's own closed-column-set proof). Submission remains
        // blocked on ADR 0039 §4's legal-review gate.
        $tables = collect(DB::connection('pgsql_admin')->select(
            "select tablename from pg_tables where schemaname = 'public'",
        ))->pluck('tablename')->all();

        foreach (['submissions', 'lms_submissions'] as $forbidden) {
            $this->assertNotContains($forbidden, $tables,
                "`{$forbidden}` must not exist -- ADR 0039's Submission legal-review gate is not bypassed.");
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
                    basename($file).' must emit ZERO domain events in this checkpoint -- ADR 0039 §10 events are illustrative future work only.');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_model(): void
    {
        // Deliberately checks only `LearningContent::`-qualified static
        // write calls, NOT the generic `->forceFill(`/`->save()`/
        // `->delete()` patterns this test originally used in Phase
        // 0I.2 -- those generic patterns started producing false
        // positives once Phase 0I.3 added a SIBLING aggregate
        // (AssignmentService.php) to the same `App\Domain\LMS`
        // namespace, which legitimately calls them for ITS OWN model.
        // `AssignmentArchitectureGuardTest::only_the_application_service_writes_the_assignment_model()`
        // is the identical, correctly-scoped counterpart for Assignment.
        $service = app_path('Domain/LMS/Application/LearningContentService.php');

        foreach ($this->moduleSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach ([
                'LearningContent::create', 'LearningContent::query()->create',
                'LearningContent::insert', 'LearningContent::updateOrCreate',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every LearningContent write to LearningContentService.');
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
            // TCH.5C: the owned teacher surface (ADR 0063 section 36).
            'GET /api/v1/schools/{school}/my/learning-content',
            'GET /api/v1/schools/{school}/my/learning-content-contexts',
            'GET /api/v1/schools/{school}/my/learning-content/{learningContent}',
            'GET /api/v1/schools/{school}/subject-offerings/{subjectOffering}/learning-content',
            'GET /app/learning-content',
            'GET /app/my-learning-content',
            'PATCH /api/v1/schools/{school}/learning-content/{learningContent}',
            'PATCH /api/v1/schools/{school}/my/learning-content/{learningContent}',
            'PATCH /app/learning-content/{learningContent}',
            'PATCH /app/my-learning-content/{learningContent}',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/archive',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/documents',
            'POST /api/v1/schools/{school}/learning-content/{learningContent}/publish',
            'POST /api/v1/schools/{school}/my/learning-content',
            'POST /api/v1/schools/{school}/my/learning-content/{learningContent}/archive',
            'POST /api/v1/schools/{school}/my/learning-content/{learningContent}/publish',
            'POST /api/v1/schools/{school}/subject-offerings/{subjectOffering}/learning-content',
            'POST /app/learning-content',
            'POST /app/learning-content/{learningContent}/archive',
            'POST /app/learning-content/{learningContent}/publish',
            'POST /app/my-learning-content',
            'POST /app/my-learning-content/{learningContent}/archive',
            'POST /app/my-learning-content/{learningContent}/publish',
        ], $routes, 'Six Tier 1 API operations (plus two Documents owner-arm routes), seven owned /my/ operations (TCH.5C) and ten web routes -- no delete, no Assignment or Submission route.');
    }
}
