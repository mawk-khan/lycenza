<?php

namespace Tests\Feature\Organization;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12C (ADR 0080 §8): the rich School profile and its institutional
 * identity.
 *
 * - Ordinary details (contact, address, board) under `school.profile.manage`;
 *   a changed phone is E.164, a website is http(s), a country is two letters;
 *   legacy values never block an unrelated edit.
 * - The identity (legal name, registration and affiliation references)
 *   changes only through the identity form: capability, a closed reason, a
 *   fresh MFA code, current authority under the School lock and a stale-form
 *   check -- audited with field names, never values.
 * - Principal views only; Teacher, Platform and Group operators get nothing;
 *   one School's edit never reaches another.
 */
class SchoolProfileTest extends TestCase
{
    // CreatesMfaFixtures arrives through GroupTestHelpers (ElevationTestHelpers).
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** @return array{0: User, 1: list<string>} an administrator with an MFA factor and single-use recovery codes */
    private function adminWithMfa(School $school): array
    {
        $admin = $this->schoolAdminFor($school);
        $this->enrollActiveMfaFactor($admin);

        return [$admin, $this->issueRecoveryCodes($admin, 6)];
    }

    /** @param  array<string, mixed>  $overrides */
    private function identity(School $school, string $code, array $overrides = []): array
    {
        $school->refresh();

        return [
            'legal_name' => 'Florets Educare Private Limited',
            'registration_reference' => 'SOC-2011-0042',
            'affiliation_reference' => 'AFF-130551',
            'expected_legal_name' => $school->legal_name,
            'expected_registration_reference' => $school->registration_reference,
            'expected_affiliation_reference' => $school->affiliation_reference,
            'reason_code' => 'first_recorded',
            'mfa_code' => $code,
            ...$overrides,
        ];
    }

