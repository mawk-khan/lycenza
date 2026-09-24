<?php

namespace Tests\Feature\Platform\Groups;

use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Platform\Elevation\ElevationTestHelpers;

/**
 * Phase 0N.5 test helpers: School Groups, Group grants and the real
 * Group-derived elevation HTTP flow.
 */
trait GroupTestHelpers
{
    use ElevationTestHelpers;

    /**
     * @param  list<School>  $schools
     */
    protected function createGroup(array $schools = [], string $name = 'Test Trust'): SchoolGroup
    {
        $group = SchoolGroup::query()->create(['name' => $name, 'slug' => 'trust-'.Str::lower(Str::random(10))]);

        foreach ($schools as $school) {
            $group->schools()->attach($school->id, ['id' => (string) Str::uuid7()]);
        }

        return $group;
    }

    protected function grantGroupRole(User $user, SchoolGroup $group, string $roleKey = 'group_admin', ?User $grantor = null): GroupRoleAssignment
    {
        return GroupRoleAssignment::query()->create([
            'user_id' => $user->id,
            'school_group_id' => $group->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            'granted_by_user_id' => ($grantor ?? $this->createUser())->id,
            'granted_at' => now(),
        ]);
    }

    /** A Group-only human (no platform role, no School membership) with MFA. */
    protected function groupAdmin(SchoolGroup $group, bool $withMfa = true): User
    {
        $user = $this->createUser();
        $this->grantGroupRole($user, $group);

        if ($withMfa) {
            $secret = app(Google2FA::class)->generateSecretKey();
            $this->enrollActiveMfaFactor($user, $secret);
            $this->mfaSecrets[$user->id] = $secret;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function startGroupElevation(User $actor, SchoolGroup $group, School|string $target, array $overrides = []): TestResponse
    {
        $this->actingAs($actor);

        return $this->post("/app/groups/{$group->id}/elevation", array_merge([
            'target' => $target instanceof School ? $target->id : $target,
            'reason_code' => 'operational_support',
            'confirmed' => '1',
            'code' => $this->totpFor($actor),
        ], $overrides));
    }

    protected function elevateViaGroup(User $actor, SchoolGroup $group, School $school, array $overrides = []): SchoolElevation
    {
        $this->startGroupElevation($actor, $group, $school, $overrides)->assertRedirect('/app');

        return SchoolElevation::query()->where('actor_user_id', $actor->id)->where('status', 'active')->firstOrFail();
    }
}
