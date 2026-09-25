<?php

namespace Tests\Feature\Platform\Schools;

use App\Domain\Platform\Application\Schools\SchoolLifecycleAudit;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Platform\Groups\GroupTestHelpers;

/**
 * Phase 0N.9 test helpers: the real HTTP School lifecycle flow, with a
 * fresh MFA code for every change (each TOTP step is single-use, so the
 * helper clears the factor's last used step first -- test-only).
 */
trait SchoolLifecycleTestHelpers
{
    use GroupTestHelpers;

    protected function freshCode(User $user): string
    {
        UserMfaFactor::query()->where('user_id', $user->id)->update(['last_used_totp_step' => null]);

        return $this->totpFor($user);
    }

    protected function resetLifecycleLimiter(User $user): void
    {
        RateLimiter::clear(md5('platform-school-lifecycle'.$user->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createSchoolViaPlatform(User $root, User|string $admin, array $overrides = []): TestResponse
    {
        $this->resetLifecycleLimiter($root);
        $this->actingAs($root);

        return $this->post('/app/platform/schools', array_merge([
            'name' => 'Lifecycle School',
            'slug' => 'lifecycle-'.Str::lower(Str::random(10)),
            'admin' => $admin instanceof User ? $admin->email : $admin,
            'confirmed' => '1',
            'mfa_code' => $this->freshCode($root),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function provisionSchool(User $root, User $admin, array $overrides = []): School
    {
        $slug = $overrides['slug'] ?? 'lifecycle-'.Str::lower(Str::random(10));

        $this->createSchoolViaPlatform($root, $admin, ['slug' => $slug] + $overrides)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return School::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function lifecycleAction(User $root, School $school, string $action, array $overrides = []): TestResponse
    {
        $this->resetLifecycleLimiter($root);
        $this->actingAs($root);

        return $this->post("/app/platform/schools/{$school->id}/{$action}", array_merge([
            'confirmed' => '1',
            'mfa_code' => $this->freshCode($root),
        ], $overrides));
    }

    protected function activeSchoolViaPlatform(User $root, User $admin): School
    {
        $school = $this->provisionSchool($root, $admin);
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors()->assertRedirect("/app/platform/schools/{$school->id}");

        return $school->fresh();
    }

    protected function suspendViaPlatform(User $root, School $school, string $reason = 'administrative_hold'): void
    {
        $this->lifecycleAction($root, $school, 'suspend', ['reason_code' => $reason])->assertSessionHasNoErrors()->assertRedirect();
    }

    /**
     * @return list<PlatformAuditEvent>
     */
    protected function lifecycleEvents(School $school, string $type): array
    {
        return PlatformAuditEvent::query()
            ->where('subject_id', $school->id)
            ->where('event_type', $type)
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lifecycleDenials(User $actor): array
    {
        return PlatformAuditEvent::query()
            ->where('actor_user_id', $actor->id)
            ->where('event_type', SchoolLifecycleAudit::DENIED)
            ->orderBy('occurred_at')->orderBy('id')
            ->pluck('metadata')->all();
    }
}
