<?php

namespace Tests\Feature\Idempotency;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
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
        $this->killStrayServers();
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
     * HR.R1-C35 WO-25 (WO22-N5): the two requests used to start together, so a stall anywhere before the trigger
     * (the server, either request's boot) and a real overlap failure both surfaced as one message. Now each phase
     * is proven by PostgreSQL, never by elapsed time, and fails with its own message and a diagnostic snapshot:
     * 1. request A starts, and is HELD only once a backend of this database waits on advisory lock 3030 (pg_locks);
     * 2. request B, a second real process and PostgreSQL session, starts only then and must be answered while A is
     *    still held. A held request is inside its worker's dispatch, so B is served by another worker.
     * The overall budget is unchanged: 25 s from A's start, under the 30 s in-flight timeout past which B could
     * reclaim A's claim. B now always meets A's committed claim through the lookup; the simultaneous-claim race (two
     * INSERTs on the unique key) may still be exercised, never forced, by the unheld retry race below and by
     * IdempotencyRealConcurrencyTest (it was only probabilistic before too; WO25-N1).
     *
     * @return list<int> both status codes, sorted
     */
    private function raceUnderBarrier(string $url, string $token, string $key, string $body): array
    {
        $admin = DB::connection('pgsql_admin');
        // Recheck N1: heldAtBarrier() reads another session's statement, which PostgreSQL shows only to a superuser or a
        // pg_read_all_stats member. Every environment's admin connection is superuser today; say so if that changes.
        if (! $admin->selectOne("select (select rolsuper from pg_roles where rolname = current_user) or pg_has_role('pg_read_all_stats', 'member') as sees")->sees) {
            $this->fail('The admin test connection cannot read other sessions\' statements (needs superuser or pg_read_all_stats); the barrier cannot be observed.');
        }
        $admin->select('select pg_advisory_lock(3030)');
        $start = microtime(true);
        $deadline = $start + 25;
        $a = $b = null;
        try {
            $a = $this->curl($url, $token, $key, $body);
            while (! $this->heldAtBarrier($admin)) {
                if (! $a->isRunning()) {
                    $this->fail($this->raceDiagnostics('request A returned before reaching the injected failure', $start, $admin, $a, $b));
                }
                if (microtime(true) > $deadline) {
                    $this->fail($this->raceDiagnostics('request A never reached the injected failure (harness: server or boot)', $start, $admin, $a, $b));
                }
                usleep(10_000);
            }
            $b = $this->curl($url, $token, $key, $body);
            while ($b->isRunning()) {
                if (microtime(true) > $deadline) {
                    $this->fail($this->raceDiagnostics('request B was not answered while A was held', $start, $admin, $a, $b));
                }
                usleep(10_000);
            }
            if (! $a->isRunning()) {
                $this->fail($this->raceDiagnostics('request A was no longer held when B was answered', $start, $admin, $a, $b));
            }
            if ((int) $b->getOutput() === 0) {
                $this->fail($this->raceDiagnostics('request B got no HTTP answer (harness: curl or server)', $start, $admin, $a, $b));
            }
            $this->assertSame(409, (int) $b->getOutput(), 'the request that returned first found the other in flight');
        } finally {
            $admin->select('select pg_advisory_unlock(3030)');
        }
        $a->wait();
        $b->wait();
        $codes = [(int) $a->getOutput(), (int) $b->getOutput()];
        sort($codes);

        return $codes;
    }

    /**
     * Whether a request is held at the injected failure: another client backend of this database waits on exactly the
     * single-key advisory lock 3030 (classid 0, objid 3030, objsubid 1) while running the INSERT the trigger guards.
     * Review R1: never another run's own pg_advisory_lock(3030), nor a 64-bit key whose low half happens to be 3030.
     */
    private function heldAtBarrier(Connection $admin): bool
    {
        return (bool) $admin->selectOne("select exists (
                select 1 from pg_locks l join pg_stat_activity a on a.pid = l.pid
                 where l.locktype = 'advisory' and l.classid = 0 and l.objid = 3030 and l.objsubid = 1 and not l.granted
                   and l.database = (select oid from pg_database where datname = current_database())
                   and a.backend_type = 'client backend' and a.pid <> pg_backend_pid() and a.query ilike 'insert into%'
            ) as held")->held;
    }

    /**
     * WO-25: what a stalled race was doing -- the phase, each request's state, every client backend of this database
     * (state, wait event, seconds in its statement, the statement's first 60 characters: the application's statements
     * are bound, so only a test fixture's literal could appear) and the server's last access-log lines (the requests
     * it accepted and answered, by client port).
     */
    private function raceDiagnostics(string $phase, float $start, Connection $admin, ?Process $a, ?Process $b): string
    {
        $state = fn (?Process $p) => $p === null ? 'not started' : ($p->isRunning() ? 'running' : 'returned '.$p->getOutput());
        $backends = $admin->select("select state, wait_event_type, wait_event, round(extract(epoch from now() - query_start)::numeric, 1) as seconds,
                left(regexp_replace(query, '\\s+', ' ', 'g'), 60) as query
            from pg_stat_activity where datname = current_database() and backend_type = 'client backend' and pid <> pg_backend_pid()");
        $log = array_slice(explode("\n", trim((string) $this->server?->getErrorOutput())), -12);

        return sprintf("%s after %.1f s; A %s; B %s.\nBackends: %s\nServer: %s", $phase, microtime(true) - $start, $state($a), $state($b),
            json_encode($backends), implode("\n", $log));
    }

    private function startServer(): void
    {
        // Review LOW-4: `php -S` serves one request at a time unless it has workers. The triggers wait on an advisory
        // lock the test holds (raceUnderBarrier), so the second request certainly arrives while the first is in flight.
        $this->killStrayServers(); // recheck N2: a stray server from a killed run never answers in this one's place
        $this->server = new Process(['php', '-S', "127.0.0.1:{$this->port}", '-t', base_path('public')], null, ['PHP_CLI_SERVER_WORKERS' => '4']);
        $this->server->setWorkingDirectory(base_path());
        $this->server->start();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $this->port)) {
                // WO-25: ready only once it has served a real application request (liveness), not merely accepted a socket.
                $live = new Process(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', "http://127.0.0.1:{$this->port}/api/health/live"]);
                $live->run();
                if ((int) $live->getOutput() !== 200) {
                    $this->fail('Local PHP server accepted a connection but did not serve liveness ('.$live->getOutput().'). stderr: '.$this->server->getErrorOutput());
                }
                // Review R3: and it is THIS server answering, not a stray one still bound to the port.
                if (! $this->server->isRunning()) {
                    $this->fail('Another process answers on port '.$this->port.'; this test\'s server exited. stderr: '.$this->server->getErrorOutput());
                }

                return;
            }
            usleep(50_000);
        }
        $this->fail('Local PHP server did not start in time. stderr: '.$this->server->getErrorOutput());
    }

    /**
     * Delta review M-T1: never leave a worker serving this port to a later run. WO-25: `pkill` does not exist in the
     * platform image, so that cleanup silently did nothing; this one reads /proc and needs no binary.
     */
    private function killStrayServers(): void
    {
        if (! function_exists('posix_kill')) {
            return; // the server's own stop() above remains the cleanup
        }
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($file));
            if (str_contains($cmd, "php -S 127.0.0.1:{$this->port} ")) {
                @posix_kill((int) basename(dirname($file)), 9);
            }
        }
    }

    private function curl(string $url, string $token, string $key, string $body): Process
    {
        $process = new Process(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-X', 'POST', $url,
            '-H', "Authorization: Bearer {$token}", '-H', "Idempotency-Key: {$key}", '-H', 'Content-Type: application/json', '-H', 'Accept: application/json', '-d', $body]);
        $process->start();

        return $process;
    }
}
