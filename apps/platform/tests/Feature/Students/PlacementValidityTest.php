<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-2 (ADR 0076 §46, finding F-2): every NEW placement -- enroll,
 * transfer, rollover execution, Admissions conversion -- needs an active
 * Student in an active Section on an active Campus of the same School.
 * Data validity, never authorization (these whole-School paths keep their
 * capabilities). A future (draft) year is a valid target.
 */
class PlacementValidityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private AcademicYear $next;

    private GradeLevel $grade;

    private GradeLevel $grade6;

    private Section $sectionA;

    private Section $sectionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->grade = $this->createGradeLevel($this->school, ['code' => 'G5', 'sequence' => 5]);
        $this->grade6 = $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        $this->sectionA = $this->createSection($this->year, $this->a, $this->grade, ['code' => 'A', 'status' => 'active']);
        $this->sectionB = $this->createSection($this->year, $this->b, $this->grade, ['code' => 'B', 'status' => 'active']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function enroll(Student $student, Section $section, string $roll = '1'): StudentEnrollment
    {
        return app(StudentEnrollmentService::class)->enroll($student, $section, $roll, '2026-06-02');
    }

    private function refusedWith(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail("expected a refusal ({$reason})");
        } catch (PlacementTargetUnavailableException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame('PLACEMENT_TARGET_UNAVAILABLE', $e->errorCode());
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    #[Test]
    public function enroll_needs_an_active_student_section_and_campus(): void
    {
        $inactive = $this->createStudent($this->school, ['status' => 'inactive']);
        $this->refusedWith(PlacementTargetUnavailableException::STUDENT_INACTIVE, fn () => $this->enroll($inactive, $this->sectionA));

        $student = $this->createStudent($this->school);
        $this->inSchool(fn () => Section::query()->whereKey($this->sectionA->id)->update(['status' => 'inactive']));
        $this->refusedWith(PlacementTargetUnavailableException::SECTION_INACTIVE, fn () => $this->enroll($student, $this->inSchool(fn () => $this->sectionA->fresh())));

        $this->inSchool(fn () => Campus::query()->whereKey($this->b->id)->update(['status' => 'inactive']));
        $this->refusedWith(PlacementTargetUnavailableException::CAMPUS_INACTIVE, fn () => $this->enroll($student, $this->sectionB));

        $this->assertSame(0, $this->inSchool(fn () => StudentEnrollment::query()->count()), 'no placement was written');
    }

    #[Test]
    public function a_future_year_section_is_a_valid_target(): void
    {
        $student = $this->createStudent($this->school);
        $future = $this->createSection($this->next, $this->a, $this->grade, ['code' => 'F', 'status' => 'active']);

        $this->assertSame($this->next->id, $this->enroll($student, $future)->academic_year_id, 'F-2 never requires the active year');
    }

    #[Test]
    public function transfer_needs_an_active_student_and_target(): void
    {
        $student = $this->createStudent($this->school);
        $placement = $this->enroll($student, $this->sectionA);

        $this->inSchool(fn () => Campus::query()->whereKey($this->b->id)->update(['status' => 'inactive']));
        $this->refusedWith(PlacementTargetUnavailableException::CAMPUS_INACTIVE, fn () => app(StudentEnrollmentService::class)->transferPlacement($placement, $this->sectionB, '2', '2026-08-01'));
        $this->assertSame('active', $this->inSchool(fn () => $placement->fresh())->status, 'the source placement is untouched (one transaction)');

        $this->inSchool(fn () => Campus::query()->whereKey($this->b->id)->update(['status' => 'active']));
        $this->inSchool(fn () => Student::query()->whereKey($student->id)->update(['status' => 'inactive']));
        $this->refusedWith(PlacementTargetUnavailableException::STUDENT_INACTIVE, fn () => app(StudentEnrollmentService::class)->transferPlacement($this->inSchool(fn () => $placement->fresh()), $this->sectionB, '2', '2026-08-01'));
    }

    #[Test]
    public function admissions_conversion_cannot_place_into_an_inactive_section(): void
    {
        $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->a, $this->grade, ['status' => 'accepted']);
        $this->inSchool(fn () => Section::query()->whereKey($this->sectionA->id)->update(['status' => 'inactive']));

        $this->refusedWith(PlacementTargetUnavailableException::SECTION_INACTIVE, fn () => app(AdmissionConversionService::class)->convert($application, 'ADM-1', $this->inSchool(fn () => $this->sectionA->fresh()), '1', '2026-06-02'));
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('student_number', 'ADM-1')->count()), 'the conversion rolled back entirely');
    }

    #[Test]
    public function rollover_validation_blocks_an_unplaceable_student_or_target(): void
    {
        $leaver = $this->createStudent($this->school, ['first_name' => 'Leaver']);
        $staying = $this->createStudent($this->school, ['first_name' => 'Staying']);
        $this->enroll($leaver, $this->sectionA, '1');
        $this->enroll($staying, $this->sectionA, '2');
        $target = $this->createSection($this->next, $this->b, $this->grade6, ['code' => 'T', 'status' => 'active']);

        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($this->school, $this->year, $this->next);
        $plans->upsertMapping($plan, $this->grade, null, $this->grade6, $target);
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        foreach ($this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->get()) as $item) {
            $plans->setItemDecision($this->inSchool(fn () => $plan->fresh()), $item, 'promote', null, 'preserve_source');
        }
        $this->inSchool(fn () => Student::query()->whereKey($leaver->id)->update(['status' => 'inactive']));

        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $results = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->get()->keyBy('student_id'));
        $this->assertSame(['blocked', 'student_inactive'], [$results[$leaver->id]->validation_result, $results[$leaver->id]->validation_reason]);
        $this->assertSame('ready', $results[$staying->id]->validation_result);

        $this->inSchool(fn () => Campus::query()->whereKey($this->b->id)->update(['status' => 'inactive']));
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $staying = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $staying->id)->firstOrFail());
        $this->assertSame(['blocked', 'target_campus_inactive'], [$staying->validation_result, $staying->validation_reason]);
    }
}