    private function auditsOf(School $school, string $action): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('school_id', $school->id)->where('event_type', $action)->orderBy('occurred_at')->get()->all());
    }

    // -- Ordinary profile ------------------------------------------------------

    #[Test]
    public function an_administrator_edits_contact_and_structured_address_and_the_page_shows_every_section(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Dubai', 'country_code' => 'AE']);
        $this->enter($this->schoolAdminFor($school), $school);

        $this->put('/app/school-setup/profile', [
            'email' => 'office@florets.example', 'phone' => '+914012345678', 'website' => 'https://florets.example',
            'address_line1' => '8-2-293 Road No. 12', 'address_line2' => 'Banjara Hills', 'city' => 'Hyderabad',
            'state_region' => 'Telangana', 'postal_code' => '500034', 'country_code' => 'in',
        ])->assertRedirect('/app/school-setup/profile')->assertSessionHasNoErrors();

        $props = $this->get('/app/school-setup/profile')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Banjara Hills', 'IN', 'Asia/Dubai', 'INR'], [$props['school']['addressLine2'], $props['school']['countryCode'], $props['school']['timezone'], $props['school']['currency']]);
        $this->assertTrue($props['canManage']);

        $audit = $this->auditsOf($school, 'school.profile_updated');
        $metadata = json_decode($audit[0]->metadata, true);
        $this->assertEqualsCanonicalizing(['email', 'phone', 'website', 'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code'], $metadata['changed']);
        $this->assertStringNotContainsString('Hyderabad', $audit[0]->metadata, 'audit names fields, never addresses or contacts');
        $this->assertStringNotContainsString('florets.example', $audit[0]->metadata);
    }

    #[Test]
    public function contact_validation_is_bounded_and_international_and_never_blocks_a_legacy_value(): void
    {
        $school = $this->createSchool(['phone' => '+91 90000 00000']); // legacy, pre-ORG.12C
        $this->enter($this->schoolAdminFor($school), $school);

        foreach ([
            ['website' => 'javascript:alert(1)'], ['website' => 'ftp://files.example'], ['website' => 'data:text/html,x'],
            ['phone' => '9876543210'], ['phone' => '+44 20 7946 0958'], ['email' => 'not-an-email'],
            ['country_code' => 'IND'], ['country_code' => '1A'], ['country_code' => ''],
        ] as $bad) {
            $this->put('/app/school-setup/profile', $bad)->assertSessionHasErrors(array_keys($bad)[0]);
        }

        $this->put('/app/school-setup/profile', ['phone' => '+91 90000 00000', 'city' => 'Hyderabad'])->assertSessionHasNoErrors();
        $this->put('/app/school-setup/profile', ['phone' => '+442079460958'])->assertSessionHasNoErrors();
        $this->put('/app/school-setup/profile', ['website' => 'http://florets.example'])->assertSessionHasNoErrors();
        $this->assertSame(['+442079460958', 'http://florets.example', 'Hyderabad'], array_values($school->refresh()->only(['phone', 'website', 'city'])));
    }

    #[Test]
    public function a_legacy_website_or_email_never_blocks_an_unrelated_edit_and_the_web_form_never_edits_name_or_code(): void
    {
        $school = $this->createSchool(['website' => 'www.legacy.edu', 'email' => 'legacy@@school', 'name' => 'Legacy School', 'code' => 'LEG']);
        $this->enter($this->schoolAdminFor($school), $school);

        $this->put('/app/school-setup/profile', ['website' => 'www.legacy.edu', 'email' => 'legacy@@school', 'city' => 'Pune', 'name' => 'Renamed', 'code' => 'NEW'])->assertSessionHasNoErrors();
        $this->assertSame(['Pune', 'Legacy School', 'LEG'], array_values($school->refresh()->only(['city', 'name', 'code'])));
        $this->put('/app/school-setup/profile', ['website' => 'www.still-bad.edu'])->assertSessionHasErrors('website');
    }

    #[Test]
    public function the_ordinary_form_never_changes_identity_status_or_timezone(): void
    {
        $school = $this->createSchool(['legal_name' => 'Original Trust', 'timezone' => 'Asia/Kolkata']);
        $this->enter($this->schoolAdminFor($school), $school);

        $this->put('/app/school-setup/profile', [
            'legal_name' => 'Hijacked Ltd', 'registration_reference' => 'X', 'affiliation_reference' => 'Y',
            'status' => 'suspended', 'timezone' => 'UTC', 'city' => 'Pune',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['Original Trust', null, null, 'active', 'Asia/Kolkata', 'Pune'],
            array_values($school->refresh()->only(['legal_name', 'registration_reference', 'affiliation_reference', 'status', 'timezone', 'city'])),
        );
        $this->assertSame([], $this->auditsOf($school, 'school.institutional_identity_changed'));
    }

    #[Test]
    public function a_legacy_school_without_rich_profile_values_still_renders(): void
    {
        $school = $this->createSchool();
        DB::table('schools')->where('id', $school->id)->update(['legal_name' => null, 'email' => null, 'phone' => null, 'website' => null, 'address_line1' => null, 'city' => null]);
        $this->enter($this->schoolAdminFor($school), $school);

        $school = $this->get('/app/school-setup/profile')->assertOk()->viewData('page')['props']['school'];
        $this->assertNull($school['legalName']);
        $this->assertNull($school['registrationReference']);
    }

    #[Test]
    public function the_principal_views_but_never_edits_and_staff_platform_and_group_operators_get_nothing(): void
    {
        $school = $this->createSchool();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $this->enter($principal, $school);
        $this->assertFalse($this->get('/app/school-setup/profile')->assertOk()->viewData('page')['props']['canManage']);
        $this->put('/app/school-setup/profile', ['city' => 'Nope'])->assertForbidden();
        $this->put('/app/school-setup/profile/identity', ['legal_name' => 'Nope', 'reason_code' => 'correction'])->assertForbidden();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->get('/app/school-setup/profile')->assertForbidden();

        $root = $this->createPlatformRoot();
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        foreach ([$manager, $auditor, $this->groupAdmin($this->createGroup([$school]))] as $operator) {
            $this->enter($operator, $school);
            $this->assertNotSame(200, $this->get('/app/school-setup/profile')->status());
            $this->put('/app/school-setup/profile', ['city' => 'Nope']);
        }
        $this->assertNotSame('Nope', $school->refresh()->city);
    }

    #[Test]
    public function an_edit_in_one_school_never_reaches_another(): void
    {
        $a = $this->createSchool(['city' => 'Hyderabad']);
        $b = $this->createSchool(['city' => 'Pune']);
        $shared = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($shared, $b), 'school_admin');

        $this->enter($shared, $a);
        $this->put('/app/school-setup/profile', ['city' => 'Secunderabad'])->assertSessionHasNoErrors();

        $this->assertSame(['Secunderabad', 'Pune'], [$a->refresh()->city, $b->refresh()->city]);
    }

    // -- Institutional identity --------------------------------------------------

    #[Test]
    public function identity_changes_need_the_capability_a_reason_and_a_fresh_mfa_code(): void
    {
        $school = $this->createSchool();
        [$admin, $codes] = $this->adminWithMfa($school);
        $this->enter($admin, $school);

        $this->put('/app/school-setup/profile/identity', $this->identity($school, ''))->assertSessionHasErrors('mfa_code');
        $this->put('/app/school-setup/profile/identity', $this->identity($school, '000000'))->assertSessionHasErrors('mfa_code');
        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[0], ['reason_code' => '']))->assertSessionHasErrors('reason_code');
        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[0], ['reason_code' => 'because']))->assertSessionHasErrors('reason_code');
        $this->assertNull($school->refresh()->legal_name, 'nothing changed on a refused attempt');

        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[1]))->assertRedirect('/app/school-setup/profile')->assertSessionHasNoErrors();
        $this->assertSame(
            ['Florets Educare Private Limited', 'SOC-2011-0042', 'AFF-130551'],
            array_values($school->refresh()->only(['legal_name', 'registration_reference', 'affiliation_reference'])),
        );

        $audit = $this->auditsOf($school, 'school.institutional_identity_changed');
        $this->assertCount(1, $audit);
        $metadata = json_decode($audit[0]->metadata, true);
        $this->assertSame(['legal_name', 'registration_reference', 'affiliation_reference'], $metadata['changed']);
        $this->assertSame('first_recorded', $metadata['reason_code']);
        $this->assertArrayHasKey('mfa_verified_at', $metadata);
        $this->assertStringNotContainsString('Florets', $audit[0]->metadata, 'never the values');
        $this->assertStringNotContainsString('SOC-2011', $audit[0]->metadata);
        $this->assertSame($admin->id, $audit[0]->actor_user_id);
    }

    #[Test]
    public function an_administrator_without_an_mfa_factor_cannot_change_identity(): void
    {
        $school = $this->createSchool();
        $this->enter($this->schoolAdminFor($school), $school);

        $this->put('/app/school-setup/profile/identity', $this->identity($school, '123456'))->assertSessionHasErrors('mfa_code');
        $this->assertNull($school->refresh()->legal_name);
    }

    #[Test]
    public function a_capability_holder_without_profile_manage_is_refused_before_any_code_is_spent(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, ['school.profile.view', 'school.settings.manage']);
        $this->enrollActiveMfaFactor($viewer);
        $codes = $this->issueRecoveryCodes($viewer, 1);
        $this->enter($viewer, $school);

        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[0]))->assertForbidden();
        $this->assertSame(1, DB::table('user_mfa_recovery_codes')->where('user_id', $viewer->id)->whereNull('consumed_at')->count(), 'the code is not consumed');
    }

    #[Test]
    public function a_stale_identity_form_never_overwrites_a_newer_value(): void
    {
        $school = $this->createSchool(['legal_name' => 'Original Trust']);
        [$admin, $codes] = $this->adminWithMfa($school);
        $this->enter($admin, $school);
        $stale = $this->identity($school, $codes[0], ['legal_name' => 'Stale Edit']);

        // Someone else changes it first.
        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[1], ['legal_name' => 'Newer Trust', 'reason_code' => 'legal_change']))->assertSessionHasNoErrors();
        $this->put('/app/school-setup/profile/identity', $stale)->assertSessionHasErrors('identity');

        $this->assertSame('Newer Trust', $school->refresh()->legal_name);
        $this->assertCount(1, $this->auditsOf($school, 'school.institutional_identity_changed'));
    }

    #[Test]
    public function an_unchanged_identity_submit_writes_and_audits_nothing(): void
    {
        $school = $this->createSchool(['legal_name' => 'Same Trust']);
        [$admin, $codes] = $this->adminWithMfa($school);
        $this->enter($admin, $school);

        $this->put('/app/school-setup/profile/identity', $this->identity($school, $codes[0], ['legal_name' => 'Same Trust', 'registration_reference' => null, 'affiliation_reference' => '']))->assertSessionHasNoErrors();
        $this->assertSame([], $this->auditsOf($school, 'school.institutional_identity_changed'));
    }

    #[Test]
    public function an_identity_change_in_one_school_never_touches_another(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool(['legal_name' => 'B Trust']);
        [$admin, $codes] = $this->adminWithMfa($a);
        $this->assignSchoolRole($this->createMembership($admin, $b), 'school_admin');
        $this->enter($admin, $a);

        $this->put('/app/school-setup/profile/identity', $this->identity($a, $codes[0]))->assertSessionHasNoErrors();
        $this->assertSame('B Trust', $b->refresh()->legal_name);
        $this->assertSame([], $this->auditsOf($b, 'school.institutional_identity_changed'));
    }
}
