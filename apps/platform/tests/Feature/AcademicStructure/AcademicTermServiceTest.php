<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOutOfRangeException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOverlapException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D sections 19-21: Term dates must fall within the parent
 * Academic Year, and Terms within the same year must not overlap.
 */
class AcademicTermServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_term_beginning_before_the_academic_year_is_rejected(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);

        $this->expectException(AcademicTermOutOfRangeException::class);

        app(AcademicTermService::class)->create($year, [
            'name' => 'Term 1', 'code' => 'T1',
            'starts_on' => '2026-05-01', 'ends_on' => '2026-09-01',
            'sequence' => 1,
        ]);
    }

    #[Test]
    public function a_term_ending_after_the_academic_year_is_rejected(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);

        $this->expectException(AcademicTermOutOfRangeException::class);

        app(AcademicTermService::class)->create($year, [
            'name' => 'Term 1', 'code' => 'T1',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-06-30',
            'sequence' => 1,
        ]);
    }

    #[Test]
    public function overlapping_terms_in_the_same_year_are_rejected(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $service = app(AcademicTermService::class);

        $service->create($year, [
            'name' => 'Term 1', 'code' => 'T1',
            'starts_on' => '2026-06-01', 'ends_on' => '2026-10-01',
            'sequence' => 1,
        ]);

        $this->expectException(AcademicTermOverlapException::class);

        $service->create($year, [
            'name' => 'Term 2', 'code' => 'T2',
            'starts_on' => '2026-09-01', 'ends_on' => '2027-01-01',
            'sequence' => 2,
        ]);
    }

    #[Test]
    public function non_overlapping_terms_within_range_succeed(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $service = app(AcademicTermService::class);

        $term1 = $service->create($year, [
            'name' => 'Term 1', 'code' => 'T1',
            'starts_on' => '2026-06-01', 'ends_on' => '2026-10-01',
            'sequence' => 1,
        ]);

        $term2 = $service->create($year, [
            'name' => 'Term 2', 'code' => 'T2',
            'starts_on' => '2026-10-01', 'ends_on' => '2027-05-31',
            'sequence' => 2,
        ]);

        $this->assertNotSame($term1->id, $term2->id);
    }
}
