<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Mail\GuardianAccountInvitationMail;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.3 -- HTTP-layer coverage for the Guardian account
 * invitation admin actions (invite/resend/revoke): authorization
 * (both capabilities required, brief §36), the happy path, and the
 * admin read-model surfaced on the Guardian Show page.
 */
class GuardianAccountInvitationHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_school_admin_can_invite_resend_and_revoke_a_guardian_account_invitation(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->activate($admin, $school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")
            ->assertRedirect("/app/guardians/{$guardian->id}");

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountInvitation.pending.status', 'pending')
        );

        $this->post("/app/guardians/{$guardian->id}/account-invitation/resend")
            ->assertRedirect("/app/guardians/{$guardian->id}");

        $this->delete("/app/guardians/{$guardian->id}/account-invitation")
            ->assertRedirect("/app/guardians/{$guardian->id}");

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountInvitation.pending', null)
        );

        Mail::assertSent(GuardianAccountInvitationMail::class, 2);
    }

    #[Test]
    public function inviting_with_only_guardians_manage_is_forbidden(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $actor = $this->createUserWithCapabilities($school, ['guardians.manage', 'guardians.view']);
        $this->activate($actor, $school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")->assertForbidden();
        Mail::assertNothingSent();
    }

    #[Test]
    public function inviting_with_only_school_members_manage_is_forbidden(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $actor = $this->createUserWithCapabilities($school, ['school.members.manage', 'guardians.view']);
        $this->activate($actor, $school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")->assertForbidden();
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_principal_cannot_invite_a_guardian_account_by_default(): void
    {
        // The seeded `principal` role has guardians.manage but only
        // school.members.view, not school.members.manage -- inviting
        // provisions a new SchoolMembership, so it stays school_admin-only
        // by default (see GuardianAccountInvitationController's docblock).
        $school = $this->createSchool();
        $principal = $this->createUser();
        $membership = $this->createMembership($principal, $school);
        $this->assignSchoolRole($membership, 'principal');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->activate($principal, $school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")->assertForbidden();
    }

    #[Test]
    public function inviting_a_guardian_with_no_email_contact_returns_a_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->activate($admin, $school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")
            ->assertSessionHasErrors('guardian');
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->post("/app/guardians/{$guardian->id}/account-invitation")->assertRedirect('/login');
    }
}
