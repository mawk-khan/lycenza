<?php

namespace Tests\Feature\StudentSubjectEnrollment;

use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.2 (checkpoint brief §42/§43/§45, MANDATORY): REQUIRED real
 * concurrency proof -- two GENUINELY separate OS processes, not two
 * sequential calls in one PHP process, racing
 * StudentSubjectEnrollmentService::enroll()/transfer() against real
 * PostgreSQL. Mirrors AcademicYearActivationConcurrencyTest's identical
 * pattern and rationale.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class StudentSubjectEnrollmentElectiveGroupConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades campuses/years/grades/subjects/offerings/groups/students/enrollments
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_enrollments_into_the_same_group_leave_exactly_one_active(): void
    {
        $this->school = $school = $this->createSchool();
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'FR']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ES']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'CC-0001']);
        $this->createStudentEnrollment($student, $section);

        $script = __DIR__.'/../../Support/enroll-subject-offering.php';
        $processA = new Process(['php', $script, $school->id, $student->id, $offeringA->id, '2026-06-01']);
        $processB = new Process(['php', $script, $school->id, $student->id, $offeringB->id, '2026-06-01']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $enrolledCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'enrolled:')));
        $rejected = array_values(array_filter($outputs, fn (string $o) => str_starts_with($o, 'rejected:')));

        $this->assertSame(1, $enrolledCount, "Exactly one of the two concurrent same-group enrollments must succeed. Got: {$outputs[0]} | {$outputs[1]}");
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString(
            'ElectiveGroupConflictException',
            $rejected[0],
            "The loser must receive the stable group-conflict exception, got: {$rejected[0]}",
        );

        $activeCount = $context->withSchool(
            $school,
            fn () => StudentSubjectEnrollment::query()->where('elective_group_id', $group->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeCount, 'The database must contain exactly one active participation in this ElectiveGroup.');
    }

    #[Test]
    public function two_concurrent_enrollments_into_different_groups_both_succeed(): void
    {
        $this->school = $school = $this->createSchool();
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $groupX = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPX']);
        $groupY = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPY']);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'FR']), [
            'is_required' => false, 'elective_group_id' => $groupX->id,
        ]);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'MU']), [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'CC-0002']);
        $this->createStudentEnrollment($student, $section);

        $script = __DIR__.'/../../Support/enroll-subject-offering.php';
        $processA = new Process(['php', $script, $school->id, $student->id, $offeringA->id, '2026-06-01']);
        $processB = new Process(['php', $script, $school->id, $student->id, $offeringB->id, '2026-06-01']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $enrolledCount = count(array_filter($outputs, fn (string $o) => str_starts_with($o, 'enrolled:')));

        $this->assertSame(2, $enrolledCount, "Both concurrent different-group enrollments must succeed. Got: {$outputs[0]} | {$outputs[1]}");

        $activeCount = $context->withSchool(
            $school,
            fn () => StudentSubjectEnrollment::query()->whereIn('elective_group_id', [$groupX->id, $groupY->id])->where('status', 'active')->count(),
        );
        $this->assertSame(2, $activeCount, 'The database must contain two active participations, one per ElectiveGroup.');
    }

    #[Test]
    public function a_concurrent_enroll_and_transfer_into_the_same_group_leave_exactly_one_active(): void
    {
        $this->school = $school = $this->createSchool();
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'FR']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ES']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $ungroupedOffering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'PE']), [
            'is_required' => false,
        ]);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'CC-0003']);
        $this->createStudentEnrollment($student, $section);

        // The transfer SOURCE: an existing, unrelated (ungrouped) active
        // participation the second process will attempt to move INTO
        // the ElectiveGroup (offeringB) concurrently with the first
        // process enrolling directly into the same Group (offeringA).
        $sourceRow = $this->createStudentSubjectEnrollment($student, $ungroupedOffering);

        $enrollScript = __DIR__.'/../../Support/enroll-subject-offering.php';
        $transferScript = __DIR__.'/../../Support/transfer-subject-offering.php';
        $processEnroll = new Process(['php', $enrollScript, $school->id, $student->id, $offeringA->id, '2026-06-01']);
        $processTransfer = new Process(['php', $transferScript, $school->id, $sourceRow->id, $offeringB->id, '2026-09-01']);
        $processEnroll->start();
        $processTransfer->start();
        $processEnroll->wait();
        $processTransfer->wait();

        $enrollOutput = $processEnroll->getOutput();
        $transferOutput = $processTransfer->getOutput();

        $activeGroupCount = $context->withSchool(
            $school,
            fn () => StudentSubjectEnrollment::query()->where('elective_group_id', $group->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeGroupCount, "At most one active Group participation may exist. enroll={$enrollOutput} transfer={$transferOutput}");

        // No partial transfer: either the transfer fully succeeded (source
        // transferred, target active) or it fully failed (source still
        // active, no target row) -- never a state in between.
        if (str_starts_with($transferOutput, 'rejected:')) {
            $refreshedSource = $context->withSchool($school, fn () => $sourceRow->fresh());
            $this->assertSame('active', $refreshedSource->status, 'A rejected transfer must leave its source row untouched.');
            $targetCount = $context->withSchool(
                $school,
                fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $offeringB->id)->where('status', 'active')->count(),
            );
            $this->assertSame(0, $targetCount, 'A rejected transfer must never leave a target row behind.');
        }
    }
}
