<?php

namespace Tests\Feature\Email;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\Exceptions\InvitationSendRateLimitedException;
use App\Domain\Identity\Application\GuardianInvitationSendLimiter;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\EmailMessage;
use App\Models\SchoolAuditEvent;
use App\Support\Email\EmailKind;
use App\Support\Email\EmailPurpose;
use App\Support\Email\EmailState;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 9.5; closes the 0O.9 findings "invitation
 * sent inside the business transaction" and "send/resend unthrottled"):
 * the invitation email is an outbox row, the provider is contacted only
 * after commit, a provider failure never undoes the invitation, and
 * sending is rate limited.
 */
class InvitationEmailOutboxTest extends TestCase
{
    use CreatesEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    private function service(): AccountInvitationService
    {
        return app(AccountInvitationService::class);
    }

    #[Test]
    public function the_invitation_its_audit_record_and_its_critical_email_commit_together(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $invitation] = $this->queueInvitationEmail($school, $admin, 'Guardian@Example.com');

        $row = $this->emailRow($school, $email->id);
        $this->assertSame(EmailPurpose::AccountInvitation, $row->purpose);
        $this->assertSame(EmailKind::Critical, $row->kind);
        $this->assertSame($invitation->id, $row->source_id);
        $this->assertTrue($row->expires_at->equalTo($invitation->expires_at), 'content lives no longer than the link');
        $this->assertSame(EmailState::Submitted, $row->status, 'submitted after commit by the (sync) queue');
        $this->assertSame(1, $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'guardian.account_invited')->count()));

        $sent = $this->lastAcceptedEmail();
        $this->assertSame('guardian@example.com', $sent->to);
        $this->assertSame(['Auto-Submitted' => 'auto-generated'], $sent->additionalHeaders());
        $this->assertStringContainsString("/invitations/{$school->id}/", $sent->text);
        $this->assertNotNull($sent->html);
        $this->assertStringNotContainsString('<img', (string) $sent->html);
        $this->assertStringNotContainsString('<script', (string) $sent->html);
    }

    #[Test]
    public function a_provider_outage_never_fails_or_rolls_back_the_invitation(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->emailFake()->queue(SubmissionResult::transient('provider_unavailable'));
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->activate($admin, $school);

        $this->actingAs($admin)->post("/app/guardians/{$guardian->id}/account-invitation")->assertRedirect("/app/guardians/{$guardian->id}")->assertSessionHasNoErrors();

        $invitation = $this->inSchool($school, fn () => GuardianAccountInvitation::query()->where('guardian_id', $guardian->id)->sole());
        $this->assertSame('pending', $invitation->status);

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountInvitation.pending.email.state', 'pending')
            ->where('accountInvitation.pending.email.waitingReason', null));
    }

    #[Test]
    public function with_email_disabled_the_invitation_exists_and_the_page_says_the_email_is_waiting(): void
    {
        config(['email.provider' => 'none']);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'guardian@example.com');
        $this->activate($admin, $school);

        $this->actingAs($admin)->post("/app/guardians/{$guardian->id}/account-invitation")->assertSessionHasNoErrors();

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->where('accountInvitation.pending.status', 'pending')
            ->where('accountInvitation.pending.email.state', 'pending')
            ->where('accountInvitation.pending.email.waitingReason', 'email_disabled'));
        $this->assertSame([], $this->emailFake()->calls());
    }

    #[Test]
    public function resend_cancels_the_unsent_email_and_keeps_provider_accepted_evidence(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        // First invitation: accepted by the provider -- its evidence stays.
        [$accepted, $first] = $this->queueInvitationEmail($school, $admin);
        $guardian = $this->inSchool($school, fn () => $first->guardian);
        $second = $this->service()->resend($school, $guardian, $admin);

        // Second invitation's email waits (email disabled), then a resend cancels it.
        config(['email.provider' => 'none']);
        $third = $this->service()->resend($school, $guardian, $admin);
        config(['email.provider' => 'fake']);
        $this->service()->resend($school, $guardian, $admin);

        $states = $this->inSchool($school, fn () => EmailMessage::query()->orderBy('created_at')->orderBy('id')->get()->mapWithKeys(fn ($m) => [$m->source_id => [$m->status->value, $m->status_code]])->all());

        $this->assertSame(['submitted', null], $states[$first->id], 'a provider-accepted email is never "unsent"');
        $this->assertSame(['submitted', null], $states[$second->id]);
        $this->assertSame(['cancelled', 'source_reissued'], $states[$third->id]);
        $this->assertCount(4, $states);
        $this->assertNull($this->inSchool($school, fn () => EmailMessage::query()->where('source_id', $third->id)->value('sealed_content')));
    }

    #[Test]
    public function revoking_cancels_the_unsent_email(): void
    {
        config(['email.provider' => 'none']);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $invitation] = $this->queueInvitationEmail($school, $admin);

        $this->service()->revoke($school, $this->inSchool($school, fn () => $invitation->guardian), $admin);

        $this->assertSame([EmailState::Cancelled, 'source_revoked'], [($row = $this->emailRow($school, $email->id))->status, $row->status_code]);
        $this->assertNull($row->sealed_content);
    }

    #[Test]
    public function sending_is_limited_to_ten_per_minute_per_admin(): void
    {
        $this->freezeSecond();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        foreach (range(1, 10) as $i) {
            $guardian = $this->createGuardian($school);
            $this->createGuardianContact($guardian, ContactType::Email, "g{$i}@example.com");
            $this->service()->invite($school, $guardian, $admin);
        }

        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'g11@example.com');
        try {
            $this->service()->invite($school, $guardian, $admin);
            $this->fail('the eleventh invitation in a minute is refused');
        } catch (InvitationSendRateLimitedException $e) {
            $this->assertStringNotContainsString('g11', $e->getMessage(), 'never names the recipient');
        }

        // Another admin of the same School is not throttled by this one.
        $other = $this->createUserWithCapabilities($school, ['guardians.manage', 'school.members.manage']);
        $this->service()->invite($school, $guardian, $other);

        $this->travel(61)->seconds();
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'g12@example.com');
        $this->service()->invite($school, $guardian, $admin);
        $this->assertEmailAcceptedCount(12);
    }

    #[Test]
    public function sending_is_limited_to_two_hundred_per_day_per_school_and_the_http_refusal_is_generic(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [, $otherSchool] = $this->createSchoolAdmin('school_admin');
        $key = "guardian-invitation-send:school:{$school->id}:d";
        foreach (range(1, GuardianInvitationSendLimiter::PER_SCHOOL_DAY) as $ignored) {
            RateLimiter::hit($key, 86400);
        }

        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'late@example.com');
        $this->activate($admin, $school);

        $this->actingAs($admin)->post("/app/guardians/{$guardian->id}/account-invitation")
            ->assertSessionHasErrors(['guardian' => 'Too many invitation emails were requested. Please wait and try again later.']);
        $this->assertSame(0, $this->inSchool($school, fn () => GuardianAccountInvitation::query()->where('guardian_id', $guardian->id)->count()));

        // A different School has its own budget.
        $this->assertFalse(RateLimiter::tooManyAttempts("guardian-invitation-send:school:{$otherSchool->id}:d", GuardianInvitationSendLimiter::PER_SCHOOL_DAY));
    }

    #[Test]
    public function invitation_links_come_from_the_canonical_origin_even_when_submitted_by_a_worker_with_no_request(): void
    {
        config(['app.url' => 'https://app.lycenza-platform.com']);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        config(['email.provider' => 'none']);
        [$email] = $this->queueInvitationEmail($school, $admin);

        // Submitted later, from the sweeper -- no request, no Host.
        config(['email.provider' => 'fake']);
        $this->makeDue($school, $email->id);
        app(TenantContext::class)->clearAll();
        $this->artisan('platform:email-messages-redispatch')->assertExitCode(0);

        $this->assertStringContainsString("https://app.lycenza-platform.com/invitations/{$school->id}/", $this->lastAcceptedEmail()->text);
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }
}
