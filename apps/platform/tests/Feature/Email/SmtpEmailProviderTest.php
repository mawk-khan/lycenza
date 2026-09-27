<?php

namespace Tests\Feature\Email;

use App\Support\Email\EmailPurpose;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Email\Providers\SmtpEmailProvider;
use App\Support\Email\Providers\SubmissionOutcome;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 6): the hardened SMTP baseline against a
 * real local SMTP peer (tests/Support/smtp-test-server.php, loopback only):
 * required TLS never downgrades, failures are classified (never thrown),
 * timeouts are bounded, and only the closed header set goes on the wire.
 */
class SmtpEmailProviderTest extends TestCase
{
    private ?Process $server = null;

    private string $dir;

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function serve(string $mode): int
    {
        $this->dir = sys_get_temp_dir().'/smtp-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->server = new Process([PHP_BINARY, base_path('tests/Support/smtp-test-server.php'), $this->dir.'/port', $this->dir.'/log', $mode]);
        $this->server->start();

        $deadline = microtime(true) + 10;
        while (! is_file($this->dir.'/port') || trim((string) file_get_contents($this->dir.'/port')) === '') {
            $this->assertLessThan($deadline, microtime(true), 'the SMTP test peer did not start');
            usleep(20_000);
        }

        return (int) file_get_contents($this->dir.'/port');
    }

    /** @return list<string> */
    private function verbs(): array
    {
        return array_values(array_filter(array_map(fn ($l) => strtok($l, ' '), file($this->dir.'/log', FILE_IGNORE_NEW_LINES) ?: [])));
    }

    private function email(EmailPurpose $purpose = EmailPurpose::AccountInvitation): OutboundEmail
    {
        return new OutboundEmail(
            messageId: '0192a5b0-0000-7000-8000-000000000001',
            rfcMessageId: '<0192a5b0-0000-7000-8000-000000000001@notify.lycenza-suite.test>',
            idempotencyKey: 'lycenza-email-0192a5b0-0000-7000-8000-000000000001',
            purpose: $purpose,
            fromAddress: 'notifications@notify.lycenza-suite.test',
            fromName: 'Northfield via Lycenza',
            to: 'guardian@example.com',
            subject: 'You are invited',
            text: 'Plain text body',
            html: '<p>HTML body</p>',
        );
    }

    private function provider(int $port, string $tls = 'none', array $extra = []): SmtpEmailProvider
    {
        config(['email.smtp' => ['host' => '127.0.0.1', 'port' => $port, 'username' => $extra['username'] ?? null, 'password' => $extra['password'] ?? null, 'tls' => $tls, 'timeout_seconds' => $extra['timeout'] ?? 5, 'ehlo_domain' => 'lycenza.test']]);

        return app(SmtpEmailProvider::class);
    }

    #[Test]
    public function an_accepted_message_carries_only_the_closed_header_set(): void
    {
        $result = $this->provider($this->serve('accept'))->submit($this->email());

        $this->assertSame(SubmissionOutcome::Accepted, $result->outcome);
        $this->assertSame('TESTQ42', $result->providerMessageId, 'the server\'s queue id');

        $line = collect(file($this->dir.'/log', FILE_IGNORE_NEW_LINES) ?: [])->first(fn (string $l) => str_starts_with($l, 'DATA-BODY '));
        $body = (string) base64_decode(substr((string) $line, 10));
        $this->assertStringContainsString('Message-ID: <0192a5b0-0000-7000-8000-000000000001@notify.lycenza-suite.test>', $body);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $body);
        $this->assertStringContainsString('From: Northfield via Lycenza <notifications@notify.lycenza-suite.test>', $body);
        $this->assertStringContainsString('Content-Type: multipart/alternative', $body);
        foreach (['Reply-To:', 'Cc:', 'Bcc:'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
    }

    #[Test]
    public function required_tls_never_falls_back_to_plaintext(): void
    {
        $result = $this->provider($this->serve('accept'), 'required')->submit($this->email());

        $this->assertSame([SubmissionOutcome::AuthFailure, 'tls_unavailable'], [$result->outcome, $result->code]);
        $this->assertNotContains('MAIL', $this->verbs(), 'nothing was sent over the plaintext connection');
        $this->assertNotContains('DATA', $this->verbs());
    }

    #[Test]
    public function plaintext_is_refused_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        try {
            $result = $this->provider(1, 'none')->submit($this->email());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([SubmissionOutcome::AuthFailure, 'tls_unavailable'], [$result->outcome, $result->code]);
    }

    #[Test]
    public function failures_are_classified_and_never_echo_the_server_or_credential(): void
    {
        $recipient = $this->provider($this->serve('reject-recipient'))->submit($this->email());
        $this->assertSame([SubmissionOutcome::PermanentFailure, 'recipient_rejected'], [$recipient->outcome, $recipient->code]);
        $this->server->stop(0);

        $auth = $this->provider($this->serve('auth-fail'), 'none', ['username' => 'lycenza', 'password' => 'smtp-canary-password-1234'])->submit($this->email());
        $this->assertSame([SubmissionOutcome::AuthFailure, 'authentication_failed'], [$auth->outcome, $auth->code]);
        $this->assertStringNotContainsString('canary', (string) json_encode($auth));
        $this->server->stop(0);

        $greylist = $this->provider($this->serve('greylist'))->submit($this->email());
        $this->assertSame([SubmissionOutcome::TransientFailure, 'provider_unavailable'], [$greylist->outcome, $greylist->code]);
    }

    #[Test]
    public function an_unresponsive_server_times_out_within_the_bound(): void
    {
        $port = $this->serve('hang');
        $start = microtime(true);

        $result = $this->provider($port, 'none', ['timeout' => 1])->submit($this->email());

        $this->assertSame(SubmissionOutcome::TransientFailure, $result->outcome);
        $this->assertLessThan(SmtpEmailProvider::MAX_TIMEOUT_SECONDS + 5, microtime(true) - $start);
    }

    #[Test]
    public function a_refused_connection_is_a_transient_network_error(): void
    {
        $result = $this->provider(1)->submit($this->email());

        $this->assertSame(SubmissionOutcome::TransientFailure, $result->outcome);
    }
}
