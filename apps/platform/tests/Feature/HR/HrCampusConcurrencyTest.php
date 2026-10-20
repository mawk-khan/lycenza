<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7E-1 (ADR 0076 §43, §54): Campus-scoped HR writes are transactionally
 * stable. Two real OS processes against real PostgreSQL; the contender is
 * positively observed BLOCKED on the holder's uncommitted transaction before
 * the holder commits (ForcesConcurrentOverlap), so each test proves one
 * serialization order and that no write commits on a stale Campus answer:
 *
 *   A  identity edit vs a new assignment on another Campus (both orders)
 *   B  identity edit vs the other Campus's assignment ending
 *   C  onboarding vs Campus deactivation (both orders)
 *   D  new assignment vs Campus deactivation
 *   +  a placement change vs a Campus-scoped new assignment (no deadlock)
 *
 * The named Campus move is not implemented (deferred, ADR 0076 §43), so race
 * E does not apply.
 */
class HrCampusConcurrencyTest extends TestCase
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

    private Campus $b;

    private Position $position;

    private User $admin;

    private User $hrA;

    private User $hrAB;

    private User $wholeHr;

    private EmploymentRecord $onlyA;

    private EmploymentRecord $both;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->schoolIds[] = $this->school->id;
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->position = $this->createPosition($this->school, ['status' => 'active']);
        $this->onlyA = $this->employee('Asha', [$this->a]);
        $this->both = $this->employee('Chitra', [$this->a, $this->b]);
        $this->admin = $this->user('school_admin', [null]);
        $this->wholeHr = $this->user('hr_officer', [null]);
        $this->hrA = $this->user('hr_officer', [$this->a->id]);
        $this->hrAB = $this->user('hr_officer', [$this->a->id, $this->b->id]);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @param  list<Campus>  $campuses */
    private function employee(string $name, array $campuses): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, ['full_name' => $name]);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2025-01-01', 'ends_on' => null, 'status' => 'active']);
        foreach ($campuses as $i => $campus) {
            $this->createEmployeeAssignment($record, $this->position, ['campus_id' => $campus->id, 'is_primary' => $i === 0, 'starts_on' => '2025-01-01', 'ends_on' => null]);
        }

        return $record;
    }

    /** @param  list<?string>  $campusIds */
    private function user(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/hr-campus-op.php', $operation, $this->school->id, ...$args];
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function fullName(EmploymentRecord $record): string
    {
        return $this->inSchool(fn () => Employee::query()->whereKey($record->employee_id)->value('full_name'));
    }

    private function assignments(EmploymentRecord $record): int
    {
        return $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $record->id)->count());
    }

    #[Test]
    public function a_campus_edit_waiting_on_a_new_other_campus_assignment_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('assign', $this->wholeHr->id, $this->onlyA->id, $this->position->id, $this->b->id),
            $this->op('edit', $this->hrA->id, $this->onlyA->employee_id, 'Renamed'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:HrWholeSchoolAuthorityRequiredException', $contender, 'the edit saw the committed B placement');
        $this->assertSame('Asha', $this->fullName($this->onlyA));
    }

    #[Test]
    public function a_new_assignment_waits_for_a_campus_edit_already_proven(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('edit', $this->hrA->id, $this->onlyA->employee_id, 'Renamed'),
            $this->op('assign', $this->wholeHr->id, $this->onlyA->id, $this->position->id, $this->b->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender, 'edit first, then the placement change: one coherent order');
        $this->assertSame('Renamed', $this->fullName($this->onlyA));
        $this->assertSame(2, $this->assignments($this->onlyA));
    }

    #[Test]
    public function a_campus_edit_waiting_on_the_other_campus_ending_proceeds_on_the_new_placement(): void
    {
        $assignmentB = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->both->id)->where('campus_id', $this->b->id)->firstOrFail());
        $yesterday = CarbonImmutable::parse(SchoolTimezone::today($this->school))->subDay()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('end-assignment', $this->wholeHr->id, $assignmentB->id, $yesterday),
            $this->op('edit', $this->hrA->id, $this->both->employee_id, 'Renamed'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender, 'after the B assignment ended, every current Campus is in scope');
        $this->assertSame('Renamed', $this->fullName($this->both));
    }

    #[Test]
    public function a_campus_edit_of_a_two_campus_employee_stays_refused_while_both_are_current(): void
    {
        // Control: the same edit, no concurrent change, is refused for A-only scope.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('edit', $this->hrAB->id, $this->both->employee_id, 'ByAB'),
            $this->op('edit', $this->hrA->id, $this->both->employee_id, 'ByA'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:HrWholeSchoolAuthorityRequiredException', $contender);
        $this->assertSame('ByAB', $this->fullName($this->both));
    }

    #[Test]
    public function onboarding_waiting_on_a_campus_deactivation_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('campus-inactivate', $this->admin->id, $this->a->id),
            $this->op('onboard', $this->hrA->id, $this->a->id, $this->position->id, 'Late Joiner'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:HrOnboardingUnavailableException', $contender);
        $this->assertFalse($this->inSchool(fn () => Employee::query()->where('full_name', 'Late Joiner')->exists()), 'no partial Employee');
    }

    #[Test]
    public function a_campus_deactivation_waits_for_onboarding_already_proven(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('onboard', $this->hrA->id, $this->a->id, $this->position->id, 'Early Joiner'),
            $this->op('campus-inactivate', $this->admin->id, $this->a->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $employee = $this->inSchool(fn () => Employee::query()->where('full_name', 'Early Joiner')->firstOrFail());
        $this->assertSame(1, $this->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->count()));
    }

    #[Test]
    public function a_new_assignment_waiting_on_a_campus_deactivation_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('campus-inactivate', $this->admin->id, $this->a->id),
            $this->op('assign', $this->hrA->id, $this->onlyA->id, $this->position->id, $this->a->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(1, $this->assignments($this->onlyA), 'no assignment commits on the stale answer');
    }

    #[Test]
    public function a_scoped_set_primary_waiting_on_another_campus_becoming_primary_is_refused(): void
    {
        // Security review M2: the open primary is read only after the exclusive
        // placement lock, so it never demotes a B primary committed meanwhile.
        $a = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->both->id)->where('campus_id', $this->a->id)->firstOrFail());
        $b = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->both->id)->where('campus_id', $this->b->id)->firstOrFail());
        $a2 = $this->inSchool(fn () => $this->createEmployeeAssignment($this->both, $this->position, ['campus_id' => $this->a->id, 'starts_on' => '2025-02-01', 'ends_on' => null]));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('set-primary', $this->wholeHr->id, $b->id),
            $this->op('set-primary', $this->hrA->id, $a2->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:HrWholeSchoolAuthorityRequiredException', $contender);
        $this->assertTrue($this->inSchool(fn () => EmployeeAssignment::query()->find($b->id)->is_primary), 'the B primary stays');
        $this->assertFalse($this->inSchool(fn () => EmployeeAssignment::query()->find($a->id)->is_primary));
    }

    #[Test]
    public function a_scoped_new_assignment_waits_for_a_placement_change_without_deadlock(): void
    {
        $assignmentA = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->both->id)->where('campus_id', $this->a->id)->firstOrFail());

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('end-assignment', $this->wholeHr->id, $assignmentA->id, SchoolTimezone::today($this->school)),
            $this->op('assign', $this->hrAB->id, $this->both->id, $this->position->id, $this->a->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender, 'the A+B scope still covers every current Campus after the end');
        $this->assertSame(3, $this->assignments($this->both));
    }
}
