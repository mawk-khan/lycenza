<?php

namespace Tests\Feature\Idempotency;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * HR.R1-C30 (C14-N2, C30-A/B) on real routes, with real server-side failures: a database trigger, created on the
 * isolated test database and dropped in `finally`, fails one statement of a real request.
 *
 * - `employee_notes`: the note's insert fails inside the service's transaction, which rolls back. Nothing
 *   committed, so the 5xx is released.
 * - `webhook-endpoints`: the endpoint row commits on its own and the audit row after it fails, the route C14
 *   named (a retry would create a second endpoint). The 5xx is remembered, and a retry never creates another.
 *
 * Both are also raced by two real processes (`curl` against a `php -S` server, separate PostgreSQL sessions).
 * Fixtures are committed, so a commit is a real COMMIT.
 */
class IdempotencyServerErrorRealRouteTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    /** @var array<string, string> one fresh code per admin, sent unchanged on every retry (it is part of the request) */
    private array $codes = [];

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?Process $server = null;

    private int $port = 18230;

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

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->select('select pg_advisory_unlock_all()');
        $this->server?->stop();
        // Delta review M-T1: never leave a worker serving this port to a later run.
        (new Process(['pkill', '-f', "php -S 127.0.0.1:{$this->port}"]))->run();
        $this->dropTriggers();
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        parent::tearDown();
    }

    private function failNotesWith(string $body): void
    {
        DB::connection('pgsql_admin')->unprepared(<<<SQL
            CREATE FUNCTION public.c30_fail_note() RETURNS trigger LANGUAGE plpgsql AS \$f\$
            BEGIN
                IF NEW.body = '{$body}' THEN PERFORM pg_advisory_lock(3030); PERFORM pg_advisory_unlock(3030); RAISE EXCEPTION 'c30 injected failure'; END IF;
                RETURN NEW;
            END \$f\$;
            CREATE TRIGGER c30_fail_note BEFORE INSERT ON employee_notes FOR EACH ROW EXECUTE FUNCTION public.c30_fail_note();
            SQL);
    }

    private function failWebhookAudits(): void
    {
        DB::connection('pgsql_admin')->unprepared(<<<'SQL'
            CREATE FUNCTION public.c30_fail_audit() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
                IF NEW.event_type = 'integrations.webhook_endpoint.created' THEN PERFORM pg_advisory_lock(3030); PERFORM pg_advisory_unlock(3030); RAISE EXCEPTION 'c30 injected audit failure'; END IF;
                RETURN NEW;
            END $f$;
            CREATE TRIGGER c30_fail_audit BEFORE INSERT ON school_audit_events FOR EACH ROW EXECUTE FUNCTION public.c30_fail_audit();
            SQL);
    }

    private function dropTriggers(): void
    {
        DB::connection('pgsql_admin')->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS c30_fail_note ON employee_notes;
            DROP FUNCTION IF EXISTS public.c30_fail_note();
            DROP TRIGGER IF EXISTS c30_fail_audit ON school_audit_events;
            DROP FUNCTION IF EXISTS public.c30_fail_audit();
            SQL);
    }

    private function inSchool(callable $read): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $read);
    }

    private function notes(): int
    {
        return $this->inSchool(fn () => DB::table('employee_notes')->where('employee_id', $this->employee->id)->count());
    }

    private function endpoints(): int
    {
        return $this->inSchool(fn () => DB::table('webhook_endpoints')->where('school_id', $this->school->id)->count());
    }

    /** @return list<array{0: string, 1: ?int}> */
    private function rows(string $key): array
    {
        return DB::connection('pgsql_admin')->table('api_idempotency_keys')->where('school_id', $this->school->id)->where('idempotency_key', $key)
            ->get(['status', 'response_status'])->map(fn ($r) => [(string) $r->status, $r->response_status === null ? null : (int) $r->response_status])->all();
    }

    private function postNote(string $key, string $body)
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->hr->createToken('c30')->plainTextToken, 'Idempotency-Key' => $key])
            ->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/notes", ['body' => $body, 'classification_tier' => 'sensitive']);
    }

    private function postEndpoint(User $admin, string $key)
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$admin->createToken('c30')->plainTextToken, 'Idempotency-Key' => $key])
            ->postJson("/api/v1/schools/{$this->school->id}/webhook-endpoints", ['name' => 'C30 ERP', 'url' => 'https://8.8.8.8/hooks/c30', 'mfa_code' => $this->codes[$admin->id] ??= $this->freshMfaCode($admin)]);
    }

    private function schoolAdmin(): User
    {
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $this->school), 'school_admin');

        return $admin;
    }

    #[Test]
    public function a_note_whose_transaction_rolled_back_is_released_and_the_same_key_then_succeeds_once(): void
    {
        $this->failNotesWith('C30 rolled back');
        $this->postNote('c30-note-rollback', 'C30 rolled back')->assertStatus(500);
        $this->assertSame([], $this->rows('c30-note-rollback'), 'nothing committed, nothing remembered');
        $this->assertSame(0, $this->notes());

        // The cause goes away (a dependency recovers); the same key is a real retry, and runs once.
        $this->dropTriggers();
        $this->postNote('c30-note-rollback', 'C30 rolled back')->assertCreated();
        $replay = $this->postNote('c30-note-rollback', 'C30 rolled back');
        $replay->assertCreated();
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->notes());
        $this->assertSame([['completed', 201]], $this->rows('c30-note-rollback'));
    }

    /**
     * C14's example: a retry here used to be the risk. The endpoint committed; the 5xx is remembered. (The fresh
     * MFA code is spent before the endpoint is written, itself a committed write, so on a fresh-MFA route every
     * 5xx is remembered: a retry could not reuse the code anyway.)
     */
    #[Test]
    public function a_webhook_endpoint_that_committed_before_its_audit_failed_is_never_created_twice(): void
    {
        $admin = $this->schoolAdmin();
        $this->failWebhookAudits();
        $this->postEndpoint($admin, 'c30-endpoint-after-commit')->assertStatus(500);
        $this->assertSame(1, $this->endpoints(), 'the endpoint committed before the audit failed');
        $this->assertSame([['completed', 500]], $this->rows('c30-endpoint-after-commit'));

        $this->dropTriggers();
        $retry = $this->postEndpoint($admin, 'c30-endpoint-after-commit');
        $retry->assertStatus(500);
        $this->assertSame('true', $retry->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, $this->endpoints(), 'never a second endpoint under the same key');
    }

    /** Two real processes, one key, a failure that commits nothing: no double, and nothing false remembered. */
    #[Test]
    public function two_real_processes_racing_a_released_failure_never_write_twice(): void
    {
        $this->failNotesWith('C30 raced');
        $token = $this->hr->createToken('c30-race')->plainTextToken;
        $this->startServer();
        $url = "http://127.0.0.1:{$this->port}/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/notes";
        $body = json_encode(['body' => 'C30 raced', 'classification_tier' => 'sensitive']);

        $this->assertSame([409, 500], $this->raceUnderBarrier($url, $token, 'c30-race-released', $body), 'one failed for real, the other overlapped it and found it in flight');
        $this->assertSame(0, $this->notes());
        $this->assertSame([], $this->rows('c30-race-released'), 'no 5xx is remembered');

        $this->dropTriggers();
        [$c, $d] = [$this->curl($url, $token, 'c30-race-released', $body), $this->curl($url, $token, 'c30-race-released', $body)];
        $c->wait();
        $d->wait();
        $codes = [(int) $c->getOutput(), (int) $d->getOutput()];
        $this->assertContains(201, $codes, json_encode($codes));
        foreach ($codes as $code) {
            $this->assertContains($code, [201, 409]);
        }
        $this->assertSame(1, $this->notes(), 'one note, whichever process won');
        $this->assertSame([['completed', 201]], $this->rows('c30-race-released'));
    }

    /** Two real processes, one key, a failure after a commit: exactly one endpoint, and the 5xx is what is kept. */
    #[Test]
    public function two_real_processes_racing_a_committed_failure_create_one_endpoint(): void
    {
        $admin = $this->schoolAdmin();
        $token = $admin->createToken('c30-race')->plainTextToken;
        $this->failWebhookAudits();
        $this->startServer();
        $url = "http://127.0.0.1:{$this->port}/api/v1/schools/{$this->school->id}/webhook-endpoints";
        $body = json_encode(['name' => 'C30 ERP', 'url' => 'https://8.8.8.8/hooks/c30', 'mfa_code' => $this->freshMfaCode($admin)]);

        $this->assertSame([409, 500], $this->raceUnderBarrier($url, $token, 'c30-race-remembered', $body), 'one committed then failed, the other overlapped it and found it in flight');
        // Then a later retry of the same key: the remembered 5xx, never a second endpoint.
        $c = $this->curl($url, $token, 'c30-race-remembered', $body);
        $c->wait();
        $this->assertSame(500, (int) $c->getOutput());
        $this->assertSame(1, $this->endpoints());
        $this->assertSame([['completed', 500]], $this->rows('c30-race-remembered'));
    }

    /**
     * Delta review L3: a deterministic overlap. The test holds advisory lock 3030; the first request to reach the
     * injected trigger waits on it, so the second is answered (409, in flight) while the first is still running.
     * Only then is the lock released and the first one finishes.
     *
     * @return list<int> both status codes, sorted
     */
    private function raceUnderBarrier(string $url, string $token, string $key, string $body): array
    {
        $admin = DB::connection('pgsql_admin');
        $admin->select('select pg_advisory_lock(3030)');
        try {
            $a = $this->curl($url, $token, $key, $body);
            $b = $this->curl($url, $token, $key, $body);
            // Under the 30 s in-flight timeout, past which the second request could reclaim the held claim.
            $deadline = microtime(true) + 25;
            while ($a->isRunning() && $b->isRunning()) {
                if (microtime(true) > $deadline) {
                    $this->fail('neither request returned while the other was held');
                }
                usleep(10_000);
            }
            $first = $a->isRunning() ? $b : $a;
            $this->assertSame(409, (int) $first->getOutput(), 'the request that returned first found the other in flight');
        } finally {
            $admin->select('select pg_advisory_unlock(3030)');
        }
        $a->wait();
        $b->wait();
        $codes = [(int) $a->getOutput(), (int) $b->getOutput()];
        sort($codes);

        return $codes;
    }

    private function startServer(): void
    {
        // Review LOW-4: `php -S` serves one request at a time unless it has workers. The triggers wait on an advisory
        // lock the test holds (raceUnderBarrier), so the second request certainly arrives while the first is in flight.
        $this->server = new Process(['php', '-S', "127.0.0.1:{$this->port}", '-t', base_path('public')], null, ['PHP_CLI_SERVER_WORKERS' => '4']);
        $this->server->setWorkingDirectory(base_path());
        $this->server->start();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $this->port)) {
                return;
            }
            usleep(50_000);
        }
        $this->fail('Local PHP server did not start in time. stderr: '.$this->server->getErrorOutput());
    }

    private function curl(string $url, string $token, string $key, string $body): Process
    {
        $process = new Process(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-X', 'POST', $url,
            '-H', "Authorization: Bearer {$token}", '-H', "Idempotency-Key: {$key}", '-H', 'Content-Type: application/json', '-H', 'Accept: application/json', '-d', $body]);
        $process->start();

        return $process;
    }
}
