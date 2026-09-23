<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- the first Analytics read model, executed through the
 * real AnalyticsReadGate: declaration, metrics, dimensions, the one
 * filter, School isolation, and no audit for a Confidential report.
 */
class CurriculumCoverageReadModelTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    /**
     * MATH offering (3 units) x Sections A, B:
     *   A: U1 completed, U2 in progress; B: U1, U2 completed.
     * ART offering: required, no syllabus.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $w = $this->deliveryWorld();
        $u2 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'U2', 'sequence' => 2]);
        $this->createSyllabusUnitFor($w['offering'], ['code' => 'U3', 'sequence' => 3]);
        $sectionB = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B', 'name' => 'B', 'status' => 'active']);
        $done = ['status' => CurriculumDelivery::STATUS_COMPLETED, 'completed_on' => $this->today()->subDay()->toDateString()];
        $this->createDelivery($w['offering'], $w['section'], $w['unit'], $done);
        $this->createDelivery($w['offering'], $w['section'], $u2, ['status' => CurriculumDelivery::STATUS_IN_PROGRESS]);
        $this->createDelivery($w['offering'], $sectionB, $w['unit'], $done);
        $this->createDelivery($w['offering'], $sectionB, $u2, $done);
        $art = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'ART']),
            ['is_required' => true, 'status' => 'active']);

        return $w + ['sectionB' => $sectionB, 'art' => $art,
            'viewer' => $this->createUserWithCapabilities($w['school'], ['analytics.view'])];
    }

    /** @return array<string, mixed> */
    private function read(array $w, array $filters = []): array
    {
        return app(AnalyticsReadGate::class)->read(app(CurriculumCoverageReadModel::class), $w['school'], $w['viewer'], $filters);
    }

    #[Test]
    public function it_declares_a_confidential_non_person_report_with_one_filter(): void
    {
        $declaration = app(CurriculumCoverageReadModel::class)->declaration();

        $this->assertSame('curriculum.coverage', $declaration->key);
        $this->assertFalse($declaration->countsPeople);
        $this->assertSame(ClassificationTier::Confidential, $declaration->tier);
        $this->assertSame(['CurriculumDelivery'], $declaration->sourceModules);
        $this->assertSame(['academic_year_id'], $declaration->filters);
    }

    #[Test]
    public function it_computes_totals_grade_and_offering_metrics_for_the_active_year(): void
    {
        $w = $this->world();

        $report = $this->read($w);

        $this->assertSame($w['year']->id, $report['academicYearId']);
        // 3 units x 2 Sections = 6 planned; 3 completed, 1 in progress.
        $this->assertSame(['planned' => 6, 'completed' => 3, 'inProgress' => 1, 'notStarted' => 2, 'coveragePercent' => '50.0', 'offerings' => 2, 'offeringsWithoutSyllabus' => 1], $report['totals']);
        $this->assertSame([['gradeLevelName' => $w['grade']->name, 'planned' => 6, 'completed' => 3, 'inProgress' => 1, 'notStarted' => 2, 'coveragePercent' => '50.0']], $report['byGradeLevel']);

        $math = collect($report['offerings'])->firstWhere('subjectOfferingId', $w['offering']->id);
        $this->assertSame([3, 2, 6, 3, '50.0'], [$math['activeSyllabusUnits'], $math['sectionCount'], $math['planned'], $math['completed'], $math['coveragePercent']]);
        $a = collect($math['sections'])->firstWhere('sectionId', $w['section']->id);
        $b = collect($math['sections'])->firstWhere('sectionId', $w['sectionB']->id);
        $this->assertSame(['planned' => 3, 'completed' => 1, 'inProgress' => 1, 'notStarted' => 1, 'coveragePercent' => '33.3'], array_intersect_key($a, array_flip(['planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent'])));
        $this->assertSame('66.7', $b['coveragePercent']);

        $art = collect($report['offerings'])->firstWhere('subjectOfferingId', $w['art']->id);
        $this->assertSame([0, 0, null], [$art['activeSyllabusUnits'], $art['planned'], $art['coveragePercent']]);
    }

    #[Test]
    public function the_year_filter_accepts_only_this_schools_years(): void
    {
        $w = $this->world();
        $other = $this->world();
        $closed = $this->createAcademicYear($w['school'], [
            'status' => 'closed', 'code' => 'OLDYR',
            'starts_on' => $this->today()->subYears(2)->toDateString(),
            'ends_on' => $this->today()->subYears(1)->toDateString(),
        ]);

        $report = $this->read($w, ['academic_year_id' => $closed->id]);
        $this->assertSame($closed->id, $report['academicYearId']);
        $this->assertSame([], $report['offerings']);
        $this->assertSame(0, $report['totals']['planned']);

        // Another School's year id: treated as not found, never its data.
        $foreign = $this->read($w, ['academic_year_id' => $other['year']->id]);
        $this->assertNull($foreign['academicYearId']);
        $this->assertSame([], $foreign['offerings']);
        $this->assertEqualsCanonicalizing([$w['year']->id, $closed->id], array_column($foreign['academicYears'], 'id'));
    }

    #[Test]
    public function a_confidential_report_read_is_not_access_audited(): void
    {
        $w = $this->world();
        $this->read($w);

        $this->assertSame(0, app(TenantContext::class)->withSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'analytics.report_viewed')->count()));
    }

    #[Test]
    public function the_report_payload_contains_no_person_keys(): void
    {
        $w = $this->world();
        $json = json_encode($this->read($w), JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/"[^"]*(student|guardian|employee|teacher|user|email|phone)[^"]*"\s*:/i', $json);
    }

    #[Test]
    public function percentages_use_integer_half_up_rounding(): void
    {
        $this->assertNull(CurriculumCoverageReadModel::percent(0, 0));
        $this->assertSame('0.0', CurriculumCoverageReadModel::percent(0, 7));
        $this->assertSame('100.0', CurriculumCoverageReadModel::percent(7, 7));
        $this->assertSame('33.3', CurriculumCoverageReadModel::percent(1, 3));
        $this->assertSame('66.7', CurriculumCoverageReadModel::percent(2, 3));
        $this->assertSame('12.5', CurriculumCoverageReadModel::percent(1, 8));
        $this->assertSame('0.1', CurriculumCoverageReadModel::percent(1, 1999));
    }
}
