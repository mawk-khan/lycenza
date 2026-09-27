<?php

namespace Tests\Feature\Email;

use App\Jobs\SubmitEmailMessageJob;
use App\Models\EmailMessage;
use App\Support\Email\EmailKind;
use App\Support\Email\EmailMessageIdentity;
use App\Support\Email\EmailPurpose;
use App\Support\Email\EmailState;
use App\Support\Email\OutboundEmailGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 sections 3, 8, 9, 13): what queueing an email
 * records -- and what it never does (contact a provider, claim "sent").
 */
class OutboundEmailGatewayTest extends TestCase
{
    use CreatesEmailFixtures;

    #[Test]
    public function queueing_writes_one_sealed_pending_message_and_dispatches_after_commit_only(): void
    {
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        [$email] = $this->queueStandardEmail($school, $admin, 'Parent.One@School-OS.test', 'Sports day', 'See you there.');

        $this->assertSame(EmailState::Pending, $email->status);
        $this->assertSame(EmailPurpose::SchoolCommunication, $email->purpose);
        $this->assertSame(EmailKind::Standard, $email->kind);
        $this->assertSame('parent.one@school-os.test', $email->recipient(), 'the application\'s normalization (trim + lowercase)');
        $this->assertSame(0, $this->emailRow($school, $email->id)->attempts);
        Queue::assertPushedOn('notifications', SubmitEmailMessageJob::class);
        $this->assertNoEmailAccepted();

        // Encrypted at rest: neither the address nor the body is in the row.
        $raw = (array) $this->inSchool($school, fn () => DB::table('email_messages')->where('id', $email->id)->first());
        $this->assertStringNotContainsString('parent.one', (string) $raw['recipient_encrypted']);
        $this->assertStringNotContainsString('See you there', (string) $raw['sealed_content']);
    }

    #[Test]
    public function the_three_identifiers_are_derived_explicitly_and_kept_apart(): void
    {
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin);

        $this->assertSame("<{$email->id}@notify.lycenza-suite.test>", $email->rfc_message_id);
        $this->assertSame("lycenza-email-{$email->id}", EmailMessageIdentity::idempotencyKey($email->id));
        $this->assertNull($email->provider_message_id, 'the provider id exists only once a provider accepted the message');

        $submitted = $this->submitEmail($school, $email->id);
        $this->assertNotNull($submitted->provider_message_id);
        $this->assertNotSame($submitted->rfc_message_id, $submitted->provider_message_id);
        $this->assertSame($email->rfc_message_id, $this->lastAcceptedEmail()->rfcMessageId);
        $this->assertSame(EmailMessageIdentity::idempotencyKey($email->id), $this->lastAcceptedEmail()->idempotencyKey);
    }

    #[Test]
    public function reserved_purposes_cannot_be_sent_and_critical_mail_needs_its_links_lifetime(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $gateway = app(OutboundEmailGateway::class);

        foreach ([EmailPurpose::AccountRecovery, EmailPurpose::SecurityNotice] as $reserved) {
            $this->assertFalse($reserved->isImplemented());
            try {
                $this->inSchool($school, fn () => $gateway->queue($school, $reserved, (string) Str::uuid(), 'a@b.test', 'S', 'T', expiresAt: now()->addHour()));
                $this->fail("{$reserved->value} must be refused");
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->inSchool($school, fn () => $gateway->queue($school, EmailPurpose::AccountInvitation, (string) Str::uuid(), 'a@b.test', 'S', 'T'));
    }

    #[Test]
    public function the_database_refuses_a_reserved_purpose_and_a_mismatched_kind_or_source(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $row = fn (array $overrides) => array_merge([
            'id' => (string) Str::uuid(), 'school_id' => $school->id, 'purpose' => 'school_communication', 'kind' => 'standard',
            'source_type' => 'communication_delivery', 'source_id' => (string) Str::uuid(), 'recipient_encrypted' => 'x',
            'from_mailbox' => 'notifications', 'from_display_name' => 'Lycenza', 'subject' => 'S', 'sealed_content' => 'x',
            'status' => 'pending', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);

        foreach ([['purpose' => 'account_recovery', 'kind' => 'critical'], ['kind' => 'critical'], ['source_type' => 'guardian_account_invitation'], ['from_mailbox' => 'ceo']] as $bad) {
            try {
                $this->inSchool($school, fn () => DB::transaction(fn () => DB::table('email_messages')->insert($row($bad))));
                $this->fail('refused: '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('email_messages_', $e->getMessage());
            }
        }
    }

    #[Test]
    public function queueing_is_idempotent_per_source(): void
    {
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email, $delivery] = $this->queueStandardEmail($school, $admin);

        $again = $this->inSchool($school, fn () => app(OutboundEmailGateway::class)->queue($school, EmailPurpose::SchoolCommunication, $delivery->id, 'someone@school-os.test', 'Other', 'Other'));

        $this->assertSame($email->id, $again->id);
        $this->assertSame(1, $this->inSchool($school, fn () => EmailMessage::query()->where('source_id', $delivery->id)->count()));
    }

    #[Test]
    public function a_rolled_back_business_transaction_leaves_no_email_and_dispatches_nothing(): void
    {
        // The real (sync) queue: an after-commit dispatch of a rolled-back
        // transaction never runs.
        $this->fakeEmail();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $before = $this->inSchool($school, fn () => EmailMessage::query()->count());

        try {
            DB::transaction(function () use ($school, $admin): void {
                $this->queueStandardEmail($school, $admin);
                throw new RuntimeException('business step failed after queueing');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->inSchool($school, fn () => EmailMessage::query()->count()));
        $this->assertSame([], $this->emailFake()->calls(), 'nothing reached the provider');
    }

    #[Test]
    public function the_job_payload_carries_ids_only(): void
    {
        $this->holdEmailSubmission();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->queueStandardEmail($school, $admin, 'secret.person@school-os.test', 'Private subject', 'Private body');

        Queue::assertPushed(SubmitEmailMessageJob::class, function (SubmitEmailMessageJob $job): bool {
            $payload = serialize($job);
            $this->assertStringNotContainsString('secret.person', $payload);
            $this->assertStringNotContainsString('Private', $payload);

            return true;
        });
    }

    #[Test]
    public function from_is_the_catalog_mailbox_on_the_sending_domain_and_the_display_name_is_sanitized(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->inSchool($school, fn () => $school->forceFill(['name' => "Evil\r\nBcc: victim@x.test <ceo@bank.test> \"School\"; ".str_repeat('x', 80)])->save());
        $this->fakeEmail();

        $this->queueStandardEmail($school->refresh(), $admin, 'a@school-os.test', "Subject\r\nX-Injected: yes".str_repeat('y', 300));
        $sent = $this->lastAcceptedEmail();

        $this->assertSame('notifications@notify.lycenza-suite.test', $sent->fromAddress);
        $this->assertLessThanOrEqual(64, mb_strlen($sent->fromName));
        $this->assertStringEndsWith(' via Lycenza', $sent->fromName);
        foreach (["\r", "\n", '<', '>', '@', '"', ';', ','] as $bad) {
            $this->assertStringNotContainsString($bad, $sent->fromName);
        }
        $this->assertStringNotContainsString("\n", $sent->subject);
        $this->assertLessThanOrEqual(200, mb_strlen($sent->subject));
        $this->assertSame([], $sent->additionalHeaders(), 'standard mail adds no header; there is no Reply-To at all');
        $this->assertStringContainsString("\r\n", $this->inSchool($school, fn () => $school->fresh()->name), 'the School\'s stored name is not modified');
    }
}
