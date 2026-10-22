<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.8E (ADR 0076 §63): committed, real PostgreSQL, two OS processes, the
 * contender observed BLOCKED before the holder commits. High-impact lifecycle
 * actions serialize on the Student row (status, FOR NO KEY UPDATE) or the
 * Section + Enrollment locks (placements), so exactly one compatible
 * transition wins and no Student ends contradictory:
 *
 *   A  two deactivations              -> one event; the second is "already inactive"
 *   B  withdraw (held) vs transfer    -> the transfer finds the placement ended
 *   C  transfer (held) vs withdraw    -> the withdrawal finds the placement transferred
 *   D  withdraw (held) vs cancel      -> one terminal state, one event
 *
 * Deactivation vs Campus transfer, both orders, is in
 * StudentCampusTransferConcurrencyTest (now with a whole-School actor).
 */
class StudentLifecycleConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $admin;

    private Student $student;

    private StudentEnrollment $placement;

    private string $otherSection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $campus = $this->createCampus($this->school, ['name' => 'North']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $section = $this->createSection($year, $campus, $grade, ['code' => 'SA', 'status' => 'active']);
        $this->otherSection = $this->createSection($year, $campus, $grade, ['code' => 'SB', 'status' => 'active'])->id;
        $this->student = $this->createStudent($this->school, ['status' => 'active']);
        $this->placement = $this->createStudentEnrollment($this->student, $section, ['status' => 'active', 'starts_on' => now()->subDays(60)->toDateString()]);
        $this->admin = $this->schoolAdminFor($this->school);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        DB::connection('pgsql_admin')->table('users')->where('id', $this->admin->id)->delete();

        parent::tearDown();
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', $operation, $this->school->id, ...$args];
    }

    private function events(): int
    {
        return DB::connection('pgsql_admin')->table('student_lifecycle_events')->where('school_id', $this->school->id)->count();
    }

    private function statuses(): array
    {
        return DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $this->student->id)->orderBy('starts_on')->orderBy('created_at')->pluck('status')->all();
    }

    #[Test]
    public function two_deactivations_make_one_event(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('student-inactivate', $this->student->id, $this->admin->id),
            $this->op('student-inactivate', $this->student->id, $this->admin->id),
        );

        $this->assertSame(['ok', 'rejected:StudentStatusUnchangedException'], [$holder, $contender]);
        $this->assertSame(1, $this->events());
    }

    #[Test]
    public function a_transfer_waiting_on_a_withdrawal_finds_the_placement_ended(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('withdraw', $this->placement->id, $this->admin->id),
            $this->op('transfer', $this->placement->id, $this->otherSection),
        );

        $this->assertSame(['ok', 'rejected:InvalidEnrollmentTransitionException'], [$holder, $contender]);
        $this->assertSame(['withdrawn'], $this->statuses());
        $this->assertSame(1, $this->events());
    }

    #[Test]
    public function a_withdrawal_waiting_on_a_transfer_finds_the_placement_transferred(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('transfer', $this->placement->id, $this->otherSection),
            $this->op('withdraw', $this->placement->id, $this->admin->id),
        );

        $this->assertSame(['ok', 'rejected:InvalidEnrollmentTransitionException'], [$holder, $contender]);
        $this->assertSame(['transferred', 'active'], $this->statuses());
        $this->assertSame(0, $this->events(), 'nothing recorded for the refused withdrawal');
    }

    #[Test]
    public function a_withdrawal_and_a_cancellation_end_the_placement_once(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('withdraw', $this->placement->id, $this->admin->id),
            $this->op('enrollment-cancel', $this->placement->id, $this->admin->id),
        );

        $this->assertSame(['ok', 'rejected:InvalidEnrollmentTransitionException'], [$holder, $contender]);
        $this->assertSame(['withdrawn'], $this->statuses());
        $this->assertSame(1, $this->events());
    }
}
