<?php

namespace Tests\Feature\Platform\Elevation;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\PlatformRoleAssignment;
use App\Models\SchoolElevation;
use App\Support\Auth\Mfa\MfaAdminResetService;
use App\Support\Auth\Mfa\MfaFactorService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\ElevationContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 sections 4, 10-12, 15): per-request validation of
 * an active elevation, every way it ends, and that a finished elevation
 * never comes back.
 */
class SchoolElevationLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures, ElevationTestHelpers;

    #[Test]
    public function a_valid_elevation_is_resolved_on_every_request_and_shown_in_the_banner(): void
    {
        $school = $this->createSchool(['name' => 'Banner School']);
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        foreach (['/app', '/app/account/security'] as $page) {
            $this->get($page)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
                ->where('elevation.schoolName', 'Banner School')
                ->where('elevation.expiresAt', $elevation->expires_at->toIso8601String())
            );
        }
    }

    #[Test]
    public function exit_ends_the_elevation_once_audited_clears_everything_and_it_cannot_be_reused(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);
        $before = session()->getId();

        $this->post('/app/platform/elevation/exit')->assertRedirect('/app');

        $elevation->refresh();
        $this->assertSame('ended', $elevation->status);
        $this->assertSame('exited', $elevation->end_reason);
        $this->assertNotNull($elevation->ended_at);
        $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
        $this->assertNotSame($before, session()->getId());
        $this->assertTrue(session('inertia.clear_history'));

        [$ended] = $this->elevationEvents($admin, ElevationAudit::ENDED);
        $this->assertEquals(['elevation_id' => $elevation->id, 'end_reason' => 'exited'], $ended->metadata);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('platformElevation.isElevated', false)
            ->where('platformElevation.notice', 'exited')
            ->where('activeSchool', null)
        );

        // Replaying the old pointer restores nothing and is not re-audited.
        $this->withSession([ResolvePlatformElevation::SESSION_KEY => $elevation->id]);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
        $this->post('/app/platform/elevation/exit');
        $this->assertCount(1, $this->elevationEvents($admin, ElevationAudit::ENDED));
    }

    #[Test]
    public function logout_ends_the_elevation_with_the_logout_reason(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame('logout', $elevation->refresh()->end_reason);
        $this->assertSame('ended', $elevation->status);
        $this->assertCount(1, $this->elevationEvents($admin, ElevationAudit::ENDED));
        $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
    }

    #[Test]
    public function an_elevation_past_its_expiry_is_refused_expired_once_and_never_reactivates(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        $this->travel(29)->minutes();
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', true));

        $this->travel(61)->seconds();
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('platformElevation.notice', 'expired')
        );

        $this->assertSame('expired', $elevation->refresh()->status);
        [$expired] = $this->elevationEvents($admin, ElevationAudit::EXPIRED);
        $this->assertEquals(['elevation_id' => $elevation->id, 'expires_at' => $elevation->expires_at->toIso8601String()], $expired->metadata);

        $this->withSession([ResolvePlatformElevation::SESSION_KEY => $elevation->id]);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertCount(1, $this->elevationEvents($admin, ElevationAudit::EXPIRED));
        Artisan::call('platform:expire-school-elevations');
        $this->assertCount(1, $this->elevationEvents($admin, ElevationAudit::EXPIRED));
    }

    #[Test]
    public function the_sweep_expires_abandoned_elevations_once_and_is_idempotent(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);
        $other = $this->platformAdmin();
        $live = $this->elevate($other, $this->createSchool());

        // The first actor's session is simply gone; only the sweep can see it.
        $this->travel(31)->minutes();
        SchoolElevation::query()->whereKey($live->id)->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'exited', 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('platform:expire-school-elevations'));
        $this->assertSame('expired', $elevation->refresh()->status);
        $this->assertSame('ended', $live->refresh()->status);
        Artisan::call('platform:expire-school-elevations');
        $this->assertCount(1, $this->elevationEvents($admin, ElevationAudit::EXPIRED));
        $this->assertSame([], $this->elevationEvents($other, ElevationAudit::EXPIRED));
    }

    #[Test]
    public function a_disabled_actor_loses_the_elevation_on_the_next_request(): void
    {
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $this->createSchool());

        $admin->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->actingAs($admin->fresh())->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertTerminated($elevation, 'actor_disabled');
    }

    #[Test]
    public function a_revoked_capability_ends_the_elevation_within_the_capability_cache_bound(): void
    {
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $this->createSchool());

        PlatformRoleAssignment::query()->where('user_id', $admin->id)->delete();

        // Still inside CapabilityResolver's documented 60-second cache.
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', true));

        $this->travel(61)->seconds();
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('platformElevation.notice', 'capability_revoked')
        );
        $this->assertTerminated($elevation, 'capability_revoked');
    }

    #[Test]
    public function an_in_app_revocation_that_clears_the_cache_ends_it_immediately(): void
    {
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $this->createSchool());

        PlatformRoleAssignment::query()->where('user_id', $admin->id)->delete();
        app(CapabilityResolver::class)->forgetCache($admin);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertTerminated($elevation, 'capability_revoked');
    }

    #[Test]
    public function a_school_that_becomes_ineligible_ends_the_elevation(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        $school->update(['status' => 'suspended']);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertTerminated($elevation, 'school_ineligible');
    }

    #[Test]
    public function becoming_a_member_of_the_target_ends_the_elevation_and_never_turns_it_into_membership_context(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        $this->createMembership($admin, $school);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('activeSchool', null)
            ->where('platformElevation.notice', 'membership_conflict')
        );
        $this->assertTerminated($elevation, 'membership_conflict');
        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));

        // The ordinary flow is required from now on.
        $this->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->assertSame($school->id, session(RequireSchoolContext::SESSION_KEY));
    }

    #[Test]
    public function an_ordinary_selection_alongside_the_pointer_is_a_conflict_that_ends_both(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        // An ordinary selection of a School the actor really belongs to.
        $member = $this->createSchool();
        $this->createMembership($admin, $member);
        $this->withSession([RequireSchoolContext::SESSION_KEY => $member->id]);

        // Neither context survives -- not even for this one request.
        $this->get('/app/school-setup')->assertRedirect('/app');
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null)->where('activeSchool', null));

        $this->assertTerminated($elevation, 'membership_conflict');
        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));
        $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
    }

    #[Test]
    public function an_mfa_reset_or_self_disable_ends_the_elevation(): void
    {
        $adminA = $this->platformAdmin();
        $elevationA = $this->elevate($adminA, $this->createSchool());
        app(MfaAdminResetService::class)->reset($this->platformAdmin(), $adminA);
        $this->assertTerminated($elevationA, 'mfa_factor_revoked');

        $adminB = $this->platformAdmin();
        [$recovery] = $this->issueRecoveryCodes($adminB, 1);
        $elevationB = $this->elevate($adminB, $this->createSchool());
        app(MfaFactorService::class)->disable($adminB, $recovery);
        $this->assertTerminated($elevationB, 'mfa_factor_revoked');

        // And a factor removed by any other path is caught per request.
        $adminC = $this->platformAdmin();
        $elevationC = $this->elevate($adminC, $this->createSchool());
        $adminC->mfaFactors()->update(['status' => 'revoked']);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertTerminated($elevationC, 'mfa_factor_revoked');
    }

    #[Test]
    public function a_forged_malformed_or_foreign_pointer_restores_nothing_and_is_audited(): void
    {
        $owner = $this->platformAdmin();
        $foreign = $this->elevate($owner, $this->createSchool());
        $admin = $this->platformAdmin();

        foreach ([(string) Str::uuid(), 'not-a-uuid', $foreign->id] as $pointer) {
            $this->actingAs($admin)->withSession([ResolvePlatformElevation::SESSION_KEY => $pointer]);

            $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null)->where('platformElevation.isElevated', false));
            $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
            $this->assertDenied($admin, 'elevation_reference_invalid');
        }

        $denials = $this->elevationEvents($admin, ElevationAudit::DENIED);
        $this->assertSame($foreign->id, end($denials)->metadata['elevation_id']);
        // The owner's elevation is untouched.
        $this->assertSame('active', $foreign->refresh()->status);
    }

    #[Test]
    public function ordinary_school_selection_is_refused_while_elevated(): void
    {
        $member = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->createMembership($admin, $member);
        $elevation = $this->elevate($admin, $this->createSchool());

        $this->post("/app/schools/{$member->id}/activate")->assertSessionHasErrors(['school' => 'Exit elevated access before selecting a School.']);

        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));
        $this->assertSame('active', $elevation->refresh()->status);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', true));
    }

    #[Test]
    public function an_elevation_held_by_another_session_is_shown_and_can_be_ended_from_here(): void
    {
        $school = $this->createSchool(['name' => 'Other Session School']);
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        // A new session (another device): no pointer.
        $this->withSession([ResolvePlatformElevation::SESSION_KEY => null]);
        session()->forget(ResolvePlatformElevation::SESSION_KEY);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('platformElevation.canStart', false)
            ->where('platformElevation.activeElsewhere.schoolName', 'Other Session School')
        );

        $this->post('/app/platform/elevation/exit')->assertRedirect('/app');
        $this->assertSame('exited', $elevation->refresh()->end_reason);
    }

    #[Test]
    public function the_request_scoped_elevation_context_never_outlives_its_request(): void
    {
        $admin = $this->platformAdmin();
        $this->elevate($admin, $this->createSchool());

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', true));
        $this->assertFalse(app(ElevationContext::class)->isElevated(), 'Cleared when the request terminated.');

        $this->post('/app/platform/elevation/exit');
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', false));
        $this->assertFalse(app(ElevationContext::class)->isElevated());
    }

    private function assertTerminated(SchoolElevation $elevation, string $reason): void
    {
        $elevation->refresh();
        $this->assertSame('terminated', $elevation->status);
        $this->assertSame($reason, $elevation->end_reason);

        $events = $this->elevationEvents($elevation->actor, ElevationAudit::TERMINATED);
        $this->assertCount(1, $events);
        $this->assertEquals(['elevation_id' => $elevation->id, 'end_reason' => $reason], $events[0]->metadata);
    }
}
