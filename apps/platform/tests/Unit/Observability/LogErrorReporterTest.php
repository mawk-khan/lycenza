<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §6): the reporter for HANDLED exceptions logs one
 * record under a stable event code, with a stable fingerprint, and hands
 * the exception itself to the central pipeline (which reduces it to its
 * safe form -- proven in ObservabilityDefectReproductionTest /
 * LogRedactionCanaryTest). It never builds its own message from the
 * exception.
 */
class LogErrorReporterTest extends TestCase
{
    #[Test]
    public function it_is_the_bound_error_reporter(): void
    {
        $this->assertInstanceOf(LogErrorReporter::class, app(ErrorReporter::class));
    }

    #[Test]
    public function it_reports_the_event_code_fingerprint_and_the_exception_itself(): void
    {
        Log::spy();
        $exception = new RuntimeException('raw text that must not be copied by the reporter');

        (new LogErrorReporter)->report($exception, 'platform.example.failed', 'webhooks', 'deliver', ['attempt' => 3]);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'platform.example.failed'
            && $context['fingerprint'] === RuntimeException::class.':webhooks:deliver'
            && $context['exception'] === $exception
            && $context['attempt'] === 3
            && ! in_array('raw text that must not be copied by the reporter', $context, true));
    }

    #[Test]
    public function two_occurrences_of_the_same_failure_produce_the_same_fingerprint(): void
    {
        Log::spy();

        $reporter = new LogErrorReporter;
        $reporter->report(new RuntimeException('first'), 'platform.example.failed', 'webhooks', 'deliver');
        $reporter->report(new RuntimeException('second, different message'), 'platform.example.failed', 'webhooks', 'deliver');

        Log::shouldHaveReceived('error')->twice()->withArgs(
            fn (string $message, array $context) => $context['fingerprint'] === RuntimeException::class.':webhooks:deliver'
        );
    }
}
