<?php

namespace App\Support\Email\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * ADR 0055 section 6: the provider-neutral, hardened SMTP baseline.
 *
 * - TLS REQUIRED: STARTTLS that can never silently downgrade
 *   (`requireTls`), or implicit TLS; peer and name verification on.
 *   Plaintext (`MAIL_SMTP_TLS=none`) exists only for Mailpit in
 *   local/testing and is refused here too outside those environments.
 * - Authenticated whenever a username is configured (production requires
 *   credentials: ProductionConfigurationGuard).
 * - Bounded: Symfony's SMTP socket applies ONE timeout to the connect and
 *   to each response; it is capped at 5 s (the connect limit), which is
 *   stricter than the 15 s per-exchange limit. The job's own 30 s timeout
 *   bounds the whole attempt.
 * - No failover to anything, no log sink.
 * - Classified, never thrown: SMTP 530/534/535/538/504 and TLS refusals are
 *   AUTH failures (operator problems), 5xx are permanent, 4xx and network
 *   errors transient. Only a closed code is returned -- never the server's
 *   text, the username or the credential.
 *
 * SMTP has no delivery events: messages sent this way stay `submitted`
 * until a vendor adapter with an event feed is configured (ADR 0055 §11).
 */
final class SmtpEmailProvider implements EmailProviderAdapter
{
    public const MAX_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly Repository $config,
        private readonly FilesystemFactory $storage,
    ) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function supportsIdempotencyKey(): bool
    {
        return false;
    }

    public function submit(OutboundEmail $email): SubmissionResult
    {
        $tls = (string) $this->config->get('email.smtp.tls');

        if ($tls === 'none' && ! app()->environment(['local', 'testing'])) {
            return SubmissionResult::authFailure('tls_unavailable');
        }

        $message = (new Email)
            ->from(new Address($email->fromAddress, $email->fromName))
            ->to(new Address($email->to))
            ->subject($email->subject)
            ->text($email->text);

        if ($email->html !== null) {
            $message->html($email->html);
        }

        $headers = $message->getHeaders();
        $headers->addIdHeader('Message-ID', trim($email->rfcMessageId, '<>'));
        foreach ($email->additionalHeaders() as $name => $value) {
            $headers->addTextHeader($name, $value);
        }

        foreach ($email->attachments as $attachment) {
            $stream = $this->storage->disk($attachment['disk'])->readStream($attachment['path']);
            if (! is_resource($stream)) {
                return SubmissionResult::permanent('content_rejected');
            }
            $message->attach($stream, $attachment['name'], $attachment['mime']);
        }

        $transport = $this->transport($tls);

        try {
            $sent = $transport->send($message);
        } catch (TransportExceptionInterface $e) {
            return self::classify($e);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // Closing a broken connection must not mask the outcome.
            }
        }

        // The server's queue id when it reports one; SMTP has no stable
        // provider message id otherwise.
        return SubmissionResult::accepted($sent?->getMessageId());
    }

    public static function classify(TransportExceptionInterface $e): SubmissionResult
    {
        $code = (int) $e->getCode();
        $text = strtolower($e->getMessage());

        return match (true) {
            str_contains($text, 'tls required') || str_contains($text, 'starttls') => SubmissionResult::authFailure('tls_unavailable'),
            in_array($code, [530, 534, 535, 538, 504], true) => SubmissionResult::authFailure(),
            $code === 552 => SubmissionResult::permanent('message_too_large'),
            in_array($code, [550, 551, 553], true) => SubmissionResult::permanent('recipient_rejected'),
            $code === 554 => SubmissionResult::permanent('content_rejected'),
            $code >= 500 && $code < 600 => SubmissionResult::permanent('provider_error'),
            in_array($code, [421, 450, 451], true) => SubmissionResult::transient('provider_unavailable'),
            $code >= 400 && $code < 500 => SubmissionResult::transient('provider_throttled'),
            str_contains($text, 'timed out') || str_contains($text, 'timeout') => SubmissionResult::transient('timeout'),
            default => SubmissionResult::transient('network_error'),
        };
    }

    private function transport(string $tls): EsmtpTransport
    {
        $transport = new EsmtpTransport(
            (string) $this->config->get('email.smtp.host'),
            (int) $this->config->get('email.smtp.port'),
            $tls === 'implicit',
        );

        $transport->setAutoTls($tls !== 'none');
        $transport->setRequireTls($tls !== 'none');

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $timeout = (int) $this->config->get('email.smtp.timeout_seconds');
            $stream->setTimeout((float) max(1, min($timeout > 0 ? $timeout : self::MAX_TIMEOUT_SECONDS, self::MAX_TIMEOUT_SECONDS)));
            if ($tls !== 'none') {
                $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
            }
        }

        $ehlo = trim((string) $this->config->get('email.smtp.ehlo_domain'));
        if ($ehlo !== '') {
            $transport->setLocalDomain($ehlo);
        }

        $username = (string) $this->config->get('email.smtp.username');
        if ($username !== '') {
            $transport->setUsername($username);
            $transport->setPassword((string) $this->config->get('email.smtp.password'));
        }

        return $transport;
    }
}
