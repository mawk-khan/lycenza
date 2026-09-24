<?php

namespace Tests\Feature\Platform\Groups;

use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0N.5 (ADR 0045 section 10): Group authority races between real OS
 * processes, overlap forced and verified (ForcesConcurrentOverlap), never
 * slept. The invariant in every order: no elevation survives whose Group
 * authority was already removed, and no duplicate active grant exists.
 * (A self-grant has no race to lose: it is a row CHECK, proven in
 * Postgres\GroupAuthorityDatabaseInvariantsTest.)
 */
class GroupAuthorityConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap, GroupTestHelpers;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<User> */
    private array $users = [];

    /** @var list<School> */
    private array $schools = [];

    private ?SchoolGroup $group = null;

    private string $script = __DIR__.'/../../../Support/group-authority-op.php';

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $userIds = array_map(fn (User $u) => $u->id, $this->users);

        $admin->table('school_elevations')->whereIn('actor_user_id', $userIds)->delete();
        if ($this->group !== null) {
            $admin->table('group_role_assignments')->where('school_group_id', $this->group->id)->delete();
            $admin->table('school_group_members')->where('school_group_id', $this->group->id)->delete();
            $admin->table('school_groups')->where('id', $this->group->id)->delete();
        }
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $userIds)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $userIds)->delete();
        $admin->table('user_mfa_recovery_codes')->whereIn('user_id', $userIds)->delete();
        $admin->table('user_mfa_factors')->whereIn('user_id', $userIds)->delete();
        $admin->table('users')->whereIn('id', $userIds)->delete();
        foreach ($this->schools as $school) {
            $admin->table('schools')->where('id', $school->id)->delete();
        }

        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: User, 2: School, 3: GroupRoleAssignment, 4: string}
     */
    private function world(): array
    {
        $platform = $this->platformAdmin();
        $school = $this->createSchool();
        $this->group = $this->createGroup([$school]);
        $actor = $this->groupAdmin($this->group);
        [$code] = $this->issueRecoveryCodes($actor, 1);
        $grant = GroupRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $this->users = [$platform, $actor, User::query()->findOrFail($grant->granted_by_user_id)];
        $this->schools = [$school];

        return [$platform, $actor, $school, $grant, $code];
    }

    private function activeElevations(User $actor): int
    {
        return DB::table('school_elevations')->where('actor_user_id', $actor->id)->where('status', 'active')->count();
    }

    #[Test]
    public function a_start_racing_ahead_of_a_removal_is_terminated_by_it(): void
    {
        [$platform, $actor, $school, , $code] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'start', $actor->id, $this->group->id, $school->id, $code],
            ['php', $this->script, 'remove', $platform->id, $this->group->id, $school->id],
        );

        $this->assertSame('started', $holder);
        $this->assertSame('removed', $contender);
        $this->assertSame(0, $this->activeElevations($actor));
        $this->assertSame('school_left_group', DB::table('school_elevations')->where('actor_user_id', $actor->id)->value('end_reason'));
    }

    #[Test]
    public function a_start_racing_behind_a_removal_is_refused(): void
    {
        [$platform, $actor, $school, , $code] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'remove', $platform->id, $this->group->id, $school->id],
            ['php', $this->script, 'start', $actor->id, $this->group->id, $school->id, $code],
        );

        $this->assertSame('removed', $holder);
        $this->assertSame('rejected:school_not_in_group', $contender);
        $this->assertSame(0, DB::table('school_elevations')->where('actor_user_id', $actor->id)->count());
    }

    #[Test]
    public function a_start_racing_ahead_of_a_revocation_is_terminated_by_it(): void
    {
        [$platform, $actor, $school, $grant, $code] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'start', $actor->id, $this->group->id, $school->id, $code],
            ['php', $this->script, 'revoke', $platform->id, $grant->id],
        );

        $this->assertSame('started', $holder);
        $this->assertSame('revoked', $contender);
        $this->assertSame(0, $this->activeElevations($actor));
        $this->assertSame('group_authority_revoked', DB::table('school_elevations')->where('actor_user_id', $actor->id)->value('end_reason'));
    }

    #[Test]
    public function a_start_racing_behind_a_revocation_is_refused(): void
    {
        [$platform, $actor, $school, $grant, $code] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'revoke', $platform->id, $grant->id],
            ['php', $this->script, 'start', $actor->id, $this->group->id, $school->id, $code],
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('rejected:group_grant_missing', $contender);
        $this->assertSame(0, DB::table('school_elevations')->where('actor_user_id', $actor->id)->count());
    }

    #[Test]
    public function two_concurrent_grants_of_the_same_role_leave_exactly_one_active(): void
    {
        [$platform] = $this->world();
        $person = $this->createUser(['email' => 'race.'.bin2hex(random_bytes(4)).'@example.test']);
        $this->users[] = $person;

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'grant', $platform->id, $this->group->id, $person->email],
            ['php', $this->script, 'grant', $platform->id, $this->group->id, $person->email],
        );

        $this->assertSame('granted', $holder);
        $this->assertSame('invalid:user', $contender);
        $this->assertSame(1, DB::table('group_role_assignments')->where('user_id', $person->id)->whereNull('revoked_at')->count());
    }
}
