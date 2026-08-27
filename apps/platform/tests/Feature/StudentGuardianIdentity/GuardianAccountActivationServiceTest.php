<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\Exceptions\ExistingAccountConfirmationRequiredException;
use App\Domain\Identity\Application\Exceptions\InvitationNotUsableException;
use App\Domain\Identity\Application\GuardianAccountActivationService;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.3 -- App\Domain\Identity\Application\GuardianAccountActivationService.
 * Every scenario issues a real invitation via AccountInvitationService
 * first, then exercises `accept()` -- never constructs an invitation
 * row by hand, matching this suite's "always the real service" rule.
 */
class GuardianAccountActivationServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function invitations(): AccountInvitationService
    {
        return app(AccountInvitationService::class);
    }

    private function activation(): GuardianAccountActivationService
    {
        return app(GuardianAccountActivationService::class);
    }

    private function invite($school, $guardian, $admin, string $email): GuardianAccountInvitation
    {
        $this->createGuardianContact($guardian, ContactType::Email, $email);

        return $this->invitations()->invite($school, $guardian, $admin);
    }

    #[Test]
    public function accepting_with_no_existing_user_creates_a_user_membership_and_link(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'newguardian@example.com');

        $link = $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');

        $this->assertSame($guardian->id, $link->guardian_id);
        $this->assertSame('active', $link->membership->status);
        $this->assertSame('newguardian@example.com', $link->membership->user->email);
        $this->assertTrue(Hash::check('a-strong-password-1', $link->membership->user->password));
        $this->assertNotNull($link->membership->user->email_verified_at);
        $this->assertSame('accepted', app(TenantContext::class)->withSchool($school, fn () => $invitation->fresh())->status);
    }

    #[Test]
    public function accepting_reuses_an_existing_user_and_membership_in_the_same_school(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $existingUser = $this->createUser(['email' => 'dual@example.com']);
        $existingMembership = $this->createMembership($existingUser, $school);
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'dual@example.com');

        $link = $this->activation()->accept($school, $invitation, $existingUser, null);

        $this->assertSame($existingMembership->id, $link->school_membership_id);
        $this->assertSame(1, SchoolMembership::query()->where('user_id', $existingUser->id)->where('school_id', $school->id)->count());
    }

    #[Test]
    public function accepting_as_existing_user_without_being_authenticated_as_that_user_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $existingUser = $this->createUser(['email' => 'existing@example.com']);
        $this->createMembership($existingUser, $school);
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'existing@example.com');

        $this->expectException(ExistingAccountConfirmationRequiredException::class);
        $this->activation()->accept($school, $invitation, null, null);
    }

    #[Test]
    public function accepting_as_existing_user_authenticated_as_a_different_user_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $existingUser = $this->createUser(['email' => 'existing@example.com']);
        $this->createMembership($existingUser, $school);
        $someoneElse = $this->createUser(['email' => 'someone-else@example.com']);
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'existing@example.com');

        $this->expectException(ExistingAccountConfirmationRequiredException::class);
        $this->activation()->accept($school, $invitation, $someoneElse, null);
    }

    #[Test]
    public function a_staff_member_invited_as_a_guardian_reuses_their_staff_membership_and_keeps_their_role(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser(['email' => 'teacher@example.com']);
        $teacherMembership = $this->createMembership($teacher, $school);
        $this->assignSchoolRole($teacherMembership, 'principal');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'teacher@example.com');

        $link = $this->activation()->accept($school, $invitation, $teacher, null);

        $this->assertSame($teacherMembership->id, $link->school_membership_id);
        $this->assertTrue(
            app(CapabilityResolver::class)->canInSchool($teacher, 'school.settings.view', $school),
            'Activating as Guardian must not remove the existing staff role.',
        );
    }

    #[Test]
    public function accepting_for_an_existing_user_with_a_membership_in_a_different_school_creates_a_new_membership_here(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $existingUser = $this->createUser(['email' => 'crossschool@example.com']);
        $this->createMembership($existingUser, $schoolB);
        $guardian = $this->createGuardian($schoolA);
        $invitation = $this->invite($schoolA, $guardian, $adminA, 'crossschool@example.com');

        $link = $this->activation()->accept($schoolA, $invitation, $existingUser, null);

        $this->assertSame($schoolA->id, $link->membership->school_id);
        $this->assertSame(2, SchoolMembership::query()->where('user_id', $existingUser->id)->count());
    }

    #[Test]
    public function accepting_an_expired_invitation_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'expired@example.com');
        app(TenantContext::class)->withSchool($school, fn () => $invitation->forceFill(['expires_at' => now()->subDay()])->save());

        $this->expectException(InvitationNotUsableException::class);
        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');
    }

    #[Test]
    public function accepting_a_revoked_invitation_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'revoked@example.com');
        $this->invitations()->revoke($school, $guardian, $admin);

        $this->expectException(InvitationNotUsableException::class);
        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');
    }

    #[Test]
    public function an_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'oncebysingleuse@example.com');
        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');

        $this->expectException(InvitationNotUsableException::class);
        $this->activation()->accept($school, $invitation, null, 'another-password-2');
    }

    #[Test]
    public function a_guardian_contact_email_change_after_issuance_invalidates_the_invitation(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'original@example.com');

        // The Guardian's only email contact changes after the
        // invitation was issued (brief §29 contact-drift).
        app(TenantContext::class)->withSchool($school, function () use ($guardian) {
            $guardian->contacts()->where('type', ContactType::Email)->update(['is_active' => false]);
        });
        $this->createGuardianContact($guardian, ContactType::Email, 'changed@example.com');

        $this->expectException(InvitationNotUsableException::class);
        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');
    }

    #[Test]
    public function a_domain_preference_recorded_before_activation_survives_activation_unchanged(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'pref@example.com');
        app(CommunicationDomainPreferenceService::class)->setPreferenceForGuardian(
            $school, $guardian, $admin, CommunicationChannel::Email, false,
        );

        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');

        $preference = app(CommunicationDomainPreferenceService::class)->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertNotNull($preference);
        $this->assertSame('disabled', $preference->preference);
    }

    #[Test]
    public function activation_events_are_audited_without_the_password_or_email(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $invitation = $this->invite($school, $guardian, $admin, 'audited@example.com');

        $this->activation()->accept($school, $invitation, null, 'a-strong-password-1');

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'guardian.account_activated')
            ->get());

        $this->assertCount(1, $events);
        $payload = json_encode($events->first()->metadata);
        $this->assertStringNotContainsString('audited@example.com', $payload);
        $this->assertStringNotContainsString('a-strong-password-1', $payload);
    }
}
