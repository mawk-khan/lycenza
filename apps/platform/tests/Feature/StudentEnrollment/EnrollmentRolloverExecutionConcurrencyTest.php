<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (this checkpoint's brief, section
 * 66): two GENUINELY separate OS processes -- not two sequential calls
 * in one PHP process -- both attempt to `start()` the SAME validated
 * `EnrollmentRolloverPlan` against real PostgreSQL, at the same time.
 * The Plan row's own lock (`lockForUpdate()` in
 * `EnrollmentRolloverExecutionService::claim()`) is what makes this
 * safe; this test proves the final database state, not just that the
 * application code "looks" correct. Mirrors
 * AcademicYearActivationConcurrencyTest/
 * EnrollmentRolloverItemExecutionConcurrencyTest's identical shape and
 * reasoning.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class EnrollmentRolloverExecutionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades academic_years/students/rollover plan/items
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_starting_the_same_plan_produce_exactly_one_claim(): void
    {
        $this->school = $school = $this->createSchool();
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $sourceGrade = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $targetGrade = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $sourceSection = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5A', 'code' => '5A']);
        $targetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6B', 'code' => '6B']);

        $student = $this->createStudent($school, ['student_number' => 'S-CONC2-1']);
        app(StudentEnrollmentService::class)->enroll($student, $sourceSection, '007', '2026-06-01');

        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        app(EnrollmentRolloverPlanService::class)->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
        $item = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail());
        app(EnrollmentRolloverPlanService::class)->setItemDecision($context->withSchool($school, fn () => $plan->fresh()), $item, 'promote', null, 'explicit', '011');
        $summary = $context->withSchool($school, fn () => app(EnrollmentRolloverDryRunService::class)->run($plan->fresh()));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');

        $script = __DIR__.'/../../Support/start-rollover-plan.php';
        $processA = new Process(['php', $script, $school->id, $plan->id]);
        $processB = new Process(['php', $script, $school->id, $plan->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $succeeded = array_filter($outputs, fn ($o) => str_starts_with($o, 'started:'));
        $rejected = array_filter($outputs, fn ($o) => str_starts_with($o, 'exception:App\\Domain\\Students\\Application\\Exceptions\\RolloverPlanAlreadyExecutingException'));

        $this->assertCount(1, $succeeded, 'exactly one of the two concurrent start() calls must claim the plan: '.implode(' | ', $outputs));
        $this->assertCount(1, $rejected, 'the other must observe already-executing, never a second fresh start: '.implode(' | ', $outputs));

        $activeTargetCount = $context->withSchool(
            $school,
            fn () => StudentEnrollment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $targetYear->id)
                ->where('status', 'active')
                ->count(),
        );
        $this->assertSame(1, $activeTargetCount, 'exactly one target Enrollment must exist -- no duplicate academic state from the losing claim attempt');
    }
}
