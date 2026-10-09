<?php

namespace Tests\Feature\Postgres;

use App\Models\Role;
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
 * SR.1 (ADR 0071 §11.6, §14): the database grantor-coverage rule is race-safe.
 * The trigger reads the issuer's membership and active grants FOR SHARE, so a
 * concurrent revocation of the issuer's authority either commits first (and
 * the waiting grant is refused) or waits for the grant to commit. Two real OS
 * processes, with the contender OBSERVED waiting on the holder's lock.
 */
class StaffRoleGrantorRaceTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }
        $admin = DB::connection('pgsql_admin');
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    /** @return array{School, User, SchoolMembership, SchoolMembership, Role} */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $issuer = $this->createUser();
        $issuerMembership = $this->createMembership($issuer, $school);
        $this->assignSchoolRole($issuerMembership, 'school_admin');
        $target = $this->createMembership($this->createUser(), $school);

        return [$school, $issuer, $issuerMembership, $target, Role::query()->where('key', 'teacher')->firstOrFail()];
    }

    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/staff-role-grant-op.php', ...$args];
    }

    private function teacherGrants(School $school, SchoolMembership $target, Role $role): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')
            ->where('school_membership_id', $target->id)->where('role_id', $role->id)->count());
    }

    #[Test]
    public function a_revocation_committed_first_refuses_the_waiting_grant(): void
    {
        [$school, $issuer, $issuerMembership, $target, $teacher] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('revoke-issuer', $school->id, $issuerMembership->id),
            $this->op('grant', $school->id, $target->id, $teacher->id, $issuer->id),
        );

        $this->assertSame('revoked:1', $holder);
        $this->assertSame('rejected:issuer_no_longer_covers', $contender);
        $this->assertSame(0, $this->teacherGrants($school, $target, $teacher));
    }

    #[Test]
    public function a_grant_committed_first_makes_the_revocation_wait_then_both_apply(): void
    {
        [$school, $issuer, $issuerMembership, $target, $teacher] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('grant', $school->id, $target->id, $teacher->id, $issuer->id),
            $this->op('revoke-issuer', $school->id, $issuerMembership->id),
        );

        $this->assertSame('granted', $holder);
        $this->assertSame('revoked:1', $contender);
        $this->assertSame(1, $this->teacherGrants($school, $target, $teacher));
    }
}
