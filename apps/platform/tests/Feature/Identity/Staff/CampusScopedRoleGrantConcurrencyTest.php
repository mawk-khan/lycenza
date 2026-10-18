<?php

namespace Tests\Feature\Identity\Staff;

use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.6, §33): Campus-scoped grants under real concurrency
 * -- two OS processes against real PostgreSQL, the contender positively
 * observed blocked before the holder commits (ForcesConcurrentOverlap).
 */
class CampusScopedRoleGrantConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @return array{0: School, 1: Campus, 2: User, 3: SchoolMembership} */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $campus = $this->createCampus($school);
        $admin = $this->createUser();
        $staff = $this->createUser();
        $this->userIds = [...$this->userIds, $admin->id, $staff->id];
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');
        $membership = $this->createMembership($staff, $school);
        $this->assignSchoolRole($membership, 'teacher');

        return [$school, $campus, $admin, $membership];
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/campus-scope-op.php', ...$args];
    }

    private function scopedGrants(School $school, SchoolMembership $membership): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->whereNotNull('campus_id')->active()->count());
    }

    #[Test]
    public function a_grant_waiting_on_a_campus_inactivation_is_refused(): void
    {
        [$school, $campus, $admin, $membership] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('inactivate', $school->id, $campus->id),
            $this->script('grant', $admin->id, $school->id, $membership->id, 'front_office', $campus->id),
        );

        $this->assertSame('inactivated', $holder);
        $this->assertSame('rejected:campus_unavailable', $contender);
        $this->assertSame(0, $this->scopedGrants($school, $membership));
    }

    #[Test]
    public function two_simultaneous_identical_campus_grants_create_one(): void
    {
        [$school, $campus, $admin, $membership] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('grant', $admin->id, $school->id, $membership->id, 'front_office', $campus->id),
            $this->script('grant', $admin->id, $school->id, $membership->id, 'front_office', $campus->id),
        );

        $this->assertSame('granted', $holder);
        $this->assertSame('rejected:role_already_granted', $contender);
        $this->assertSame(1, $this->scopedGrants($school, $membership));
    }
}
