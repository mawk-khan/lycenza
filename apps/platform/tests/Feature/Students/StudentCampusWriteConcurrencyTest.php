<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7F-2 (ADR 0076 §46): the Campus name edit and atomic Campus onboarding
 * serialize against every state change that decides them -- proven with two
 * GENUINELY separate OS processes whose transactions overlap (the contender
 * is observed blocked on the holder's lock), in both orders.
 *
 * - Whoever locks first commits; the other waits. A state change that
 *   committed first is seen and the Campus write refuses -- an A-authorized
 *   edit never commits after the Student moved to B, became inactive, the
 *   year switched or Campus A was deactivated.
 * - Two onboardings never share a Student number; a refused onboarding
 *   leaves no Student behind.
 */
class StudentCampusWriteConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    private School $school;

    private Campus $a;

    private AcademicYear $nextYear;

    private Section $sectionA;

    private Section $sectionB;

    private Student $student;

    private StudentEnrollment $placement;

    private User $admin;

    private User $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->schoolIds[] = $this->school->id;
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $b = $this->createCampus($this->school, ['name' => 'South']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $this->nextYear = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => 'Y2', 'status' => 'draft', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(300)->toDateString()]);
        $this->sectionA = $this->createSection($year, $this->a, $grade, ['code' => 'SA', 'status' => 'active']);
        $this->sectionB = $this->createSection($year, $b, $grade, ['code' => 'SB', 'status' => 'active']);
        $nextB = $this->createSection($this->nextYear, $b, $grade, ['code' => 'NB', 'status' => 'active']);

        // Current on Campus A in Y1; already placed on Campus B for Y2.
        $this->student = $this->createStudent($this->school, ['first_name' => 'Original', 'status' => 'active']);
        $this->placement = $this->createStudentEnrollment($this->student, $this->sectionA, ['status' => 'active', 'starts_on' => now()->subDays(60)->toDateString()]);
        $this->createStudentEnrollment($this->student, $nextB, ['status' => 'active', 'starts_on' => now()->subDay()->toDateString()]);

        $this->admin = $this->user('school_admin', null);
        $this->principal = $this->user('principal', $this->a->id);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function user(string $role, ?string $campusId): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $this->assignSchoolRole($this->createMembership($user, $this->school), $role, $campusId);

        return $user;
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', $operation, $this->school->id, ...$args];
    }

    /** @return array<string, array<int, string>> */
    private function stateChanges(): array
    {
        return [
            'transfer' => $this->op('transfer', $this->placement->id, $this->sectionB->id),
            'inactivate' => $this->op('student-inactivate', $this->student->id),
            'year' => $this->op('year-activate', $this->nextYear->id),
            'campus' => $this->op('campus-inactivate', $this->admin->id, $this->a->id),
        ];
    }

    private function rename(string $firstName): array
    {
        return $this->op('student-rename', $this->principal->id, $this->student->id, $firstName);
    }

    private function onboard(string $firstName, ?Section $section = null): array
    {
        return $this->op('onboard', $this->principal->id, ($section ?? $this->sectionA)->id, $firstName);
    }

    private function firstName(): string
    {
        return (string) DB::connection('pgsql_admin')->table('students')->where('id', $this->student->id)->value('first_name');
    }

    private function onboarded(string $firstName): int
    {
        return DB::connection('pgsql_admin')->table('students')->where('school_id', $this->school->id)->where('first_name', $firstName)->count();
    }

    /** @return array<string, array{0: string}> */
    public static function editStateChanges(): array
    {
        return ['transfer' => ['transfer'], 'inactivation' => ['inactivate'], 'year switch' => ['year'], 'Campus deactivation' => ['campus']];
    }

    /** @return array<string, array{0: string}> */
    public static function onboardingStateChanges(): array
    {
        return ['Campus deactivation' => ['campus'], 'Section deactivation' => ['section'], 'year switch' => ['year']];
    }

    /** @return array<int, string> */
    private function change(string $change): array
    {
        return $change === 'section' ? $this->op('section-inactivate', $this->sectionA->id) : $this->stateChanges()[$change];
    }

    #[Test]
    #[DataProvider('editStateChanges')]
    public function a_campus_name_edit_that_locks_first_commits_and_the_state_change_waits(string $change): void
    {
        [$edit, $state] = $this->raceWithHeldHolder($this->rename('Edited'), $this->change($change));

        $this->assertSame('ok', $edit, 'the edit holding the lock commits');
        $this->assertSame('ok', $state, 'the state change then completes');
        $this->assertSame('Edited', $this->firstName());
    }

    #[Test]
    #[DataProvider('editStateChanges')]
    public function a_state_change_that_commits_first_refuses_the_campus_name_edit(string $change): void
    {
        [$state, $edit] = $this->raceWithHeldHolder($this->change($change), $this->rename('Late'));

        $this->assertSame('ok', $state);
        $this->assertSame('rejected:ModelNotFoundException', $edit, 'an A-authorized edit never commits after authority moved');
        $this->assertSame('Original', $this->firstName());
    }

    #[Test]
    public function simultaneous_onboardings_never_share_a_student_number(): void
    {
        [$first, $second] = $this->raceWithHeldHolder($this->onboard('Racer'), $this->onboard('Racer'));

        $this->assertStringStartsWith('ok:', $first);
        $this->assertStringStartsWith('ok:', $second);
        $this->assertNotSame($first, $second, 'distinct Student numbers');
        $this->assertSame(2, $this->onboarded('Racer'));
    }

    #[Test]
    #[DataProvider('onboardingStateChanges')]
    public function an_onboarding_that_locks_first_commits_and_the_state_change_waits(string $change): void
    {
        [$onboarding, $state] = $this->raceWithHeldHolder($this->onboard('First'), $this->change($change));

        $this->assertStringStartsWith('ok:', $onboarding);
        $this->assertSame('ok', $state);
        $this->assertSame(1, $this->onboarded('First'));
    }

    #[Test]
    #[DataProvider('onboardingStateChanges')]
    public function a_state_change_that_commits_first_refuses_onboarding_and_leaves_no_student(string $change): void
    {
        [$state, $onboarding] = $this->raceWithHeldHolder($this->change($change), $this->onboard('Late'));

        $this->assertSame('ok', $state);
        $this->assertSame('rejected:StudentOnboardingUnavailableException', $onboarding);
        $this->assertSame(0, $this->onboarded('Late'), 'no partial Student survives');
    }

    #[Test]
    public function a_typed_number_racing_an_onboarding_never_deadlocks_or_duplicates(): void
    {
        // The fixture's numeric factory number already moved the counter past it.
        $next = (int) DB::connection('pgsql_admin')->table('student_number_sequences')->where('school_id', $this->school->id)->value('next_value');

        // Onboarding holds the counter and takes $next; the same typed number
        // queues on the counter (BEFORE trigger), then meets the ordinary
        // duplicate refusal -- never a deadlock, never a shared number.
        [$onboarding, $manual] = $this->raceWithHeldHolder($this->onboard('Gen'), $this->op('manual-create', (string) $next));
        $this->assertSame("ok:{$next}", $onboarding);
        $this->assertSame('rejected:DuplicateStudentNumberException', $manual);

        // A typed number committed first moves the counter past it.
        $typed = $next + 5;
        [$manual, $onboarding] = $this->raceWithHeldHolder($this->op('manual-create', (string) $typed), $this->onboard('After'));
        $this->assertSame('ok', $manual);
        $this->assertSame('ok:'.($typed + 1), $onboarding);
    }
}
