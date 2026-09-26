<?php

namespace Tests\Feature\Observability;

use App\Domain\Documents\Application\Exceptions\DocumentStorageException;
use App\Support\Observability\Logging\StructuredJsonFormatter;
use App\Support\Observability\Logging\StructuredLogTap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §5-§6): production log records are one JSON
 * object per line with a FIXED top-level schema; stable event codes;
 * identifiers from the request context; everything else under `ctx`.
 */
class StructuredLoggingTest extends TestCase
{
    use CapturesStructuredLogs, CreatesTenancyFixtures;

    #[Test]
    public function every_application_channel_carries_the_central_tap(): void
    {
        foreach (['stack', 'single', 'daily', 'stderr', 'syslog', 'errorlog'] as $channel) {
            $this->assertContains(StructuredLogTap::class, config("logging.channels.{$channel}.tap", []), $channel);
        }
    }

    #[Test]
    public function a_record_is_one_json_line_with_the_fixed_schema(): void
    {
        $this->captureLogs();
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        app(TenantContext::class)->set($school);
        app(TenantContext::class)->setActor($user);
        app(TenantContext::class)->setRequestId('req-structured-0001');
        app(TenantContext::class)->setCorrelationId('corr-structured-0001');

        Log::info('platform.example.completed', ['dispatched' => 3, 'outcome' => 'ok', 'queue' => 'default']);

        $line = $this->capturedJson()[0];
        $this->assertSame('platform.example.completed', $line['event_code']);
        $this->assertSame('info', $line['level']);
        $this->assertSame('platform', $line['service']);
        $this->assertSame('console', $line['process_role']);
        $this->assertSame('testing', $line['environment']);
        $this->assertSame('req-structured-0001', $line['request_id']);
        $this->assertSame('corr-structured-0001', $line['correlation_id']);
        $this->assertSame($school->id, $line['school_id'], 'logs MAY carry the School id (Confidential logging contract)');
        $this->assertSame($user->id, $line['actor_user_id']);
        $this->assertSame('ok', $line['outcome']);
        $this->assertSame('default', $line['queue']);
        $this->assertSame(['dispatched' => 3], $line['ctx']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $line['time']);
        $this->assertArrayNotHasKey('message', $line, 'a dotted code is the event code, not prose');
        $this->assertStringEndsWith("\n", $this->capturedOutput());
        $this->assertSame(1, substr_count($this->capturedOutput(), "\n"));
    }

    #[Test]
    public function unknown_context_never_grows_the_top_level_schema(): void
    {
        $this->captureLogs();
        Context::add('some_future_context_key', 'x');

        Log::warning('Something human-readable happened', ['arbitrary_field' => 'value', 'nested' => ['a' => 1]]);

        $line = $this->capturedJson()[0];
        $this->assertSame([], array_diff(array_keys($line), StructuredJsonFormatter::FIELDS), 'only catalog fields at the top level');
        $this->assertSame('Something human-readable happened', $line['message']);
        $this->assertSame('value', $line['ctx']['arbitrary_field']);
        $this->assertSame('x', $line['ctx']['some_future_context_key']);
        $this->assertArrayNotHasKey('event_code', $line);
    }

    #[Test]
    public function an_exception_record_keeps_diagnostic_value_without_its_message(): void
    {
        $this->captureLogs();

        report(new RuntimeException('internal detail canary-exc-51f0'));

        $line = $this->capturedJson()[0];
        $this->assertSame('application.exception', $line['event_code']);
        $this->assertSame(RuntimeException::class, $line['exception_class']);
        $this->assertSame('error', $line['level']);
        $this->assertNotEmpty($line['ctx']['exception']['trace']);
        $this->assertStringContainsString('tests/Feature/Observability/StructuredLoggingTest.php', $line['ctx']['exception']['file']);
        $this->assertStringNotContainsString('canary-exc-51f0', $this->capturedOutput());
    }

    #[Test]
    public function the_application_s_own_exceptions_keep_their_user_facing_message(): void
    {
        $this->captureLogs();

        report(new DocumentStorageException);

        $line = $this->capturedJson()[0];
        $this->assertSame(DocumentStorageException::class, $line['exception_class']);
        $this->assertArrayHasKey('message', $line['ctx']['exception']);
    }

    #[Test]
    public function local_line_format_is_still_sanitized(): void
    {
        $this->captureLogs('line');

        Log::info('login attempt', ['password' => 'canary-line-pw-77aa', 'email' => 'someone@example.test']);

        $this->assertStringNotContainsString('canary-line-pw-77aa', $this->capturedOutput());
        $this->assertStringContainsString('[redacted]', $this->capturedOutput());
    }
}
