<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.3B architecture boundaries, enforced as tests rather than
 * as prose that a later checkpoint can quietly drift from. Mirrors
 * Tests\Feature\Attendance\AttendanceArchitectureGuardTest.
 *
 * Targeted at the boundaries that actually matter -- a forbidden
 * column, a forbidden dependency, a write path that bypasses the
 * service, or an unsanctioned route -- never at formatting.
 */
class CurriculumDeliveryArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function moduleSources(): array
    {
        $files = [];
        foreach ([
            app_path('Domain/CurriculumDelivery'),
            app_path('Http/Controllers/App/CurriculumDelivery'),
        ] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files[] = resource_path('js/Pages/App/CurriculumDelivery/Index.vue');

        return $files;
    }

    #[Test]
    public function the_table_carries_no_person_lesson_lms_or_examinations_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['curriculum_deliveries'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'section_id', 'syllabus_unit_id', 'subject_offering_id',
            'academic_year_id', 'campus_id', 'grade_level_id',
            'started_on', 'completed_on', 'status', 'created_at', 'updated_at',
        ], $columns, 'The curriculum_deliveries column set is closed and reviewed; adding one is an architecture decision.');

        foreach ([
            // Person identity -- what keeps this row Confidential.
            'teacher_id', 'employee_id', 'user_id', 'student_id',
            'student_enrollment_id', 'student_subject_enrollment_id',
            // Forbidden dependencies.
            'timetable_entry_id', 'attendance_session_id', 'attendance_record_id', 'academic_term_id',
            // Lesson Planning.
            'notes', 'description', 'objective', 'objectives', 'resources', 'homework',
            'instructional_method', 'lesson_count', 'periods_used', 'hours_taught', 'sequence',
            // LMS / Documents.
            'attachment_id', 'document_id', 'file_path', 'assignment_id', 'submission_id',
            // Examinations.
            'marks', 'score', 'grade', 'weight', 'assessment_id', 'grade_scale_id', 'result_status',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on curriculum_deliveries -- see docs/modules/ACADEMICS.md.");
        }
    }

    #[Test]
    public function the_module_references_no_forbidden_domain(): void
    {
        foreach ($this->moduleSources() as $file) {
            $source = file_get_contents($file);
            // Strip comments/docblocks: this module's own docblocks
            // legitimately NAME the things it must not depend on.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            foreach ([
                'App\\Domain\\Timetable',
                'App\\Domain\\Attendance',
                'App\\Domain\\StudentEnrollment',
                'App\\Domain\\Students',
                'App\\Domain\\Documents',
                'App\\Domain\\Examinations',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not reference '.$forbidden.' -- Curriculum Delivery depends outward on Academic Structure and Syllabus only.');
            }

            foreach (['AcademicTerm', 'TimetableEntry', 'AttendanceSession', 'StudentEnrollment'] as $forbiddenClass) {
                $this->assertStringNotContainsString($forbiddenClass, $code,
                    basename($file).' must not use '.$forbiddenClass.'.');
            }
        }
    }

    #[Test]
    public function the_module_dispatches_no_domain_event(): void
    {
        foreach ($this->moduleSources() as $file) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach (['event(', 'Event::dispatch', '::dispatch(', 'DomainEvent', 'domain_event_outbox', 'WebhookEventRegistry'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must emit ZERO domain events -- no consumer exists (CLAUDE.md rule 2).');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_model(): void
    {
        $service = app_path('Domain/CurriculumDelivery/Application/CurriculumDeliveryService.php');

        foreach ($this->moduleSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));

            foreach ([
                'CurriculumDelivery::create', 'CurriculumDelivery::query()->create',
                'CurriculumDelivery::insert', 'CurriculumDelivery::updateOrCreate',
                '->forceFill(', '->save()', '->delete()',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every write to CurriculumDeliveryService.');
            }
        }
    }

    #[Test]
    public function the_status_vocabulary_is_closed_and_excludes_not_started(): void
    {
        $this->assertSame(['in_progress', 'completed'], CurriculumDelivery::STATUSES);
        $this->assertNotContains('not_started', CurriculumDelivery::STATUSES,
            'not_started is the ABSENCE of a row, never a stored value -- consumers must LEFT JOIN from syllabus_units.');
    }

    #[Test]
    public function the_registered_route_surface_is_exactly_the_sanctioned_one(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'curriculum-deliver') || str_contains($r->uri(), 'syllabus-delivery'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'GET /api/v1/schools/{school}/curriculum-deliveries/{curriculumDelivery}',
            'GET /api/v1/schools/{school}/my/curriculum-deliveries',
            'GET /api/v1/schools/{school}/my/curriculum-deliveries/{curriculumDelivery}',
            'GET /api/v1/schools/{school}/my/curriculum-delivery-contexts',
            'GET /api/v1/schools/{school}/subject-offerings/{subjectOffering}/curriculum-deliveries',
            'GET /app/my-curriculum-delivery',
            'GET /app/syllabus-delivery',
            'PATCH /api/v1/schools/{school}/curriculum-deliveries/{curriculumDelivery}',
            'PATCH /api/v1/schools/{school}/my/curriculum-deliveries/{curriculumDelivery}',
            'PATCH /app/my-curriculum-delivery/{curriculumDelivery}',
            'PATCH /app/syllabus-delivery/{curriculumDelivery}',
            'POST /api/v1/schools/{school}/curriculum-deliveries/{curriculumDelivery}/transition',
            'POST /api/v1/schools/{school}/my/curriculum-deliveries',
            'POST /api/v1/schools/{school}/my/curriculum-deliveries/{curriculumDelivery}/transition',
            'POST /api/v1/schools/{school}/subject-offerings/{subjectOffering}/curriculum-deliveries',
            'POST /app/my-curriculum-delivery',
            'POST /app/my-curriculum-delivery/{curriculumDelivery}/transition',
            'POST /app/syllabus-delivery',
            'POST /app/syllabus-delivery/{curriculumDelivery}/transition',
        ], $routes, 'Tier 1: five API operations plus four web routes. Tier 2 (TCH.3): the owned teacher /my/ family -- six API operations plus four web routes. No delete, archive, bulk, reorder, search, report or Student route.');
    }
}
