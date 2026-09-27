<?php

namespace App\Support\Email\Providers;

use Illuminate\Support\Facades\Log;

/**
 * ADR 0055 section 22: the deterministic, network-free provider for
 * local/testing (`MAIL_PROVIDER=fake`; refused in production by
 * ProductionConfigurationGuard). A container singleton: tests script its
 * next outcomes and assert on what it accepted (Tests\Concerns\FakesEmail).
 *
 * - accepts by default, with a stable provider message id;
 * - `queue()` scripts the next outcomes (transient, permanent, auth, a
 *   timeout);
 * - `crashAfterAcceptingNext()` models "the provider accepted, then the
 *   worker died before recording it" (ADR 0055 section 9.3);
 * - `honourIdempotency()` models a provider that deduplicates on the
 *   submission idempotency key (optional in the ADR) -- without it, a
 *   resubmission after a crash is a real duplicate, which is exactly the
 *   documented at-least-once residual.
 */
final class FakeEmailProvider implements EmailProviderAdapter
{
    /** @var list<array{email: OutboundEmail, result: SubmissionResult}> */
    private array $calls = [];

    /** @var list<SubmissionResult|string> */
    private array $script = [];

    /** @var array<string, string> idempotency key => provider message id */
    private array $accepted = [];

    private bool $honoursIdempotency = false;

    public function name(): string
    {
        return 'fake';
    }

    public function supportsIdempotencyKey(): bool
    {
        return $this->honoursIdempotency;
    }

    public function submit(OutboundEmail $email): SubmissionResult
    {
        $next = array_shift($this->script);

        if ($next instanceof SubmissionResult) {
            $this->calls[] = ['email' => $email, 'result' => $next];

            return $next;
        }

        if ($this->honoursIdempotency && isset($this->accepted[$email->idempotencyKey])) {
            // The provider recognised the key: the same message, not a new one.
            return SubmissionResult::accepted($this->accepted[$email->idempotencyKey]);
        }

        $result = SubmissionResult::accepted('fake-'.substr(hash('sha256', $email->idempotencyKey.'#'.count($this->calls)), 0, 32));
        $this->calls[] = ['email' => $email, 'result' => $result];
        $this->accepted[$email->idempotencyKey] = (string) $result->providerMessageId;

        // Ids only -- never the address, subject or body.
        Log::info('email.fake_provider.accepted', ['email_message_id' => $email->messageId]);

        if ($next === 'crash') {
            throw new SimulatedWorkerCrash('Simulated worker crash after provider acceptance.');
        }

        return $result;
    }

    public function queue(SubmissionResult ...$results): self
    {
        array_push($this->script, ...$results);

        return $this;
    }

    public function crashAfterAcceptingNext(): self
    {
        $this->script[] = 'crash';

        return $this;
    }

    public function honourIdempotency(bool $honour = true): self
    {
        $this->honoursIdempotency = $honour;

        return $this;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->script = [];
        $this->accepted = [];
        $this->honoursIdempotency = false;
    }

    /** @return list<OutboundEmail> every email the provider ACCEPTED (duplicates included) */
    public function acceptedEmails(): array
    {
        return array_values(array_map(
            fn (array $call) => $call['email'],
            array_filter($this->calls, fn (array $call) => $call['result']->outcome === SubmissionOutcome::Accepted),
        ));
    }

    /** @return list<array{email: OutboundEmail, result: SubmissionResult}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function lastAccepted(): ?OutboundEmail
    {
        $accepted = $this->acceptedEmails();

        return $accepted === [] ? null : $accepted[count($accepted) - 1];
    }
}
