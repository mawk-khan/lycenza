<?php

namespace Tests\Feature\Email;

use App\Jobs\ApplyEmailEventJob;
use App\Jobs\SubmitEmailMessageJob;
use App\Models\Notification;
use App\Support\Email\EmailPurpose;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055): structural guards that fail on the SHAPE of a
 * change that would quietly bring back direct or unsafe email.
 */
class EmailArchitectureTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return iterable<string, string> relative path => source */
    private function appSources(): iterable
    {
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            yield Str::after($file->getRealPath(), base_path().'/') => $file->getContents();
        }
    }

    #[Test]
    public function no_application_code_sends_mail_through_laravels_mailer(): void
    {
        foreach ($this->appSources() as $path => $source) {
            $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\Mail', $source, "{$path}: business email goes through OutboundEmailGateway");
            $this->assertDoesNotMatchRegularExpression('/\bMail::(to|send|mailer|raw|queue|later)\b/', $source, "{$path}: direct Laravel mail");
            $this->assertStringNotContainsString('extends Mailable', $source, "{$path}: no Mailable -- content is sealed by the producer");
            $this->assertStringNotContainsString('->toMail(', $source, "{$path}: no mail notifications");
        }
    }

    #[Test]
    public function only_the_smtp_adapter_touches_a_mail_transport(): void
    {
        foreach ($this->appSources() as $path => $source) {
            if ($path === 'app/Support/Email/Providers/SmtpEmailProvider.php') {
                continue;
            }
            $this->assertStringNotContainsString('Symfony\\Component\\Mailer', $source, "{$path} must not use a mail transport directly");
        }

        $smtp = (string) file_get_contents(app_path('Support/Email/Providers/SmtpEmailProvider.php'));
        $this->assertStringContainsString('setRequireTls($tls !== \'none\')', $smtp);
        $this->assertStringNotContainsString('Failover', $smtp);
        $this->assertStringNotContainsString('LogTransport', $smtp);
    }

    #[Test]
    public function nothing_ever_sets_a_reply_to_cc_or_bcc(): void
    {
        // The email layer and its two producers. (Communications' own
        // conversation "reply" concept is product data, not a header.)
        $paths = [
            ...array_map(fn ($f) => $f->getRealPath(), iterator_to_array((new Finder)->files()->in(app_path('Support/Email'))->name('*.php'), false)),
            app_path('Domain/Communications/Application/Channels/EmailChannelDriver.php'),
            app_path('Domain/Communications/Application/Channels/CommunicationDeliveryEmailSource.php'),
            app_path('Domain/Identity/Application/AccountInvitationService.php'),
            resource_path('views/emails/identity/guardian-account-invitation.blade.php'),
            resource_path('views/emails/identity/guardian-account-invitation-html.blade.php'),
            resource_path('views/emails/communications/message.blade.php'),
            resource_path('views/emails/communications/message-html.blade.php'),
        ];

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            foreach (['replyTo(', "'Reply-To'", '"Reply-To"', '->cc(', '->bcc(', 'addReplyTo', 'reply_to'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$path}: v1 has no Reply-To/Cc/Bcc (ADR 0055 section 8.3)");
            }
        }

        // OutboundEmail carries no such field at all.
        $properties = array_map(fn ($p) => $p->getName(), (new \ReflectionClass(OutboundEmail::class))->getProperties());
        $this->assertSame([], array_values(array_filter($properties, fn (string $p) => preg_match('/reply|cc|bcc/i', $p) === 1)));
    }

    #[Test]
    public function the_producers_queue_through_the_gateway_with_their_own_purpose(): void
    {
        $invitations = (string) file_get_contents(app_path('Domain/Identity/Application/AccountInvitationService.php'));
        $this->assertStringContainsString('EmailPurpose::AccountInvitation', $invitations);
        $this->assertStringContainsString('$this->email->queue(', $invitations);

        $driver = (string) file_get_contents(app_path('Domain/Communications/Application/Channels/EmailChannelDriver.php'));
        $this->assertStringContainsString('EmailPurpose::SchoolCommunication', $driver);
        $this->assertStringContainsString('CommunicationDeliveryResult::accepted(', $driver);
        $this->assertStringNotContainsString('CommunicationDeliveryResult::sent(', $driver);

        // Phase 0O.10A (ADR 0056): the identity-level purposes have exactly
        // one producer each, through queueForIdentity() -- nothing else
        // names them.
        $producers = [
            'EmailPurpose::AccountRecovery' => 'app/Domain/Identity/Application/AccountRecovery/AccountRecoveryIssuer.php',
            'EmailPurpose::SecurityNotice' => 'app/Domain/Identity/Application/AccountRecovery/SecurityNoticeService.php',
        ];
        foreach ($producers as $purpose => $producer) {
            $this->assertStringContainsString('->queueForIdentity(', (string) file_get_contents(base_path($producer)), $producer);
        }
        foreach ($this->appSources() as $path => $source) {
            foreach ($producers as $purpose => $producer) {
                if (! in_array($path, ['app/Support/Email/EmailPurpose.php', $producer], true)) {
                    $this->assertStringNotContainsString($purpose, $source, $path);
                }
            }
        }
    }

    #[Test]
    public function the_email_jobs_own_their_retries_and_stay_under_the_queue_timeout(): void
    {
        foreach ([SubmitEmailMessageJob::class, ApplyEmailEventJob::class] as $job) {
            $instance = (new \ReflectionClass($job))->newInstanceWithoutConstructor();
            $this->assertSame(1, $instance->tries, "{$job}: the message state owns retries (rule 59)");
            $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $instance->timeout, "{$job}: rule 58");
            $this->assertLessThan((int) config('email.submission.lease_seconds'), $instance->timeout, "{$job}: the claim lease outlives the job");
        }
    }

    #[Test]
    public function the_log_only_notification_email_provider_does_not_exist_outside_local_and_testing(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');

        $this->app['env'] = 'production';
        $this->app->forgetInstance(NotificationDispatcher::class);
        try {
            app(NotificationDispatcher::class)->send($school, null, 'email', 'anything');
            $this->fail('an email "notification" must have no provider outside local/testing');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'email'", $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
            $this->app->forgetInstance(NotificationDispatcher::class);
        }

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => Notification::query()->where('channel', 'email')->count()));
    }

    #[Test]
    public function o14_can_only_rely_on_email_when_the_critical_substrate_is_available(): void
    {
        $this->assertTrue(EmailPurpose::AccountRecovery->isImplemented(), 'O14 is implemented by 0O.10A');
        $this->assertSame('critical', EmailPurpose::AccountRecovery->kind()->value);

        $resolver = app(EmailProviderResolver::class);
        config(['email.provider' => 'none']);
        $this->assertFalse($resolver->criticalEmailAvailable());
        config(['email.provider' => 'fake']);
        $this->assertTrue($resolver->criticalEmailAvailable());
    }
}
