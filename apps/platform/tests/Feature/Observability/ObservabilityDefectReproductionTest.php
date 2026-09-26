<?php

namespace Tests\Feature\Observability;

use App\Listeners\RecordQueueHeartbeat;
use App\Models\SchoolAuditEvent;
use App\Support\Events\OutboxReconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §4.2): the six verified observability defects,
 * each proved with fake canary values. Written first against the
 * unfixed code (where each failed), kept as permanent regressions.
 * The database-outage case lives in OperationsStatusOutageTest (it must
 * not run inside a transaction).
 */
class ObservabilityDefectReproductionTest extends TestCase
{
    use CapturesStructuredLogs, CreatesTenancyFixtures;

    #[Test]
    public function a_scheduled_command_failure_never_logs_or_stores_the_raw_exception_message(): void
    {
        $canary = 'canary-command-failure-7c1d2e';
        $this->captureLogs();
        $this->mock(OutboxReconciler::class)->shouldReceive('reconcile')->andThrow(new RuntimeException("reconcile blew up: {$canary}"));

        $exit = Artisan::call('platform:outbox-dispatch');
        $console = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertNoCanaryLogged($canary);
        $this->assertStringNotContainsString($canary, $console);
        $this->assertStringNotContainsString($canary, (string) DB::table('scheduler_heartbeats')->where('name', 'outbox-dispatch')->value('last_error'));
    }

    #[Test]
    public function a_query_exception_never_leaks_its_bound_values(): void
    {
        $canary = 'canary-sql-binding-4b9f0a';
        $this->captureLogs();

        try {
            DB::transaction(fn () => DB::select('select ?::integer as v', [$canary]));
            $this->fail('the query must fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString($canary, $e->getMessage(), 'the exception itself does carry the value -- the proof is not vacuous');
            report($e);
        }

        $this->assertNoCanaryLogged($canary);
    }

    #[Test]
    public function logged_stack_traces_carry_no_argument_values(): void
    {
        $canary = 'CANARYARG9';
        // The production image's PHP default before 0O.5A: arguments kept
        // in traces. The pipeline must not depend on the ini setting alone.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $previousLength = ini_set('zend.exception_string_param_max_len', '15');
        $this->captureLogs();

        try {
            $this->throwWith($canary);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($canary, $e->getTraceAsString(), 'the trace does carry the argument -- the proof is not vacuous');
            report($e);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
            ini_set('zend.exception_string_param_max_len', (string) $previousLength);
        }

        $this->assertNoCanaryLogged($canary);
    }

    private function throwWith(string $secret): never
    {
        throw new RuntimeException('failure with a secret argument on the stack');
    }

    #[Test]
    public function an_oversized_or_malformed_request_id_is_replaced_and_never_reaches_audit(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $hostile = 'canary-'.str_repeat('x', 300);
        $response = $this->withHeaders(['X-Request-Id' => $hostile])->put('/app/settings', [
            'name' => 'Renamed School', 'timezone' => 'Asia/Kolkata', 'default_locale' => 'en',
        ]);

        $response->assertRedirect('/app/settings');
        $echoed = (string) $response->headers->get('X-Request-Id');
        $this->assertNotSame($hostile, $echoed);
        $this->assertTrue(Str::isUuid($echoed), 'a fresh server id replaces it');

        $audit = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'school.settings.updated')->latest('occurred_at')->firstOrFail());
        $this->assertSame($echoed, $audit->request_id);
    }

    #[Test]
    public function the_queue_heartbeat_listener_is_registered_exactly_once(): void
    {
        foreach ([JobProcessed::class => 'handleProcessed', JobFailed::class => 'handleFailed'] as $event => $method) {
            $registrations = collect(app('events')->getRawListeners()[$event] ?? [])
                ->filter(fn ($listener) => is_string($listener) && $listener === RecordQueueHeartbeat::class.'@'.$method
                    || is_array($listener) && $listener === [RecordQueueHeartbeat::class, $method]);

            $this->assertCount(1, $registrations, "{$event} -> RecordQueueHeartbeat@{$method}");
        }
    }
}
