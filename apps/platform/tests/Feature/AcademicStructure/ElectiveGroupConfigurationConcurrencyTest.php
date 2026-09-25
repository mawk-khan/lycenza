<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.3 (checkpoint brief §27-30/§52-54, MANDATORY): the decisive
 * configuration-vs-enrollment race proof. Both
 * ElectiveGroupService::assignOffering() and
 * StudentSubjectEnrollmentService::enroll() lock the SAME
 * SubjectOffering row (`SELECT ... FOR UPDATE`) before reading/deriving
 * its `elective_group_id` -- they therefore always serialize against
 * each other, and exactly one of two coherent final states results,
 * regardless of which one happens to win the row lock:
 *
 * - CONFIG-FIRST: the assignment commits; the enrollment (having
 *   acquired the lock afterward) reads the NEW group and snapshots it
 *   correctly.
 * - ENROLL-FIRST: the enrollment commits with a NULL (ungrouped)
 *   snapshot; the assignment (having acquired the lock afterward) sees
 *   the just-committed participation via its history check and is
 *   rejected.
 *
 * Neither this test nor the production code FORCES a specific winner
 * (there is no test-only sleep hook in either service -- CLAUDE.md rule
 * 2/CLAUDE.md's own testing discipline) -- exactly like every other
 * real concurrency test in this repository
 * (AcademicYearActivationConcurrencyTest, IdempotencyRealConcurrencyTest,
 * ...), which race genuinely concurrent processes and assert on the
 * AGGREGATE safety invariant rather than controlling who wins. This
 * test repeats the race enough times (checkpoint brief §54) that BOTH
 * orderings are expected to occur across the run, and asserts the
 * correct, coherent outcome for whichever ordering actually happened
 * on each repetition -- the FORBIDDEN state (Offering grouped, but the
 * participation's snapshot NULL, or vice versa) is checked on every
 * single repetition regardless of winner.
 *
 * Deliberately does NOT use DatabaseTransactions (see
 * $connectionsToTransact) -- the subprocesses are separate PostgreSQL
 * sessions.
 */
class ElectiveGroupConfigurationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var array<int, School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }

        parent::tearDown();
    }

    #[Test]
    public function configuration_and_enrollment_racing_the_same_offering_always_leave_a_coherent_final_state(): void
    {
        $context = app(TenantContext::class);
        $repetitions = 6;
        $configWins = 0;
        $enrollWins = 0;

        for ($i = 0; $i < $repetitions; $i++) {
            $school = $this->createSchool();
            $this->schools[] = $school;
            $campus = $this->createCampus($school);
            $year = $this->createAcademicYear($school);
            $grade = $this->createGradeLevel($school);
            $group = $this->createElectiveGroup($year, $campus, $grade);
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), [
                'is_required' => false, 'elective_group_id' => null,
            ]);
            $section = $this->createSection($year, $campus, $grade);
            $student = $this->createStudent($school, ['student_number' => "CFG-{$i}"]);
            $this->createStudentEnrollment($student, $section);

            $configScript = __DIR__.'/../../Support/assign-elective-group.php';
            $enrollScript = __DIR__.'/../../Support/enroll-subject-offering.php';
            $configProcess = new Process(['php', $configScript, $school->id, $group->id, $offering->id]);
            $enrollProcess = new Process(['php', $enrollScript, $school->id, $student->id, $offering->id, '2026-06-01']);
            $configProcess->start();
            $enrollProcess->start();
            $configProcess->wait();
            $enrollProcess->wait();

            $configOutput = $configProcess->getOutput();
            $enrollOutput = $enrollProcess->getOutput();

            $finalOffering = $context->withSchool($school, fn () => SubjectOffering::query()->findOrFail($offering->id));
            $participation = $context->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $offering->id)->first());

            $configSucceeded = str_starts_with($configOutput, 'assigned:');
            $enrollSucceeded = str_starts_with($enrollOutput, 'enrolled:');

            $this->assertTrue($enrollSucceeded, "enroll() must never be rejected by this race (it has no reason to reject an always-compatible, always-elective offering): {$enrollOutput}");
            $this->assertNotNull($participation, "iteration {$i}: enrollment must have created a row");

            if ($configSucceeded) {
                $configWins++;
                $this->assertSame($group->id, $finalOffering->elective_group_id, "iteration {$i} (config-first): the Offering must be grouped");
                $this->assertSame($group->id, $participation->elective_group_id, "iteration {$i} (config-first): the participation snapshot must match the NEW group -- forbidden state otherwise");
            } else {
                $enrollWins++;
                $this->assertStringContainsString('ElectiveGroupAssignmentLockedException', $configOutput, "iteration {$i} (enroll-first): configuration must be rejected with the stable immutability exception, got: {$configOutput}");
                $this->assertNull($finalOffering->elective_group_id, "iteration {$i} (enroll-first): the Offering must remain ungrouped -- a rejected configuration must never partially apply");
                $this->assertNull($participation->elective_group_id, "iteration {$i} (enroll-first): the participation snapshot must be NULL, matching the ungrouped Offering at the time it was created");
            }

            // The one FORBIDDEN state, checked on every repetition
            // regardless of which side won: an Offering reporting a
            // group while its own participation's snapshot disagrees.
            $this->assertSame(
                $finalOffering->elective_group_id,
                $participation->elective_group_id,
                "iteration {$i}: Offering.elective_group_id and the participation's own snapshot must never disagree -- this is exactly the forbidden mismatched state.",
            );
        }

        $this->assertGreaterThan(0, $configWins + $enrollWins, 'sanity: at least one repetition must have produced a result');
    }
}
