<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1G.3 (checkpoint brief sections 34-35/59-61, MANDATORY): real
 * two-process races between `EnrollmentRolloverItemExecutionService::execute()`'s
 * OWN subject-elective application and a genuinely concurrent, unrelated
 * `StudentSubjectEnrollmentService::enroll()` call for the SAME target
 * placement -- never a mocked lock, never a sequential simulation.
 * Reuses `execute-rollover-item.php`/`enroll-subject-offering.php`
 * exactly as `EnrollmentRolloverItemExecutionConcurrencyTest`/
 * `ElectiveGroupConfigurationConcurrencyTest` already do -- no second
 * concurrency-test harness invented.
 *
 * Deliberately does NOT use DatabaseTransactions (see
 * $connectionsToTransact) -- the two subprocesses are separate
 * PostgreSQL sessions.
 */
class EnrollmentRolloverSubjectExecutionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var array<int, School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $school->delete();
        }

        parent::tearDown();
    }

    /**
     * @return array{school: School, student: Student, item: EnrollmentRolloverItem, plan: EnrollmentRolloverPlan, targetA: SubjectOffering, targetOfferingBSameGroup: SubjectOffering, groupX: ElectiveGroup, targetEnrollmentId: string}
     */
    private function buildAlreadyEnrolledContextWithOneMappedGroupedElective(string $suffix): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $grade = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $sourceSection = $this->createSection($sourceYear, $campus, $grade, ['name' => "5A{$suffix}", 'code' => "5A{$suffix}"]);
        $targetSection = $this->createSection($targetYear, $campus, $grade, ['name' => "5B{$suffix}", 'code' => "5B{$suffix}"]);
        $groupX = $this->createElectiveGroup($targetYear, $campus, $grade);

        $subjectA = $this->createSubject($school);
        $sourceOffering = $this->createSubjectOffering($sourceYear, $campus, $grade, $subjectA, ['is_required' => false]);
        $targetA = $this->createSubjectOffering($targetYear, $campus, $grade, $subjectA, ['is_required' => false, 'elective_group_id' => $groupX->id]);

        $subjectB = $this->createSubject($school);
        $targetOfferingBSameGroup = $this->createSubjectOffering($targetYear, $campus, $grade, $subjectB, ['is_required' => false, 'elective_group_id' => $groupX->id]);

        $student = $this->createStudent($school, ['student_number' => "CONC-{$suffix}"]);
        app(StudentEnrollmentService::class)->enroll($student, $sourceSection, '01', '2026-06-01');
        app(StudentSubjectEnrollmentService::class)->enroll($student, $sourceOffering, '2026-06-01');
        app(StudentEnrollmentService::class)->enroll($student, $targetSection, '01', '2027-06-01'); // pre-existing target placement

        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        app(EnrollmentRolloverPlanService::class)->upsertMapping($plan, $grade, null, $grade, $targetSection);
        app(EnrollmentRolloverPlanService::class)->upsertSubjectMapping($plan, $sourceOffering, $targetA);
        $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
        $item = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail());
        app(EnrollmentRolloverPlanService::class)->setItemDecision($context->withSchool($school, fn () => $plan->fresh()), $item, 'repeat', null, 'explicit', '01');
        $summary = $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');
        $item = $context->withSchool($school, fn () => $item->fresh());
        $this->assertSame('already_enrolled', $item->validation_result);

        $targetEnrollment = $context->withSchool($school, fn () => StudentEnrollment::query()
            ->where('student_id', $student->id)->where('academic_year_id', $targetYear->id)->firstOrFail());

        return compact('school', 'student', 'item', 'plan', 'targetA', 'targetOfferingBSameGroup', 'groupX') + ['targetEnrollmentId' => $targetEnrollment->id];
    }

    /**
     * Section 34 (MANDATORY): rollover's OWN mapped intent (target
     * Offering A) races a completely independent, concurrent
     * `enroll()` call for the EXACT SAME target Offering. Whichever
     * side's INSERT wins the `student_subject_enrollments_one_active_per_offering`
     * unique index, the LOSER's `ActiveSubjectEnrollmentConflictException`
     * is reconciled (this checkpoint's brief, section 22): rollover
     * NEVER fails the whole Item merely because a competing writer
     * already satisfied the identical intent. Repeated to observe both
     * orderings, exactly like `ElectiveGroupConfigurationConcurrencyTest`.
     */
    #[Test]
    public function rollover_and_a_concurrent_direct_enroll_racing_the_exact_same_target_offering_always_reconcile_to_one_active_row(): void
    {
        $repetitions = 6;
        $rolloverWins = 0;
        $directWins = 0;

        for ($i = 0; $i < $repetitions; $i++) {
            ['school' => $school, 'student' => $student, 'item' => $item, 'targetA' => $targetA, 'targetEnrollmentId' => $targetEnrollmentId] = $this->buildAlreadyEnrolledContextWithOneMappedGroupedElective("EX{$i}");
            $context = app(TenantContext::class);

            $rolloverScript = __DIR__.'/../../Support/execute-rollover-item.php';
            $directScript = __DIR__.'/../../Support/enroll-subject-offering.php';
            $rolloverProcess = new Process(['php', $rolloverScript, $school->id, $item->id]);
            $directProcess = new Process(['php', $directScript, $school->id, $student->id, $targetA->id, '2027-06-01']);
            $rolloverProcess->start();
            $directProcess->start();
            $rolloverProcess->wait();
            $directProcess->wait();

            $rolloverOutput = $rolloverProcess->getOutput();
            $directOutput = $directProcess->getOutput();

            $rolloverCoherent = str_starts_with($rolloverOutput, 'succeeded:') || str_starts_with($rolloverOutput, 'reconciled:');
            $this->assertTrue($rolloverCoherent, "iteration {$i}: rollover must NEVER fail an exact-target race, got: {$rolloverOutput}");

            $directSucceeded = str_starts_with($directOutput, 'enrolled:');
            if ($directSucceeded) {
                $directWins++;
            } else {
                $rolloverWins++;
                $this->assertStringContainsString('ActiveSubjectEnrollmentConflictException', $directOutput, "iteration {$i}: the losing direct enroll() must be rejected with the stable conflict exception, got: {$directOutput}");
            }

            $activeCount = $context->withSchool(
                $school,
                fn () => StudentSubjectEnrollment::query()
                    ->where('student_enrollment_id', $targetEnrollmentId)
                    ->where('subject_offering_id', $targetA->id)
                    ->where('status', 'active')
                    ->count(),
            );
            $this->assertSame(1, $activeCount, "iteration {$i}: exactly one active row for the exact target Offering must survive the race, regardless of winner");
        }

        $this->assertGreaterThan(0, $rolloverWins + $directWins, 'sanity: at least one repetition must have produced a result');
    }

    /**
     * Section 35 (MANDATORY): rollover intends target Offering A / Group
     * X; a concurrent, unrelated writer enrolls a DIFFERENT Offering B
     * in the SAME Group X for the identical target placement. Exactly
     * one Group X row survives regardless of winner. If rollover LOSES
     * the race, the Item fails with the stable
     * `elective_target_group_conflict_at_execution` reason -- NEVER
     * auto-replacing the competing writer's row. If rollover WINS, the
     * competing direct `enroll()` call itself is rejected with the
     * canonical `ElectiveGroupConflictException`.
     */
    #[Test]
    public function rollover_and_a_concurrent_direct_enroll_racing_the_same_elective_group_always_leave_exactly_one_active_row(): void
    {
        $repetitions = 6;
        $rolloverWins = 0;
        $directWins = 0;

        for ($i = 0; $i < $repetitions; $i++) {
            ['school' => $school, 'student' => $student, 'item' => $item, 'targetA' => $targetA, 'targetOfferingBSameGroup' => $offeringB, 'groupX' => $groupX, 'targetEnrollmentId' => $targetEnrollmentId] = $this->buildAlreadyEnrolledContextWithOneMappedGroupedElective("GX{$i}");
            $context = app(TenantContext::class);

            $rolloverScript = __DIR__.'/../../Support/execute-rollover-item.php';
            $directScript = __DIR__.'/../../Support/enroll-subject-offering.php';
            $rolloverProcess = new Process(['php', $rolloverScript, $school->id, $item->id]);
            $directProcess = new Process(['php', $directScript, $school->id, $student->id, $offeringB->id, '2027-06-01']);
            $rolloverProcess->start();
            $directProcess->start();
            $rolloverProcess->wait();
            $directProcess->wait();

            $rolloverOutput = $rolloverProcess->getOutput();
            $directOutput = $directProcess->getOutput();

            $rolloverSucceeded = str_starts_with($rolloverOutput, 'succeeded:') || str_starts_with($rolloverOutput, 'reconciled:');
            $directSucceeded = str_starts_with($directOutput, 'enrolled:');

            // Exactly one side must win this Group -- never both, never
            // neither (the partial unique index makes this structurally
            // impossible to violate).
            $this->assertNotSame($rolloverSucceeded, $directSucceeded, "iteration {$i}: exactly one side must win Group X, got rollover={$rolloverOutput} direct={$directOutput}");

            if ($rolloverSucceeded) {
                $rolloverWins++;
                $this->assertStringContainsString('ElectiveGroupConflictException', $directOutput, "iteration {$i} (rollover-first): the losing direct enroll() must be rejected with the stable group-conflict exception, got: {$directOutput}");

                // Rollover's win is reported as `succeeded:` or, for this
                // already-enrolled fixture, `reconciled:` (both counted as a
                // win above); the persisted Item status must match exactly
                // what the process reported.
                $item = $context->withSchool($school, fn () => $item->fresh());
                $this->assertSame(strstr($rolloverOutput, ':', true), $item->execution_status ?? null, "iteration {$i}");
            } else {
                $directWins++;
                $item = $context->withSchool($school, fn () => $item->fresh());
                $this->assertSame('failed', $item->execution_status, "iteration {$i} (direct-first): rollover must fail this Item rather than overwrite the competing writer's row");
                // Timing determines which of two EQUALLY valid stable
                // reasons manifests: if the competitor's write is
                // already visible by the time applySubjectElectives()
                // runs its own defensive pre-check, it is caught there
                // (elective_target_existing_conflict, computed BEFORE
                // any enroll() call); if it lands strictly between that
                // pre-check and rollover's own enroll() call, rollover's
                // own attempt hits the live database constraint instead
                // (elective_target_group_conflict_at_execution). Both
                // are correct -- never a partial/overwriting outcome.
                $this->assertContains($item->validation_reason, ['elective_target_group_conflict_at_execution', 'elective_target_existing_conflict'], "iteration {$i}");
            }

            $groupXActiveCount = $context->withSchool(
                $school,
                fn () => StudentSubjectEnrollment::query()
                    ->where('student_enrollment_id', $targetEnrollmentId)
                    ->where('elective_group_id', $groupX->id)
                    ->where('status', 'active')
                    ->count(),
            );
            $this->assertSame(1, $groupXActiveCount, "iteration {$i}: exactly one active Group X row must survive the race, regardless of winner");
        }

        $this->assertGreaterThan(0, $rolloverWins + $directWins, 'sanity: at least one repetition must have produced a result');
    }
}
