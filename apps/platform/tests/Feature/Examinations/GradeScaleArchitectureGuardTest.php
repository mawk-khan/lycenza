<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Http\Controllers\GradeScaleController as ApiGradeScaleController;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Http\Controllers\App\Examinations\GradeScaleController as WebGradeScaleController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4C architecture boundaries, enforced as tests. Scoped
 * precisely to GradeScale's OWN files, matching
 * ExaminationPaperArchitectureGuardTest's pattern.
 */
class GradeScaleArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function gradeScaleSources(): array
    {
        return [
            app_path('Domain/Examinations/Infrastructure/GradeScale.php'),
            app_path('Domain/Examinations/Infrastructure/GradeBand.php'),
            app_path('Domain/Examinations/Application/GradeScaleService.php'),
            app_path('Domain/Examinations/Http/Controllers/GradeScaleController.php'),
            app_path('Http/Controllers/App/Examinations/GradeScaleController.php'),
            app_path('Domain/Examinations/Application/Exceptions/DuplicateGradeScaleCodeException.php'),
            app_path('Domain/Examinations/Application/Exceptions/DuplicateGradeBandThresholdException.php'),
            app_path('Domain/Examinations/Application/Exceptions/GradeScaleIllegalTransitionException.php'),
            app_path('Domain/Examinations/Application/Exceptions/GradeScaleIncompleteException.php'),
            app_path('Domain/Examinations/Application/Exceptions/GradeBandNotMutableException.php'),
            resource_path('js/Pages/App/Examinations/GradeScales/Index.vue'),
        ];
    }

    private function code(string $file): string
    {
        return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
    }

    #[Test]
    public function the_grade_scales_table_carries_no_forbidden_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['grade_scales'],
        ))->pluck('column_name')->all();

        foreach ([
            'academic_year_id', 'examination_id', 'examination_paper_id', 'grade_level_id',
            'student_id', 'section_id',
            'sequence', 'display_order',
            'grade_point', 'is_pass', 'is_passing',
            'description', 'notes', 'remarks',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on grade_scales -- see docs/modules/EXAMINATIONS.md.");
        }
    }

    #[Test]
    public function the_grade_bands_table_carries_no_forbidden_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['grade_bands'],
        ))->pluck('column_name')->all();

        foreach ([
            'max_percentage', 'upper_bound',
            'grade_point', 'is_pass', 'is_passing',
            'student_id', 'examination_paper_id',
            'description', 'notes', 'remarks', 'color', 'sequence',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns,
                "`{$forbidden}` must never exist on grade_bands -- bands store ONLY a lower-bound threshold.");
        }
    }

    #[Test]
    public function no_range_type_exclusion_constraint_or_btree_gist_machinery_was_introduced(): void
    {
        foreach ($this->gradeScaleSources() as $file) {
            $code = $this->code($file);

            foreach (['numrange', 'daterange', 'tsrange', 'EXCLUDE USING', 'btree_gist', 'gist'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $code,
                    basename($file).' must not introduce '.$forbidden.' -- the threshold-only design deliberately needs neither.');
            }
        }
    }

    #[Test]
    public function the_module_references_no_forbidden_domain(): void
    {
        foreach ($this->gradeScaleSources() as $file) {
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
                    basename($file).' must not reference '.$forbidden.' -- GradeScale is a standalone, School-owned reference entity.');
            }

            foreach (['StudentMark', 'ReportCard', 'Transcript', 'Examination::', 'ExaminationPaper::'] as $forbiddenClass) {
                $this->assertStringNotContainsString($forbiddenClass, $code,
                    basename($file).' must not use '.$forbiddenClass.' -- GradeScale is wholly independent of the Examination chain.');
            }
        }
    }

    #[Test]
    public function the_module_dispatches_no_domain_event(): void
    {
        foreach ($this->gradeScaleSources() as $file) {
            $code = $this->code($file);

            foreach (['event(', 'Event::dispatch', '::dispatch(', 'DomainEvent', 'domain_event_outbox', 'WebhookEventRegistry'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must emit ZERO domain events -- no consumer exists (CLAUDE.md rule 2).');
            }
        }
    }

    #[Test]
    public function only_the_application_service_writes_the_models(): void
    {
        $service = app_path('Domain/Examinations/Application/GradeScaleService.php');

        foreach ($this->gradeScaleSources() as $file) {
            if ($file === $service) {
                continue;
            }

            $code = $this->code($file);

            foreach ([
                'GradeScale::create', 'GradeScale::query()->create', 'GradeScale::updateOrCreate',
                'GradeBand::create', 'GradeBand::query()->create', 'GradeBand::updateOrCreate',
                '->forceFill(', '->save()', '->delete()',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must delegate every write to GradeScaleService.');
            }
        }
    }

    #[Test]
    public function no_tenant_lock_or_advisory_lock_was_introduced(): void
    {
        // The corrected architecture uses parent-row `lockForUpdate()`
        // ONLY -- never a School-wide TenantLock (which would
        // needlessly serialize unrelated GradeScales) and never an
        // advisory lock.
        foreach ($this->gradeScaleSources() as $file) {
            $code = $this->code($file);

            foreach (['TenantLock', 'advisory', 'pg_advisory'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($file).' must not introduce '.$forbidden.' -- see ADR 0035\'s corrected concurrency protocol.');
            }
        }
    }

    #[Test]
    public function the_service_uses_row_level_locking_for_every_mutating_operation_on_an_existing_scale(): void
    {
        $service = $this->code(app_path('Domain/Examinations/Application/GradeScaleService.php'));

        $this->assertSame(4, substr_count($service, 'lockForUpdate()'),
            'Exactly four methods mutate an existing GradeScale (update, addBand, updateBand, removeBand); each must reload it with lockForUpdate().');
    }

    #[Test]
    public function the_status_vocabulary_is_closed(): void
    {
        $this->assertSame(['draft', 'active', 'inactive'], GradeScale::STATUSES);

        foreach (['archived', 'closed', 'scheduled', 'published', 'suspended'] as $forbidden) {
            $this->assertNotContains($forbidden, GradeScale::STATUSES);
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

                return $controller === ApiGradeScaleController::class || $controller === WebGradeScaleController::class;
            })
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame([
            'DELETE /api/v1/schools/{school}/grade-scales/{gradeScale}/bands/{gradeBand}',
            'DELETE /app/examinations/grade-scales/{gradeScale}/bands/{gradeBand}',
            'GET /api/v1/schools/{school}/grade-scales',
            'GET /api/v1/schools/{school}/grade-scales/{gradeScale}',
            'GET /app/examinations/grade-scales',
            'PATCH /api/v1/schools/{school}/grade-scales/{gradeScale}',
            'PATCH /api/v1/schools/{school}/grade-scales/{gradeScale}/bands/{gradeBand}',
            'PATCH /app/examinations/grade-scales/{gradeScale}',
            'PATCH /app/examinations/grade-scales/{gradeScale}/bands/{gradeBand}',
            'POST /api/v1/schools/{school}/grade-scales',
            'POST /api/v1/schools/{school}/grade-scales/{gradeScale}/bands',
            'POST /app/examinations/grade-scales',
            'POST /app/examinations/grade-scales/{gradeScale}/bands',
        ], $routes, 'Seven API operations plus six web routes -- no GradeScale delete route, GradeBand delete is the sole delete route.');

        $api = array_filter($routes, fn ($r) => str_contains($r, '/api/'));
        $web = array_filter($routes, fn ($r) => ! str_contains($r, '/api/'));

        $this->assertCount(7, $api);
        $this->assertCount(6, $web);
        $this->assertCount(13, $routes);

        $deleteRoutes = array_filter($routes, fn ($r) => str_starts_with($r, 'DELETE '));
        $this->assertCount(2, $deleteRoutes, 'Exactly one API and one web DELETE route, both GradeBand removal.');
        foreach ($deleteRoutes as $route) {
            $this->assertStringContainsString('/bands/', $route,
                'The only sanctioned delete is GradeBand removal -- there is no GradeScale delete route.');
        }
    }

    #[Test]
    public function no_examination_or_student_mark_reference_exists_in_the_module(): void
    {
        foreach ($this->gradeScaleSources() as $file) {
            $code = $this->code($file);

            $this->assertStringNotContainsString('examination_id', $code,
                basename($file).' must not reference examination_id -- GradeScale has no Examination parent.');
        }
    }
}
