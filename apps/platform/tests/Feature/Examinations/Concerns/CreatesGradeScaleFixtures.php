<?php

namespace Tests\Feature\Examinations\Concerns;

use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0H.4C fixture helpers. `Tests\Concerns\CreatesTenancyFixtures`
 * is used read-only here, never modified.
 */
trait CreatesGradeScaleFixtures
{
    use CreatesTenancyFixtures;

    /**
     * @return array{school: School, actor: User}
     */
    protected function gradeScaleWorld(): array
    {
        $school = $this->createSchool();

        return [
            'school' => $school,
            'actor' => $this->fullGradeScaleActor($school),
        ];
    }

    /**
     * Builds a GradeScale directly, bypassing the service, so a test
     * can arrange an EXISTING row without depending on the very write
     * path it is about to exercise.
     */
    protected function createGradeScale(School $school, array $attributes = []): GradeScale
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => GradeScale::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createGradeBand(GradeScale $scale, array $attributes = []): GradeBand
    {
        return app(TenantContext::class)->withSchool(
            $scale->school,
            fn () => GradeBand::factory()->create(array_merge([
                'school_id' => $scale->school_id,
                'grade_scale_id' => $scale->id,
            ], $attributes)),
        );
    }

    protected function fullGradeScaleActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'examinations.grade_scales.view', 'examinations.grade_scales.manage',
        ]);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function inGradeScaleSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
