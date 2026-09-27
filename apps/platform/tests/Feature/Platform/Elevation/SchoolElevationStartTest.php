<?php

namespace Tests\Feature\Platform\Elevation;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Support\Domains\DomainState;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 sections 5, 6, 9, 13, 15): starting a platform
 * elevation -- exact target, confirmation, fresh MFA, fixed 30 minutes,
 * audited -- and every refusal, audited or not as the ADR requires.
 */
class SchoolElevationStartTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, ElevationTestHelpers;

    #[Test]
    public function a_platform_admin_enters_one_school_for_exactly_thirty_minutes_with_an_audited_start(): void
    {
        $this->freezeSecond();
        $school = $this->createSchool(['name' => 'Target School']);
        $admin = $this->platformAdmin();

        $elevation = $this->elevate($admin, $school);

        $this->assertSame($school->id, $elevation->school_id);
        $this->assertSame('operational_support', $elevation->reason_code);
        $this->assertTrue($elevation->started_at->equalTo(now()));
        $this->assertTrue($elevation->expires_at->equalTo(now()->addMinutes(30)));

        // The session holds only the pointer; no ordinary selection.
        $this->assertSame($elevation->id, session(ResolvePlatformElevation::SESSION_KEY));
        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));

        [$started] = $this->elevationEvents($admin, ElevationAudit::STARTED);
        $this->assertSame($school->id, $started->subject_id);
        $this->assertEquals([
            'elevation_id' => $elevation->id,
            'reason_code' => 'operational_support',
            'expires_at' => $elevation->expires_at->toIso8601String(),
            'authority_type' => 'platform',
        ], $started->metadata);
        $this->assertNotNull($started->ip_address);

        // Elevation is not membership: no membership or School role exists.
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $admin->id)->count());

        $this->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platformElevation.isElevated', true)
            ->where('platformElevation.notice', 'started')
            ->where('elevation.schoolName', 'Target School')
            ->where('elevation.expiresAt', $elevation->expires_at->toIso8601String())
            ->where('activeSchool', null)
            ->has('memberships', 0)
        );
    }

    #[Test]
    public function the_confirmation_page_names_the_school_only_after_an_exact_match_by_uuid_or_active_domain(): void
    {
        $school = $this->createSchool(['name' => 'Domain School']);
        $this->createSchoolDomain($school, 'erp.domain-school.org');
        $admin = $this->platformAdmin();
        $this->actingAs($admin);

        foreach ([$school->id, strtoupper($school->id), 'erp.domain-school.org', 'ERP.DOMAIN-SCHOOL.org', 'erp.domain-school.org.'] as $target) {
            $this->post('/app/platform/elevation/confirm', ['target' => $target, 'reason_code' => 'incident_response'])
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('App/Platform/ConfirmEnterSchool')
                    ->where('schoolName', 'Domain School')
                    ->where('reason.value', 'incident_response')
                    ->where('maxMinutes', 30)
                );
        }

        // Nothing started by confirming.
        $this->assertSame(0, SchoolElevation::query()->count());
        $this->assertSame([], $this->elevationEvents($admin));
    }

    #[Test]
    public function no_partial_unverified_or_malformed_target_resolves_and_the_refusal_is_uniform_and_audited(): void
    {
        $school = $this->createSchool(['name' => 'Hidden School']);
        $this->createSchoolDomain($school, 'erp.hidden-school.org');
        $this->createSchoolDomain($school, 'erp.unverified-school.org', DomainState::PendingVerification);
        $this->createSchoolDomain($school, 'erp.suspended-school.org', DomainState::Suspended);
        $admin = $this->platformAdmin();
        $this->actingAs($admin);

        $cases = [
            'hidden' => 'target_malformed',                 // not a domain, not a UUID
            'hidden-school.org' => 'target_not_found',       // partial domain
            'erp.unverified-school.org' => 'target_not_found', // pending (unverified) domain
            'erp.suspended-school.org' => 'target_not_found',  // suspended domain: only ACTIVE resolves
            (string) Str::uuid() => 'target_not_found',      // unknown School id
            'Hidden School' => 'target_malformed',           // a name is never searched
            'erp.hidden-school.org%' => 'target_malformed',
        ];

        foreach ($cases as $target => $outcome) {
            $response = $this->post('/app/platform/elevation/confirm', ['target' => $target, 'reason_code' => 'operational_support']);

            $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Platform/EnterSchool')
                ->where('errors.target', 'That School cannot be entered.')
                ->missing('schoolName')
            );
            $denial = $this->assertDenied($admin, $outcome);
            $this->assertEquals(['outcome_code' => $outcome, 'reason_code' => 'operational_support', 'authority_type' => 'platform'], $denial->metadata);
        }
    }

    #[Test]
    public function an_inactive_school_gets_the_same_refusal(): void
    {
        $school = $this->createSchool(['status' => 'suspended']);
        $admin = $this->platformAdmin();

        $this->startElevation($admin, $school)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('errors.target', 'That School cannot be entered.')
        );

        $this->assertSame($school->id, $this->assertDenied($admin, 'target_inactive')->subject_id);
        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function an_actor_without_the_capability_is_refused_with_403_and_audited(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->enrollActiveMfaFactor($user);
        $this->actingAs($user);

        $this->get('/app/platform/elevation')->assertForbidden();
        $this->post('/app/platform/elevation/confirm', ['target' => $school->id, 'reason_code' => 'operational_support'])->assertForbidden();
        $this->assertDenied($user, 'capability_missing');

        $this->postJson('/app/platform/elevation', ['target' => $school->id, 'reason_code' => 'operational_support', 'confirmed' => true, 'code' => '000000'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function school_roles_never_hold_the_elevation_capability(): void
    {
        [$schoolAdmin, $school] = $this->createSchoolAdmin('school_admin');
        $this->enrollActiveMfaFactor($schoolAdmin);
        $this->actingAs($schoolAdmin);

        $this->post('/app/platform/elevation/confirm', ['target' => $school->id, 'reason_code' => 'operational_support'])->assertForbidden();
        $this->assertDenied($schoolAdmin, 'capability_missing');
    }

    #[Test]
    public function a_disabled_actor_is_refused(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $admin->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->startElevation($admin->fresh(), $school)->assertForbidden();
        $this->assertDenied($admin, 'actor_disabled');
    }

    #[Test]
    public function a_member_of_the_target_school_is_sent_to_ordinary_selection(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->createMembership($admin, $school);

        $this->startElevation($admin, $school)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('errors.target', 'You are a member of that School: select it from your School list instead.')
        );
        $this->assertDenied($admin, 'actor_is_member');
    }

    #[Test]
    public function an_existing_active_elevation_blocks_another_start_for_any_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $admin = $this->platformAdmin();
        $recovery = $this->issueRecoveryCodes($admin, 1);

        $this->elevate($admin, $schoolA);

        $this->postJson('/app/platform/elevation', [
            'target' => $schoolB->id, 'reason_code' => 'operational_support', 'confirmed' => true, 'code' => $recovery[0],
        ])->assertStatus(409)->assertJsonPath('error.code', 'already_elevated');

        $this->assertDenied($admin, 'already_elevated');
        $this->assertSame(1, SchoolElevation::query()->where('actor_user_id', $admin->id)->count());
    }

    #[Test]
    public function an_unknown_reason_or_a_missing_confirmation_is_a_plain_validation_error_and_not_audited(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();

        foreach ([['reason_code' => 'other'], ['reason_code' => 'Customer asked me to'], ['reason_code' => '']] as $override) {
            $this->startElevation($admin, $school, $override)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('errors.reason_code', 'Choose one of the listed reasons.')
            );
        }

        $this->startElevation($admin, $school, ['confirmed' => ''])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Platform/ConfirmEnterSchool')
            ->where('errors.confirmed', 'Confirm that you are entering this School.')
        );

        $this->postJson('/app/platform/elevation', ['target' => $school->id, 'reason_code' => 'other', 'confirmed' => true, 'code' => $this->totpFor($admin)])
            ->assertStatus(422);

        $this->assertSame([], $this->elevationEvents($admin));
        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function every_approved_reason_code_starts_an_elevation(): void
    {
        foreach (['operational_support', 'security_investigation', 'configuration_assistance', 'incident_response'] as $reason) {
            $admin = $this->platformAdmin();
            $this->startElevation($admin, $this->createSchool(), ['reason_code' => $reason])->assertRedirect('/app');
            $this->assertSame($reason, SchoolElevation::query()->where('actor_user_id', $admin->id)->value('reason_code'));
        }
    }

    #[Test]
    public function without_an_enrolled_factor_the_start_is_refused_403_mfa_required_not_enrolled(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin(withMfa: false);
        $this->actingAs($admin);

        $this->get('/app/platform/elevation')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('mfaEnrolled', false));

        $this->postJson('/app/platform/elevation', ['target' => $school->id, 'reason_code' => 'operational_support', 'confirmed' => true, 'code' => '123456'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_required_not_enrolled');

        $this->assertDenied($admin, 'mfa_not_enrolled');
        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function a_wrong_code_starts_nothing_is_audited_and_does_not_refresh_assurance(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->subMinutes(50)->toIso8601String()]);

        $this->startElevation($admin, $school, ['code' => '000000'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Platform/ConfirmEnterSchool')
            ->where('errors.code', 'That code is not valid.')
        );

        $this->assertDenied($admin, 'mfa_verification_failed');
        $this->assertSame(0, SchoolElevation::query()->count());
        $this->assertTrue(now()->subMinutes(49)->greaterThan(session('mfa_verified_at')));
    }

    #[Test]
    public function login_time_assurance_alone_is_not_enough_every_start_re_verifies(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->toIso8601String()]);

        $this->startElevation($admin, $school, ['code' => ''])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('errors.code', 'Enter a current authentication code.')
        );
        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function a_totp_code_cannot_be_replayed_and_a_recovery_code_works_once(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $admin = $this->platformAdmin();
        $code = $this->totpFor($admin);
        [$recovery] = $this->issueRecoveryCodes($admin, 1);

        $this->startElevation($admin, $schoolA, ['code' => $code])->assertRedirect('/app');
        $this->post('/app/platform/elevation/exit')->assertRedirect('/app');

        // Same TOTP step again: refused as a replay.
        $this->startElevation($admin, $schoolB, ['code' => $code])->assertOk();
        $this->assertDenied($admin, 'mfa_verification_failed');

        $this->startElevation($admin, $schoolB, ['code' => $recovery])->assertRedirect('/app');
        $this->post('/app/platform/elevation/exit');

        $this->startElevation($admin, $schoolA, ['code' => $recovery])->assertOk();
        $this->assertDenied($admin, 'mfa_verification_failed');
    }

    #[Test]
    public function a_successful_start_refreshes_mfa_assurance_and_expiry_never_outlives_it(): void
    {
        $this->freezeSecond();
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->subMinutes(59)->toIso8601String()]);

        $elevation = $this->elevate($admin, $school);

        $this->assertSame(now()->toIso8601String(), session('mfa_verified_at'));
        $assuranceEnds = now()->addMinutes((int) config('mfa.assurance_window_minutes'));
        $this->assertTrue($elevation->expires_at->lessThanOrEqualTo($assuranceEnds));
        $this->assertTrue($elevation->expires_at->equalTo(now()->addMinutes(30)));
    }

    #[Test]
    public function starting_clears_an_ordinary_school_selection_and_regenerates_the_session(): void
    {
        $member = $this->createSchool();
        $target = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->createMembership($admin, $member);
        $this->actingAs($admin)->post("/app/schools/{$member->id}/activate")->assertRedirect('/app');
        $before = session()->getId();

        $this->elevate($admin, $target);

        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));
        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function the_start_page_offers_no_school_list_and_only_the_four_reasons(): void
    {
        $this->createSchool(['name' => 'Must Not Appear']);
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->get('/app/platform/elevation');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Platform/EnterSchool')
            ->where('reasons', [
                ['value' => 'operational_support', 'label' => 'Operational support'],
                ['value' => 'security_investigation', 'label' => 'Security investigation'],
                ['value' => 'configuration_assistance', 'label' => 'Configuration assistance'],
                ['value' => 'incident_response', 'label' => 'Incident response'],
            ])
            ->where('maxMinutes', 30)
        );
        $this->assertStringNotContainsString('Must Not Appear', $response->getContent());

        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('platformElevation.canStart', true));
    }

    #[Test]
    public function an_account_without_the_elevate_capability_sees_no_entry_point(): void
    {
        $admin = $this->createUser();

        $this->actingAs($admin)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('platformElevation.canStart', false));
    }
}
