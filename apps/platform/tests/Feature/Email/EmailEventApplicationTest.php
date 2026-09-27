<?php

namespace Tests\Feature\Email;

use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\School;
use App\Support\Email\EmailKind;
use App\Support\Email\EmailState;
use App\Support\Email\Events\FakeEmailEventAdapter;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 sections 11.3 and 12): applying normalized events
 * -- the explicit graph (never backward), dedupe, tenancy from stored data,
 * suppression scopes and the Communications projection. Events enter
 * through the real webhook route; the sync queue applies them.
 */
class EmailEventApplicationTest extends TestCase
{
    use CreatesEmailFixtures;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    private function send(string $providerMessageId, string $type, ?string $bounceClass = null, ?string $id = null): TestResponse
    {
        $body = (string) json_encode(['events' => [array_filter([
            'id' => $id ?? 'evt-'.(++$this->sequence),
            'type' => $type,
            'message_id' => $providerMessageId,
            'occurred_at' => now()->toIso8601String(),
            'bounce_class' => $bounceClass,
        ])]]);

        return $this->call('POST', 'http://localhost/api/integrations/email-provider/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LYCENZA_FAKE_EMAIL_SIGNATURE' => FakeEmailEventAdapter::sign($body, (string) config('email.events.secrets')[0]),
        ], $body)->assertStatus(202);
    }

    /** @return array{0: School, 1: EmailMessage, 2: CommunicationDelivery} */
    private function submittedStandard(string $to = 'family@example.com'): array
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin, $to);

        return [$school, $this->emailRow($school, $email->id), $delivery];
    }

    private function deliveryStatus(School $school, CommunicationDelivery $delivery): string
    {
        return $this->inSchool($school, fn () => CommunicationDelivery::query()->find($delivery->id)->status);
    }

    #[Test]
    public function delivered_then_a_complaint_is_representable_and_projected(): void
    {
        [$school, $email, $delivery] = $this->submittedStandard();
        $this->assertSame(EmailState::Submitted, $email->status);
        $this->assertSame('sent', $this->deliveryStatus($school, $delivery));

        $this->send($email->provider_message_id, 'delivered');
        $this->assertSame(EmailState::Delivered, $this->emailRow($school, $email->id)->status);
        $this->assertSame('delivered', $this->deliveryStatus($school, $delivery));

        $this->send($email->provider_message_id, 'complaint');
        $row = $this->emailRow($school, $email->id);
        $this->assertSame(EmailState::Complained, $row->status);
        $this->assertSame('bounced', $this->deliveryStatus($school, $delivery));

        $suppression = EmailSuppression::query()->sole();
        $this->assertSame(['standard', 'complaint'], [$suppression->scope, $suppression->reason], 'a complaint about standard mail suppresses standard mail');
        $this->assertStringNotContainsString('family', $suppression->address_fingerprint);
    }

    #[Test]
    public function late_and_out_of_order_events_never_move_a_message_backward(): void
    {
        [$school, $email, $delivery] = $this->submittedStandard();
        $this->send($email->provider_message_id, 'delivered');

        $this->send($email->provider_message_id, 'deferred');
        $this->send($email->provider_message_id, 'soft_bounce');

        $this->assertSame(EmailState::Delivered, $this->emailRow($school, $email->id)->status);
        $this->assertSame('delivered', $this->deliveryStatus($school, $delivery));
        $this->assertSame(['applied', 'stale', 'stale'], EmailEvent::query()->orderBy('received_at')->orderBy('id')->pluck('result')->all());
        $this->assertSame(0, EmailSuppression::query()->count(), 'a soft bounce never suppresses');
    }

    #[Test]
    public function a_hard_bounce_suppresses_the_address_for_all_mail_once_even_when_repeated(): void
    {
        [$school, $email, $delivery] = $this->submittedStandard('gone@example.com');

        $this->send($email->provider_message_id, 'hard_bounce', 'mailbox_unknown', 'evt-hb');
        $this->send($email->provider_message_id, 'hard_bounce', 'mailbox_unknown', 'evt-hb');  // replay
        $this->send($email->provider_message_id, 'hard_bounce', 'mailbox_unknown');           // a second report

        $row = $this->emailRow($school, $email->id);
        $this->assertSame([EmailState::Bounced, 'bounce_mailbox_unknown'], [$row->status, $row->status_code]);
        $this->assertSame(['bounced', 'email_bounced'], $this->inSchool($school, fn () => [($d = CommunicationDelivery::query()->find($delivery->id))->status, $d->failure_code]));
        $this->assertSame(1, EmailSuppression::query()->count());
        $this->assertSame('all', EmailSuppression::query()->sole()->scope);
        $this->assertSame(2, EmailEvent::query()->count(), 'the replay was deduplicated');
        $this->assertNotNull(app(EmailSuppressionService::class)->blocking('gone@example.com', EmailKind::Critical));
    }

    #[Test]
    public function a_complaint_about_critical_mail_suppresses_all_mail(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueInvitationEmail($school, $admin, 'angry@example.com');
        $email = $this->emailRow($school, $email->id);

        $this->send($email->provider_message_id, 'complaint');

        $this->assertSame(EmailState::Complained, $this->emailRow($school, $email->id)->status);
        $this->assertSame('all', EmailSuppression::query()->sole()->scope);
    }

    #[Test]
    public function a_provider_drop_fails_the_message_as_provider_rejected_and_provider_suppression_is_recorded(): void
    {
        [$school, $email, $delivery] = $this->submittedStandard('dropped@example.com');

        $this->send($email->provider_message_id, 'dropped', 'provider_suppressed');

        $this->assertSame([EmailState::Failed, 'provider_rejected'], [($row = $this->emailRow($school, $email->id))->status, $row->status_code]);
        $this->assertSame('rejected', $this->deliveryStatus($school, $delivery));
        $this->assertSame(['all', 'provider_suppressed'], [($s = EmailSuppression::query()->sole())->scope, $s->reason]);
    }

    #[Test]
    public function an_event_for_an_unknown_message_creates_nothing(): void
    {
        $this->send('fake-never-submitted-here', 'delivered');

        $this->assertSame('unknown_message', EmailEvent::query()->sole()->result);
        $this->assertSame(0, EmailSuppression::query()->count());
    }

    #[Test]
    public function the_school_always_comes_from_the_stored_message(): void
    {
        [$schoolA, $emailA] = $this->submittedStandard('a@example.com');
        [$schoolB] = $this->submittedStandard('b@example.com');

        $this->send($emailA->provider_message_id, 'delivered');

        $this->assertSame(EmailState::Delivered, $this->emailRow($schoolA, $emailA->id)->status);
        $this->assertSame(0, $this->inSchool($schoolB, fn () => EmailMessage::query()->where('status', 'delivered')->count()));
        $this->assertNull($this->inSchool($schoolB, fn () => EmailMessage::query()->find($emailA->id)), 'RLS: School B cannot read School A\'s message');
    }

    #[Test]
    public function a_delivered_event_never_changes_the_invitation(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $invitation] = $this->queueInvitationEmail($school, $admin);

        $this->send($this->emailRow($school, $email->id)->provider_message_id, 'delivered');

        $this->assertSame('pending', $this->inSchool($school, fn () => $invitation->fresh()->status));
    }
}
