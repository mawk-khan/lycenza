<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Application\Coverage\AcademicYearOption;
use App\Domain\CurriculumDelivery\Application\Coverage\CurriculumCoverageCounts;
use App\Domain\CurriculumDelivery\Application\Coverage\OfferingCoverageCounts;
use App\Domain\CurriculumDelivery\Application\Coverage\SectionCoverageCounts;
use App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- Curriculum Delivery's aggregate read contract
 * (CurriculumCoverageReadService): correct unit counts, the same
 * projection rules as the operational page, School isolation, and no
 * person data in its result types.
 */
class CurriculumCoverageReadServiceTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private function service(): CurriculumCoverageReadService
    {
        return app(CurriculumCoverageReadService::class);
    }

    /**
     * Offering with 3 active units (+1 inactive), Sections A and B.
     * A: U1 completed, U2 in progress. B: U1 completed, plus a delivery
     * of the INACTIVE unit which must not count.
     *
     * @return array<string, mixed>
     */
    private function coverageWorld(): array
    {
        $w = $this->deliveryWorld();
        $u2 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'U2', 'sequence' => 2]);
        $u3 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'U3', 'sequence' => 3]);
        $retired = $this->createSyllabusUnitFor($w['offering'], ['code' => 'OLD', 'sequence' => 9, 'status' => 'inactive']);
        $sectionB = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B', 'name' => 'B', 'status' => 'active']);

        $completed = ['status' => CurriculumDelivery::STATUS_COMPLETED, 'completed_on' => $this->today()->subDays(2)->toDateString()];
        $this->createDelivery($w['offering'], $w['section'], $w['unit'], $completed);
        $this->createDelivery($w['offering'], $w['section'], $u2, ['status' => CurriculumDelivery::STATUS_IN_PROGRESS]);
        $this->createDelivery($w['offering'], $sectionB, $w['unit'], $completed);
        $this->createDelivery($w['offering'], $sectionB, $retired, $completed);

        return $w + ['u2' => $u2, 'u3' => $u3, 'sectionB' => $sectionB];
    }

    private function sectionCounts(CurriculumCoverageCounts $result, string $offeringId, string $sectionId): SectionCoverageCounts
    {
        $offering = collect($result->offerings)->firstWhere('subjectOfferingId', $offeringId);
        $this->assertInstanceOf(OfferingCoverageCounts::class, $offering);

        $section = collect($offering->sections)->firstWhere('sectionId', $sectionId);
        $this->assertInstanceOf(SectionCoverageCounts::class, $section);

        return $section;
    }

    #[Test]
    public function it_counts_planned_completed_in_progress_and_not_started_units_per_section(): void
    {
        $w = $this->coverageWorld();

        $result = $this->service()->coverageForAcademicYear($w['school'], $w['year']->id);

        $this->assertSame($w['year']->id, $result->academicYearId);
        $this->assertCount(1, $result->offerings);
        $this->assertSame(3, $result->offerings[0]->activeSyllabusUnits);
        $this->assertCount(2, $result->offerings[0]->sections);

        $a = $this->sectionCounts($result, $w['offering']->id, $w['section']->id);
        $this->assertSame([3, 1, 1, 1], [$a->plannedUnits, $a->completedUnits, $a->inProgressUnits, $a->notStartedUnits]);

        // The delivery of the since-deactivated unit is not counted,
        // exactly as the operational page no longer lists that unit.
        $b = $this->sectionCounts($result, $w['offering']->id, $w['sectionB']->id);
        $this->assertSame([3, 1, 0, 2], [$b->plannedUnits, $b->completedUnits, $b->inProgressUnits, $b->notStartedUnits]);
    }

    #[Test]
    public function it_applies_the_operational_projection_rules(): void
    {
        $w = $this->coverageWorld();

        // Elective Offering: no Section-wide cohort -> excluded.
        $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'ELEC']),
            ['is_required' => false, 'status' => 'active']);
        // Inactive required Offering -> excluded.
        $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'GONE']),
            ['is_required' => true, 'status' => 'inactive']);
        // Inactive Section -> excluded.
        $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'Z', 'status' => 'inactive']);
        // Section of another grade -> not paired with this Offering.
        $this->createSection($w['year'], $w['campus'], $this->createGradeLevel($w['school'], ['code' => 'OTHER', 'sequence' => 900]), ['code' => 'A']);
        // A required Offering with no syllabus: reported with 0 planned.
        $empty = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'ART']),
            ['is_required' => true, 'status' => 'active']);

        $result = $this->service()->coverageForAcademicYear($w['school'], $w['year']->id);

        $this->assertEqualsCanonicalizing([$w['offering']->id, $empty->id], array_map(fn ($o) => $o->subjectOfferingId, $result->offerings));
        $this->assertCount(2, $result->offerings[0]->sections);

        $art = collect($result->offerings)->firstWhere('subjectOfferingId', $empty->id);
        $this->assertSame(0, $art->activeSyllabusUnits);
        foreach ($art->sections as $section) {
            $this->assertSame([0, 0, 0, 0], [$section->plannedUnits, $section->completedUnits, $section->inProgressUnits, $section->notStartedUnits]);
        }
    }

    #[Test]
    public function another_academic_year_is_not_counted(): void
    {
        $w = $this->coverageWorld();
        $closed = $this->createAcademicYear($w['school'], [
            'status' => 'closed', 'code' => 'OLDYR',
            'starts_on' => $this->today()->subYears(2)->toDateString(),
            'ends_on' => $this->today()->subYears(1)->toDateString(),
        ]);

        $this->assertSame([], $this->service()->coverageForAcademicYear($w['school'], $closed->id)->offerings);
    }

    #[Test]
    public function one_school_never_sees_another_schools_coverage(): void
    {
        $a = $this->coverageWorld();
        $b = $this->coverageWorld();

        $resultA = $this->service()->coverageForAcademicYear($a['school'], $a['year']->id);
        $this->assertSame([$a['offering']->id], array_map(fn ($o) => $o->subjectOfferingId, $resultA->offerings));

        // School B's year id requested under School A: nothing, never B's rows.
        $this->assertSame([], $this->service()->coverageForAcademicYear($a['school'], $b['year']->id)->offerings);

        $yearIds = array_map(fn (AcademicYearOption $y) => $y->id, $this->service()->academicYearOptions($a['school']));
        $this->assertSame([$a['year']->id], $yearIds);
    }

    #[Test]
    public function it_restores_the_callers_tenant_context(): void
    {
        $a = $this->coverageWorld();
        $b = $this->coverageWorld();
        $context = app(TenantContext::class);

        $context->withSchool($b['school'], function () use ($a, $b, $context): void {
            $this->service()->coverageForAcademicYear($a['school'], $a['year']->id);
            $this->assertSame($b['school']->id, $context->schoolId());
        });
    }

    #[Test]
    public function the_result_types_carry_no_person_field(): void
    {
        foreach ([CurriculumCoverageCounts::class, OfferingCoverageCounts::class, SectionCoverageCounts::class, AcademicYearOption::class] as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $this->assertDoesNotMatchRegularExpression(
                    '/student|guardian|employee|teacher|user|actor|staff|enrol|name_of|email|phone/i',
                    $property->getName(),
                    "{$class}::\${$property->getName()} looks like person data -- the coverage contract counts syllabus units only.",
                );
            }
        }
    }
}
