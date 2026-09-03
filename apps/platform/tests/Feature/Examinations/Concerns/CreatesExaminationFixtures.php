<?php

namespace Tests\Feature\Examinations\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0H.4A fixture helpers. `Tests\Concerns\CreatesTenancyFixtures`
 * is USED read-only here, never modified -- the same discipline
 * CreatesSyllabusFixtures/CreatesCurriculumDeliveryFixtures followed.
 *
 * The AcademicYear spans today-6mo..today+6mo by default, deliberately
 * RELATIVE rather than a fixed literal pair: this checkpoint's whole
 * point is that FUTURE examination dates are valid, so the fixtures must
 * always have real future room inside the year. A hardcoded window would
 * quietly become a time bomb the moment the wall clock passed it -- the
 * exact failure mode AnnouncementSchedulingTimezoneTest demonstrates.
 */
trait CreatesExaminationFixtures
{
    use CreatesTenancyFixtures;

    /**
     * @return array{school: School, year: AcademicYear, actor: User}
     */
    protected function examinationWorld(array $yearAttributes = []): array
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, array_merge([
            'status' => 'active',
            'starts_on' => $this->today()->subMonths(6)->toDateString(),
            'ends_on' => $this->today()->addMonths(6)->toDateString(),
        ], $yearAttributes));

        return [
            'school' => $school,
            'year' => $year,
            'actor' => $this->fullExaminationActor($school),
        ];
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay();
    }

    /**
     * Builds an Examination directly, bypassing the service, so a test
     * can arrange an EXISTING row without depending on the very write
     * path it is about to exercise.
     */
    protected function createExamination(AcademicYear $year, array $attributes = []): Examination
    {
        return app(TenantContext::class)->withSchool(
            $year->school,
            fn () => Examination::factory()->create(array_merge([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
            ], $attributes)),
        );
    }

    protected function fullExaminationActor(School $school): User
    {
        return $this->createUserWithCapabilities($school, [
            'examinations.definitions.view',
            'examinations.definitions.manage',
        ]);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function inExaminationSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
