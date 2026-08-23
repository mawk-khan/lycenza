<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use App\Support\Observability\LogSanitizer;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0C.4 section 38/40: proves the default ErrorReporter emits a
 * stable, class+component+operation fingerprint (never a stack-trace
 * hash) and sanitizes caller-supplied metadata before it ever reaches
 * a log line.
 */
class LogErrorReporterTest extends TestCase
{
    #[Test]
    public function it_is_bound_as_the_default_error_reporter(): void
    {
        $this->assertInstanceOf(LogErrorReporter::class, app(ErrorReporter::class));
    }

    #[Test]
    public function it_reports_a_stable_fingerprint_and_sanitized_metadata(): void
    {
        Log::spy();

        $reporter = new LogErrorReporter(new LogSanitizer);
        $exception = new RuntimeException('something broke');

        $reporter->report(
            $exception,
            component: 'webhooks',
            operation: 'deliver',
            schoolId: 'school-1',
            requestId: 'req-1',
            correlationId: 'corr-1',
            metadata: ['endpoint_secret' => 'shh', 'attempt' => 3],
        );

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'application_error'
                && $context['exception_class'] === RuntimeException::class
                && $context['component'] === 'webhooks'
                && $context['operation'] === 'deliver'
                && $context['fingerprint'] === RuntimeException::class.':webhooks:deliver'
                && $context['metadata']['endpoint_secret'] === '[redacted]'
                && $context['metadata']['attempt'] === 3;
        });
    }

    #[Test]
    public function two_occurrences_of_the_same_failure_produce_the_same_fingerprint(): void
    {
        Log::spy();

        $reporter = new LogErrorReporter(new LogSanitizer);
        $reporter->report(new RuntimeException('first'), 'webhooks', 'deliver');
        $reporter->report(new RuntimeException('second, different message and call site'), 'webhooks', 'deliver');

        Log::shouldHaveReceived('error')->twice()->withArgs(
            fn (string $message, array $context) => $context['fingerprint'] === RuntimeException::class.':webhooks:deliver'
        );
    }
}
