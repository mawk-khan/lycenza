<?php

namespace Tests\Concerns;

use App\Support\Email\Providers\FakeEmailProvider;
use App\Support\Email\Providers\OutboundEmail;
use PHPUnit\Framework\Assert;

/**
 * Phase 0O.9A (ADR 0055 section 22): tests observe email through the
 * deterministic fake PROVIDER (phpunit.xml: MAIL_PROVIDER=fake), i.e. what
 * the durable email layer actually submitted -- never through Laravel's
 * Mail facade, which application code no longer uses.
 */
trait FakesEmail
{
    protected function fakeEmail(): FakeEmailProvider
    {
        $fake = app(FakeEmailProvider::class);
        $fake->reset();

        return $fake;
    }

    protected function emailFake(): FakeEmailProvider
    {
        return app(FakeEmailProvider::class);
    }

    protected function assertNoEmailAccepted(): void
    {
        Assert::assertSame([], $this->emailFake()->acceptedEmails(), 'The fake email provider accepted email.');
    }

    protected function assertEmailAcceptedCount(int $count): void
    {
        Assert::assertCount($count, $this->emailFake()->acceptedEmails(), 'Unexpected number of emails accepted by the fake provider.');
    }

    protected function assertEmailAcceptedTo(string $address, int $times = 1): void
    {
        Assert::assertCount($times, array_filter($this->emailFake()->acceptedEmails(), fn (OutboundEmail $e) => $e->to === strtolower($address)), "Expected {$times} email(s) accepted for the address.");
    }

    protected function assertEmailNotAcceptedTo(string $address): void
    {
        $this->assertEmailAcceptedTo($address, 0);
    }

    /** @param callable(OutboundEmail): bool $matching */
    protected function assertEmailAccepted(callable $matching, int $times = 1): void
    {
        Assert::assertCount($times, array_filter($this->emailFake()->acceptedEmails(), $matching), 'The expected email was not accepted by the fake provider.');
    }

    protected function lastAcceptedEmail(): OutboundEmail
    {
        $email = $this->emailFake()->lastAccepted();
        Assert::assertNotNull($email, 'No email was accepted by the fake provider.');

        return $email;
    }
}
