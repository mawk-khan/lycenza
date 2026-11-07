<?php

namespace Tests\Feature\Idempotency;

use App\Domain\HR\Infrastructure\Employee;
use App\Http\Middleware\EnsureIdempotent;
use App\Models\ApiIdempotencyKey;
use App\Models\School;
use App\Models\User;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C14 (C11-N2, RELIABILITY.md "Failure classification"): what an idempotency key
 * remembers, over real HTTP. Laravel's routing pipeline renders an exception into a
 * response BEFORE it reaches route middleware, so `EnsureIdempotent`'s catch blocks never
 * see one on a real request. On `e3df37b3` a suspended School's 404 was stored and replayed
 * for 48 hours after it resumed. That is corrected. A 500 is stored too, against what
 * RELIABILITY.md used to say: releasing it can double an effect, so that stays a decision.
 */
class IdempotencyFailureClassificationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const RETRIED = 'test-key-retried-request';

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.notes.view', 'hr.employees.notes.manage']);
        $this->employee = $this->createEmployee($this->school);
    }

    /** @return array<string, string> */
    private function headers(string $key = self::RETRIED): array
    {
        return ['Authorization' => 'Bearer '.$this->hr->createToken('t')->plainTextToken, 'Idempotency-Key' => $key];
    }

    private function postNote(string $key = self::RETRIED, array $body = ['body' => 'A note', 'classification_tier' => 'sensitive'])
    {
        return $this->withHeaders($this->headers($key))->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/notes", $body);
    }

    /** The School's check fails once, the way a suspension landing after the request-time check does. */
    private function theGuardFailsOnceWith(\Throwable $failure): void
    {
        $real = app(SchoolOperationalGuard::class);
        $this->app->instance(SchoolOperationalGuard::class, new class($real, $failure) extends SchoolOperationalGuard
        {
            private bool $spent = false;

            public function __construct(private SchoolOperationalGuard $real, private \Throwable $failure) {}

            public function requireOperational(string $schoolId): void
            {
                if (! $this->spent) {
                    $this->spent = true;

                    throw $this->failure;
                }
                $this->real->requireOperational($schoolId);
            }
        });
    }

    private function notes(): int
    {
        return $this->inSchool(fn () => DB::table('employee_notes')->where('employee_id', $this->employee->id)->count());
    }

    private function keys(): int
    {
        return $this->inSchool(fn () => DB::table('api_idempotency_keys')->where('school_id', $this->school->id)->where('idempotency_key', self::RETRIED)->count());
    }

    /** Forced RLS reads nothing with no School context, so a bare count here would prove nothing. */
    private function inSchool(callable $read): int
    {
        return app(TenantContext::class)->withSchool($this->school, $read);
    }

    #[Test]
    public function a_suspended_school_is_not_remembered_under_the_key(): void
    {
        $this->theGuardFailsOnceWith(new SchoolNotOperationalException);

        $this->postNote()->assertNotFound();

        $this->assertSame(0, $this->notes());
        $this->assertSame(0, $this->keys(), 'the refusal was stored under the key');
        app('auth')->forgetGuards();

        // The School has resumed: the retry is a real attempt, not a replay of the refusal.
        $retry = $this->postNote();
        $retry->assertCreated();
        $this->assertNull($retry->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->notes());
    }

    #[Test]
    public function a_server_error_stays_remembered_because_it_can_follow_a_commit(): void
    {
        // Today's behaviour, pinned, not endorsed: C14-N2 is the Platform owner's. Releasing it would let a retry run a
        // committed effect twice on a route with no atomic completion, so a new key is what gets a new attempt.
        $this->theGuardFailsOnceWith(new RuntimeException('a dependency went away'));

        $this->postNote()->assertStatus(500);

        $this->assertSame(1, $this->keys());
        app('auth')->forgetGuards();
        $replay = $this->postNote();
        $replay->assertStatus(500);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(0, $this->notes());

        app('auth')->forgetGuards();
        $this->postNote('01890f3e-7c1a-7d3e-9b1a-1234567890ac')->assertCreated();
        $this->assertSame(1, $this->notes());
    }

    #[Test]
    public function a_self_participating_completion_outlives_a_failure_after_it(): void
    {
        $record = $this->claim();
        $this->inContext(function () use ($record): void {
            app(IdempotencyGuard::class)->completeWithin($record, 201, ['data' => ['id' => 'done']]);
            app(IdempotencyGuard::class)->releaseIfStillClaimed($record->fresh());
        });

        $this->assertSame(1, $this->keys(), 'a completed record is not a claim');
    }

    #[Test]
    public function a_slow_request_cannot_release_a_claim_someone_else_reclaimed(): void
    {
        $record = $this->claim();
        $this->inContext(function () use ($record): void {
            DB::table('api_idempotency_keys')->where('id', $record->id)->update(['created_at' => now()->addMinute()]);
            app(IdempotencyGuard::class)->releaseIfStillClaimed($record);
        });

        $this->assertSame(1, $this->keys(), 'the reclaimed claim stays');
    }

    #[Test]
    public function the_claim_is_released_while_it_is_still_held(): void
    {
        $record = $this->claim();

        $this->inContext(fn () => app(IdempotencyGuard::class)->releaseIfStillClaimed($record));

        $this->assertSame(0, $this->keys());
    }

    #[Test]
    public function the_accrual_run_stays_off_the_idempotent_routes(): void
    {
        // A release is safe only where a School refusal cannot follow a commit. LeaveAccrualService commits batches and
        // can then be refused, so a key on its route would let a retry repeat them. This pins that one service, not the class.
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (str_ends_with($route->uri(), '/accrual-runs') && in_array('POST', $route->methods(), true)) {
                $this->assertNotContains('idempotent', $route->gatherMiddleware(), $route->uri());
                $this->assertNotContains(EnsureIdempotent::class, $route->gatherMiddleware(), $route->uri());
                $checked++;
            }
        }
        $this->assertSame(2, $checked, 'one web route and one API route');
    }

    private function inContext(callable $work): void
    {
        app(TenantContext::class)->withSchool($this->school, $work);
    }

    private function claim(): ApiIdempotencyKey
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => app(IdempotencyGuard::class)->claim($this->school->id, 'user', $this->hr->id, 'test.route', self::RETRIED, 'POST', str_repeat('a', 64), 3600)->record);
    }

    #[Test]
    public function a_success_is_still_replayed_and_nothing_is_written_twice(): void
    {
        $first = $this->postNote();
        $first->assertCreated();
        app('auth')->forgetGuards();
        $replay = $this->postNote();

        $replay->assertCreated();
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->notes());
    }

    #[Test]
    public function a_deterministic_refusal_is_still_remembered(): void
    {
        $body = ['body' => '', 'classification_tier' => 'sensitive'];

        $this->postNote(body: $body)->assertUnprocessable();
        app('auth')->forgetGuards();
        $replay = $this->postNote(body: $body);

        $replay->assertUnprocessable();
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
    }

    #[Test]
    public function a_key_is_scoped_to_its_actor_after_a_release(): void
    {
        $this->theGuardFailsOnceWith(new SchoolNotOperationalException);
        $this->postNote()->assertNotFound();

        $other = $this->createUserWithCapabilities($this->school, ['hr.employees.notes.view', 'hr.employees.notes.manage']);
        app('auth')->forgetGuards();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken, 'Idempotency-Key' => self::RETRIED])
            ->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/notes", ['body' => 'A note', 'classification_tier' => 'sensitive']);

        $response->assertCreated();
        $this->assertNull($response->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->notes());
    }
}
