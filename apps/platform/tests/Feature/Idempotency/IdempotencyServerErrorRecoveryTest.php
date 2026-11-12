<?php

namespace Tests\Feature\Idempotency;

use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Middleware\EnsureIdempotent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C30 (C14-N2, Platform owner decisions C30-A/B, 2026-11-12): a rendered 5xx is released only when the
 * request provably committed nothing, so the same key retries for real; after a committed effect it stays
 * remembered, and a retry never runs the operation again.
 *
 * On `506c6c62` every rendered 5xx was stored and replayed for 48 hours, even one raised before any write
 * (`IdempotencyFailureClassificationTest` pinned it). The failures here are real and server-side: probe routes
 * that fail at a chosen boundary, and database triggers that fail a real route's statement. Fixtures are
 * committed, so a commit here is a real COMMIT and the detector runs as it does in production.
 */
class IdempotencyServerErrorRecoveryTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $actor;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->actor = $this->createUserWithCapabilities($this->school, ['hr.employees.notes.view', 'hr.employees.notes.manage']);
        $this->employee = $this->createEmployee($this->school);

        Route::middleware(['api', 'auth:sanctum', 'school-membership', 'idempotent'])
            ->post('/api/v1/schools/{school}/c30-probe/{mode}', function (School $school, string $mode) {
                $note = fn () => app(EmployeeNoteService::class)->add(Employee::query()->findOrFail($this->employee->id), ['body' => "probe {$mode}", 'classification_tier' => 'sensitive'], $this->actor);

                return match ($mode) {
                    'succeed' => response()->json(['data' => ['id' => $note()->id]], 201),
                    'read-then-fail' => (function () {
                        DB::select('select 1');
                        throw new RuntimeException('a dependency went away');
                    })(),
                    'rollback-then-fail' => DB::transaction(function () use ($note) {
                        $note();
                        throw new RuntimeException('failed inside the transaction');
                    }),
                    'commit-then-fail' => (function () use ($note) {
                        $note();
                        throw new RuntimeException('failed after the commit');
                    })(),
                    'autocommit-then-fail' => (function () {
                        DB::update('update employees set updated_at = now() where id = ?', [$this->employee->id]);
                        throw new RuntimeException('failed after an autocommitted write');
                    })(),
                    'render-after-commit' => (function () use ($note) {
                        $note();

                        return response()->json(['value' => NAN]);
                    })(),
                    'volatile-select-then-fail' => (function () use ($school) {
                        DB::select('select inventory_location_has_operational_history(gen_random_uuid(), ?) as x', [$school->id]);
                        throw new RuntimeException('failed after a volatile function');
                    })(),
                    // A real push through a real queue driver: the job is accepted, then the request fails.
                    'queued-then-fail' => (function () {
                        Queue::connection('database')->push('c30-probe-job', ['school' => $this->school->id]);
                        throw new RuntimeException('failed after queueing');
                    })(),
                    'http-then-fail' => (function () {
                        Http::fake(['*' => Http::response('ok')]);
                        Http::get('https://example.test/c30');
                        throw new RuntimeException('failed after an outbound call');
                    })(),
                    // Review MEDIUM-1: an autocommit write that reports failure may still have committed (a lost
                    // acknowledgement). Attempted is enough: here a foreign-key violation stands in for the lost reply.
                    'attempted-autocommit-write' => DB::update('update employees set school_id = gen_random_uuid() where id = ?', [$this->employee->id]),
                    'savepoint-then-outer-rollback' => DB::transaction(function () use ($note) {
                        DB::transaction(fn () => $note());
                        throw new RuntimeException('the outer transaction rolled back after an inner one committed');
                    }),
                    'manual-commit-then-fail' => (function () {
                        DB::beginTransaction();
                        DB::update('update employees set updated_at = now() where id = ?', [$this->employee->id]);
                        DB::commit();
                        throw new RuntimeException('failed after a manual commit');
                    })(),
                };
            })->name('c30.probe');
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->table('jobs')->where('payload', 'like', '%c30-probe-job%')->delete();
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        parent::tearDown();
    }

    private function probe(string $mode, string $key)
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->actor->createToken('c30')->plainTextToken, 'Idempotency-Key' => $key])
            ->postJson("/api/v1/schools/{$this->school->id}/c30-probe/{$mode}");
    }

    private function inSchool(callable $read): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $read);
    }

    private function notes(): int
    {
        return $this->inSchool(fn () => DB::table('employee_notes')->where('employee_id', $this->employee->id)->count());
    }

    /** @return list<array{0: string, 1: ?int}> status and stored response status of every row under the key */
    private function rows(string $key): array
    {
        return DB::connection('pgsql_admin')->table('api_idempotency_keys')->where('school_id', $this->school->id)->where('idempotency_key', $key)
            ->get(['status', 'response_status'])->map(fn ($r) => [(string) $r->status, $r->response_status === null ? null : (int) $r->response_status])->all();
    }

    /** @return array<string, array{0: string}> */
    public static function nothingCommitted(): array
    {
        return [
            'a failure before any write' => ['read-then-fail'],
            'a failure inside a transaction that rolled back' => ['rollback-then-fail'],
            'an inner commit undone by the outer rollback' => ['savepoint-then-outer-rollback'],
        ];
    }

    /** The reproduction: on 506c6c62 both of these were stored and replayed for 48 hours. */
    #[DataProvider('nothingCommitted')]
    #[Test]
    public function a_server_error_that_committed_nothing_is_released_and_the_same_key_retries_for_real(string $mode): void
    {
        $key = "c30-released-{$mode}";
        $first = $this->probe($mode, $key);
        $first->assertStatus(500);
        $this->assertNull($first->headers->get('Idempotency-Replayed'));
        $this->assertSame([], $this->rows($key), 'nothing is remembered under the key');
        $this->assertSame(0, $this->notes(), 'and nothing was written');

        $second = $this->probe($mode, $key);
        $second->assertStatus(500);
        $this->assertNull($second->headers->get('Idempotency-Replayed'), 'the retry ran again, it was not a replay');
        $this->assertSame([], $this->rows($key));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function somethingCommitted(): array
    {
        return [
            'a failure after a committed transaction' => ['commit-then-fail', 1],
            'a failure after an autocommitted write' => ['autocommit-then-fail', 0],
            'a response that failed to render after the commit' => ['render-after-commit', 1],
            'a failure after a volatile function (may write)' => ['volatile-select-then-fail', 0],
            'a failure after a job was queued' => ['queued-then-fail', 0],
            'a failure after an outbound HTTP request' => ['http-then-fail', 0],
            'an autocommit write that reported failure' => ['attempted-autocommit-write', 0],
            'a failure after a manual commit' => ['manual-commit-then-fail', 0],
        ];
    }

    /** C30-B: a 5xx after a committed effect is remembered, and the retry never runs the operation again. */
    #[DataProvider('somethingCommitted')]
    #[Test]
    public function a_server_error_after_a_committed_effect_is_remembered_and_never_runs_twice(string $mode, int $notes): void
    {
        $key = "c30-remembered-{$mode}";
        $this->probe($mode, $key)->assertStatus(500);
        $this->assertSame([['completed', 500]], $this->rows($key));
        $this->assertSame($notes, $this->notes());

        $retry = $this->probe($mode, $key);
        $retry->assertStatus(500);
        $this->assertSame('true', $retry->headers->get('Idempotency-Replayed'));
        $this->assertSame($notes, $this->notes(), 'the committed effect happened once');
        $this->assertSame([['completed', 500]], $this->rows($key));
    }

    #[Test]
    public function a_success_is_remembered_and_replayed_as_before(): void
    {
        $this->probe('succeed', 'c30-success')->assertCreated();
        $replay = $this->probe('succeed', 'c30-success');
        $replay->assertCreated();
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->notes());
        $this->assertSame([['completed', 201]], $this->rows('c30-success'));
    }

    /** The same key with another request: released means forgotten (a new attempt); remembered means a conflict. */
    #[Test]
    public function the_same_key_with_another_request_follows_what_was_remembered(): void
    {
        $this->probe('read-then-fail', 'c30-other-request')->assertStatus(500);
        $this->probe('succeed', 'c30-other-request')->assertCreated();
        $this->assertSame(1, $this->notes(), 'nothing was remembered, so the other request is a new attempt');

        $this->probe('commit-then-fail', 'c30-remembered-request')->assertStatus(500);
        $this->probe('succeed', 'c30-remembered-request')->assertStatus(409)->assertJsonMissingPath('error.exception');
        $this->assertSame(2, $this->notes(), 'one note from the success, one from the committed failure; the conflict wrote nothing');
        $this->assertSame([['completed', 500]], $this->rows('c30-remembered-request'));
    }

    /** The 48-hour window is unchanged: a remembered 5xx is replayed to its last second and forgotten after it. */
    #[Test]
    public function a_remembered_server_error_lives_exactly_as_long_as_any_outcome(): void
    {
        $start = now()->startOfSecond();
        $this->travelTo($start);
        $this->probe('commit-then-fail', 'c30-ttl-window')->assertStatus(500);

        foreach (['+1 minute', '+24 hours', '+47 hours 59 minutes 59 seconds', '+48 hours'] as $later) {
            $this->travelTo($start->copy()->modify($later));
            $this->assertSame('true', $this->probe('commit-then-fail', 'c30-ttl-window')->headers->get('Idempotency-Replayed'), "replayed at {$later}");
        }
        $this->assertSame(1, $this->notes());

        // After the window the key is forgotten, as a success's is: the same key is then a new attempt.
        $this->travelTo($start->copy()->modify('+48 hours 1 second'));
        $this->assertNull($this->probe('commit-then-fail', 'c30-ttl-window')->headers->get('Idempotency-Replayed'));
        $this->assertSame(2, $this->notes());
    }

    /** The direct-call path (an exception that reaches the middleware unrendered) follows the same rule. */
    #[Test]
    public function an_unrendered_exception_follows_the_same_rule(): void
    {
        $call = function (string $key, callable $work) {
            $route = (new \Illuminate\Routing\Route('POST', 'c30-direct', fn () => null))->name('c30.direct');
            $route->setRouter(app('router'))->setContainer(app());
            $request = Request::create('/c30-direct', 'POST', [], [], [], ['HTTP_IDEMPOTENCY_KEY' => $key]);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            app(TenantContext::class)->set($this->school);
            app(TenantContext::class)->setActor($this->actor);
            try {
                app(EnsureIdempotent::class)->handle($request, function () use ($work) {
                    $work();

                    return response()->json(['ok' => true]);
                });
                $this->fail('the exception was swallowed');
            } catch (RuntimeException) {
            } finally {
                app(TenantContext::class)->clearAll();
            }
        };

        $call('c30-direct-nothing', fn () => throw new RuntimeException('nothing written'));
        $this->assertSame([], $this->rows('c30-direct-nothing'));

        $call('c30-direct-committed', function () {
            DB::update('update employees set updated_at = now() where id = ?', [$this->employee->id]);
            throw new RuntimeException('after a write');
        });
        $this->assertSame([['completed', 500]], $this->rows('c30-direct-committed'));
    }

    /** A 5xx stored before C30 (a pre-fix row) is left as it was: replayed until it expires, never rewritten. */
    #[Test]
    public function a_server_error_stored_before_c30_is_replayed_until_it_expires(): void
    {
        $this->probe('succeed', 'c30-legacy')->assertCreated();
        DB::connection('pgsql_admin')->table('api_idempotency_keys')->where('idempotency_key', 'c30-legacy')
            ->update(['response_status' => 500, 'response_body' => json_encode(['message' => 'Server Error'])]);

        $replay = $this->probe('succeed', 'c30-legacy');
        $replay->assertStatus(500);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame([['completed', 500]], $this->rows('c30-legacy'));
        $this->assertSame(1, $this->notes());
    }
}
