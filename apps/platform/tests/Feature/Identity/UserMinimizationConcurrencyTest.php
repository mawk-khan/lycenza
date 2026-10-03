<?php

namespace Tests\Feature\Identity;

use App\Models\School;
use App\Models\User;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.4: User minimization racing what makes a User current again, in two
 * real OS processes with an observed lock wait (COMMITTED fixtures). The
 * minimization locks the User FOR UPDATE and rechecks every purpose under
 * that lock; a new membership, Employee link or platform grant takes FOR
 * KEY SHARE (its foreign key) and FOR SHARE (the not-minimized guard) on
 * the same row. Whichever commits first wins:
 * - the current purpose first: the User is kept current;
 * - minimization first: the late write is refused by the database.
 */
class UserMinimizationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $users = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }
        $admin->table('erasure_cases')->whereIn('subject_id', $this->users)->delete();
        $admin->table('platform_audit_events')->whereIn('subject_id', $this->users)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $this->users)->delete();
        $admin->table('school_memberships')->whereIn('user_id', $this->users)->delete();
        $admin->table('users')->whereIn('id', $this->users)->delete();

        parent::tearDown();
    }

    /** @return array{0: User, 1: School, 2: string} a former member and an approved case for it */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $user = $this->createUser();
        $this->users[] = $user->id;
        $this->createMembership($user, $school, 'suspended');
        $cases = app(ErasureCaseService::class);
        $case = $cases->open(null, 'user', $user->id, 'written');
        $cases->decide($case->id, 'approve', 'request_valid');

        return [$user, $school, $case->id];
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/user-minimization-op.php', ...$args];
    }

    private function minimized(User $user): bool
    {
        return DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->whereNotNull('minimized_at')->exists();
    }

    private function grantor(): string
    {
        $grantor = $this->createUser();
        $this->users[] = $grantor->id;

        return $grantor->id;
    }

    private function currentEmployee(School $school): string
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school): string {
            $employee = $this->createEmployee($school);
            $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => null]);

            return $employee->id;
        });
    }

    #[Test]
    public function a_membership_committed_first_keeps_the_user_current(): void
    {
        [$user, , $case] = $this->world();
        $other = $this->createSchool();
        $this->schools[] = $other;

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('membership', $user->id, $other->id), $this->script('minimize', $case));

        $this->assertSame('joined', $holder);
        $this->assertSame('dependency_blocked:active_membership', $contender);
        $this->assertFalse($this->minimized($user));
    }

    #[Test]
    public function a_minimization_committed_first_refuses_the_late_membership(): void
    {
        [$user, , $case] = $this->world();
        $other = $this->createSchool();
        $this->schools[] = $other;

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('minimize', $case), $this->script('membership', $user->id, $other->id));

        $this->assertSame('completed:minimized', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('school_memberships')->where('user_id', $user->id)->where('school_id', $other->id)->count());
    }

    #[Test]
    public function an_employee_link_committed_first_keeps_the_user_current(): void
    {
        [$user, $school, $case] = $this->world();
        $employee = $this->currentEmployee($school);

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('link', $user->id, $school->id, $employee), $this->script('minimize', $case));

        $this->assertSame('linked', $holder);
        $this->assertSame('dependency_blocked:active_employee_link', $contender);
        $this->assertFalse($this->minimized($user));
    }

    #[Test]
    public function a_minimization_committed_first_refuses_the_late_employee_link(): void
    {
        [$user, $school, $case] = $this->world();
        $employee = $this->currentEmployee($school);

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('minimize', $case), $this->script('link', $user->id, $school->id, $employee));

        $this->assertSame('completed:minimized', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertNull(app(TenantContext::class)->withSchool($school, fn () => DB::table('employees')->where('id', $employee)->value('user_id')));
    }

    #[Test]
    public function a_platform_grant_committed_first_keeps_the_user_current(): void
    {
        [$user, , $case] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('grant', $user->id, $this->grantor()), $this->script('minimize', $case));

        $this->assertSame('granted', $holder);
        $this->assertSame('dependency_blocked:active_platform_role', $contender);
        $this->assertFalse($this->minimized($user));
    }

    #[Test]
    public function a_minimization_committed_first_refuses_the_late_platform_grant(): void
    {
        [$user, , $case] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('minimize', $case), $this->script('grant', $user->id, $this->grantor()));

        $this->assertSame('completed:minimized', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('platform_role_assignments')->where('user_id', $user->id)->count());
    }
}
