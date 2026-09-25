<?php

namespace Tests\Feature\Platform\Roles;

use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0N.7 (ADR 0046 section 4): platform-role grant races between real
 * OS processes, overlap forced and verified (ForcesConcurrentOverlap),
 * never slept. Invariant: never two active grants of one role to one
 * person, and every outcome is coherent and audited.
 */
class PlatformRoleGovernanceConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<User> */
    private array $users = [];

    private string $script = __DIR__.'/../../../Support/platform-role-op.php';

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $ids = array_map(fn (User $u) => $u->id, $this->users);
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $ids)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $ids)->delete();
        $admin->table('users')->whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    /** @return array{0: User, 1: User} */
    private function world(): array
    {
        $root = $this->createUser();
        $this->assignPlatformRole($root, 'platform_super_admin');
        $target = $this->createUser(['email' => 'race.'.bin2hex(random_bytes(4)).'@example.test']);
        $this->users = [$root, $target];

        return [$root, $target];
    }

    private function active(User $target): int
    {
        return DB::table('platform_role_assignments')->where('user_id', $target->id)->whereNull('revoked_at')->count();
    }

    private function events(User $root, string $type): int
    {
        return DB::table('platform_audit_events')->where('actor_user_id', $root->id)->where('event_type', $type)->count();
    }

    #[Test]
    public function two_concurrent_grants_leave_exactly_one_active(): void
    {
        [$root, $target] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'grant', $root->id, $target->email],
            ['php', $this->script, 'grant', $root->id, $target->email],
        );

        $this->assertSame('granted', $holder);
        $this->assertSame('invalid:user', $contender);
        $this->assertSame(1, $this->active($target));
        $this->assertSame(1, $this->events($root, 'platform.role_grant.granted'));
        $this->assertSame(1, $this->events($root, 'platform.role_grant.denied'));
    }

    #[Test]
    public function a_regrant_racing_a_revocation_waits_for_it_and_leaves_one_active_grant(): void
    {
        [$root, $target] = $this->world();
        $grant = PlatformRoleAssignment::query()->create(['user_id' => $target->id, 'role_id' => DB::table('roles')->where('key', 'platform_auditor')->value('id'), 'granted_by_user_id' => $root->id, 'granted_at' => now()]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'revoke', $root->id, $grant->id],
            ['php', $this->script, 'grant', $root->id, $target->email],
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('granted', $contender);
        $this->assertSame(1, $this->active($target));
        $this->assertSame(2, DB::table('platform_role_assignments')->where('user_id', $target->id)->count());
    }

    #[Test]
    public function two_concurrent_revocations_revoke_once(): void
    {
        [$root, $target] = $this->world();
        $grant = PlatformRoleAssignment::query()->create(['user_id' => $target->id, 'role_id' => DB::table('roles')->where('key', 'platform_auditor')->value('id'), 'granted_by_user_id' => $root->id, 'granted_at' => now()]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script, 'revoke', $root->id, $grant->id],
            ['php', $this->script, 'revoke', $root->id, $grant->id],
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('invalid:grant', $contender);
        $this->assertSame(0, $this->active($target));
        $this->assertSame(1, $this->events($root, 'platform.role_grant.revoked'));
    }
}
