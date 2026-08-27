<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasAccountLinkException;
use App\Domain\Identity\Application\Exceptions\GuardianAlreadyHasPendingInvitationException;
use App\Domain\Identity\Application\Exceptions\GuardianHasNoEmailContactException;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Domain\Identity\Mail\GuardianAccountInvitationMail;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.3 -- App\Domain\Identity\Application\AccountInvitationService.
 * Every test uses Mail::fake() (brief §44) -- no real provider is ever
 * contacted.
 */
class AccountInvitationServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function service(): AccountInvitationService
    {
        return app(AccountInvitationService::class);
    }

    #[Test]
    public function inviting_a_guardian_with_an_email_contact_creates_a_pending_invitation_and_sends_mail(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        $invitation = $this->service()->invite($school, $guardian, $admin);

        $this->assertSame('pending', $invitation->status);
        $this->assertSame($guardian->id, $invitation->guardian_id);
        $this->assertNotSame('guardian@example.com', $invitation->destination_email_hash);
        $this->assertSame(hash('sha256', 'guardian@example.com'), $invitation->destination_email_hash);
        Mail::assertSent(GuardianAccountInvitationMail::class);
    }

    #[Test]
    public function inviting_a_guardian_with_no_email_contact_is_denied_and_sends_no_mail(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->expectException(GuardianHasNoEmailContactException::class);

        try {
            $this->service()->invite($school, $guardian, $admin);
        } finally {
            Mail::assertNothingSent();
        }
    }

    #[Test]
    public function inviting_an_already_linked_guardian_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $membership = $this->createMembership($this->createUser(), $school);
        app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $admin);

        $this->expectException(GuardianAlreadyHasAccountLinkException::class);
        $this->service()->invite($school, $guardian, $admin);
    }

    #[Test]
    public function inviting_the_same_guardian_twice_while_pending_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->service()->invite($school, $guardian, $admin);

        $this->expectException(GuardianAlreadyHasPendingInvitationException::class);
        $this->service()->invite($school, $guardian, $admin);
    }

    #[Test]
    public function resending_revokes_the_prior_invitation_and_issues_a_fresh_one(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $first = $this->service()->invite($school, $guardian, $admin);

        $second = $this->service()->resend($school, $guardian, $admin);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('revoked', app(TenantContext::class)->withSchool($school, fn () => $first->fresh())->status);
        $this->assertSame('pending', $second->status);
        $this->assertSame(
            1,
            app(TenantContext::class)->withSchool($school, fn () => GuardianAccountInvitation::query()->where('guardian_id', $guardian->id)->pending()->count()),
        );
    }

    #[Test]
    public function revoking_a_pending_invitation_marks_it_revoked(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $invitation = $this->service()->invite($school, $guardian, $admin);

        $this->service()->revoke($school, $guardian, $admin);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $invitation->fresh());
        $this->assertSame('revoked', $fresh->status);
        $this->assertNotNull($fresh->revoked_at);
    }

    #[Test]
    public function after_revocation_a_new_invitation_can_be_issued(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->service()->invite($school, $guardian, $admin);
        $this->service()->revoke($school, $guardian, $admin);

        $newInvitation = $this->service()->invite($school, $guardian, $admin);

        $this->assertSame('pending', $newInvitation->status);
    }

    #[Test]
    public function invitation_events_are_audited_without_the_plaintext_token_or_email(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');

        $this->service()->invite($school, $guardian, $admin);

        $events = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'guardian.account_invited')
            ->get());

        $this->assertCount(1, $events);
        $payload = json_encode($events->first()->metadata);
        $this->assertStringNotContainsString('guardian@example.com', $payload);
        $this->assertStringNotContainsString('token', strtolower($payload));
        $this->assertStringNotContainsString('password', $payload);
    }
}
