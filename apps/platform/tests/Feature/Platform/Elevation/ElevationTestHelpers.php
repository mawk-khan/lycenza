<?php

namespace Tests\Feature\Platform\Elevation;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;

/**
 * Phase 0N.3 test helpers: a Platform Super Admin with an active MFA
 * factor, and the real HTTP start flow.
 */
trait ElevationTestHelpers
{
    use CreatesMfaFixtures;

    /** @var array<string, string> TOTP secret per user id */
    private array $mfaSecrets = [];

    protected function platformAdmin(bool $withMfa = true): User
    {
        $admin = $this->createUser();
        $this->assignPlatformRole($admin, 'platform_super_admin');

        if ($withMfa) {
            $secret = app(Google2FA::class)->generateSecretKey();
            $this->enrollActiveMfaFactor($admin, $secret);
            $this->mfaSecrets[$admin->id] = $secret;
        }

        return $admin;
    }

    protected function totpFor(User $user): string
    {
        return $this->currentTotpCodeFor($this->mfaSecrets[$user->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function startElevation(User $admin, School|string $target, array $overrides = []): TestResponse
    {
        $this->actingAs($admin);

        return $this->post('/app/platform/elevation', array_merge([
            'target' => $target instanceof School ? $target->id : $target,
            'reason_code' => 'operational_support',
            'confirmed' => '1',
            'code' => $this->totpFor($admin),
        ], $overrides));
    }

    protected function elevate(User $admin, School $school): SchoolElevation
    {
        $this->startElevation($admin, $school)->assertRedirect('/app');

        $elevation = SchoolElevation::query()->where('actor_user_id', $admin->id)->where('status', 'active')->firstOrFail();
        $this->assertSame($elevation->id, session(ResolvePlatformElevation::SESSION_KEY));

        return $elevation;
    }

    /**
     * @return list<PlatformAuditEvent>
     */
    protected function elevationEvents(User $actor, ?string $type = null): array
    {
        return PlatformAuditEvent::query()
            ->where('actor_user_id', $actor->id)
            ->where('event_type', 'like', 'platform.school_elevation.%')
            ->when($type !== null, fn ($q) => $q->where('event_type', $type))
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->all();
    }

    protected function assertDenied(User $actor, string $outcome): PlatformAuditEvent
    {
        $denials = $this->elevationEvents($actor, ElevationAudit::DENIED);
        $this->assertNotEmpty($denials, "Expected a denied elevation audit ({$outcome}).");
        $last = end($denials);
        $this->assertSame($outcome, $last->metadata['outcome_code']);

        return $last;
    }
}
