<?php

namespace Tests\Feature\Idempotency;

use App\Models\ApiIdempotencyKey;
use App\Models\PlatformIdempotencyDemoCounter;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0C.2 section 10/38): two
 * GENUINELY separate OS processes issuing GENUINELY parallel HTTP
 * requests (via `curl`, launched with Process::start() -- not run(),
 * so neither blocks the other -- against a REAL `php -S` server
 * subprocess, itself a separate process from this test) with the
 * identical Idempotency-Key/body/actor/School must still produce
 * exactly ONE underlying side effect. This is not a sequential
 * simulation of concurrency -- it is real concurrency, backed by real
 * PostgreSQL's unique-constraint/row-locking guarantees
 * (App\Support\Idempotency\IdempotencyGuard), mirroring
 * tests/Feature/Webhooks/EndToEndProofBTest.php's real local-HTTP
 * pattern.
 *
 * Deliberately does NOT use DatabaseTransactions (see
 * $connectionsToTransact below): the two curl subprocesses and the
 * `php -S` server subprocess are all separate PostgreSQL sessions, and
 * a session can never see another session's uncommitted rows -- the
 * fixtures this test creates must be REAL, COMMITTED rows for the
 * subprocesses to see them at all. Fixtures are cleaned up explicitly
 * in tearDown() instead of via transaction rollback.
 */
class IdempotencyRealConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?Process $server = null;

    private ?School $school = null;

    private ?User $user = null;

    private int $port = 18201;

    protected function tearDown(): void
    {
        $this->server?->stop();

        if ($this->user !== null) {
            $this->user->tokens()->delete();
        }
        // School delete cascades memberships/role assignments/audit
        // events/idempotency records/the demo counter (all school_id
        // foreign keys are cascadeOnDelete). See each table's migration.
        $this->deleteSchoolAsAdmin($this->school);
        $this->user?->delete();

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_requests_with_the_same_key_produce_exactly_one_side_effect(): void
    {
        [$this->user, $this->school] = $this->createSchoolAdmin('school_admin');
        $token = $this->user->createToken('concurrency-test')->plainTextToken;

        $this->startServer();

        $url = "http://127.0.0.1:{$this->port}/api/v1/schools/{$this->school->id}/idempotency-demo/increment";

        $requestA = $this->startCurl($url, $token, 'race-key');
        $requestB = $this->startCurl($url, $token, 'race-key');

        $requestA->wait();
        $requestB->wait();

        $codeA = (int) trim($requestA->getOutput());
        $codeB = (int) trim($requestB->getOutput());

        // Both requests must have been handled safely -- either as the
        // one genuine execution (200), a completed replay (200), or a
        // refusal because the original claim was still in flight (409)
        // -- never a server error, and never a second execution.
        $this->assertContains($codeA, [200, 409], "Unexpected status from request A: {$codeA}. stderr: {$requestA->getErrorOutput()}");
        $this->assertContains($codeB, [200, 409], "Unexpected status from request B: {$codeB}. stderr: {$requestB->getErrorOutput()}");
        $this->assertContains(200, [$codeA, $codeB], 'At least one request must have succeeded.');

        $context = app(TenantContext::class);
        $context->set($this->school);

        $counterValue = PlatformIdempotencyDemoCounter::query()->find($this->school->id)?->value;
        $this->assertSame(1, $counterValue, 'The demonstration counter must have been incremented exactly once.');

        $completedRecords = ApiIdempotencyKey::query()
            ->where('idempotency_key', 'race-key')
            ->where('status', 'completed')
            ->count();
        $this->assertSame(1, $completedRecords, 'Exactly one idempotency record must have reached completed.');

        $auditCount = SchoolAuditEvent::query()
            ->where('event_type', 'platform.idempotency_demo.counter_incremented')
            ->count();
        $this->assertSame(1, $auditCount, 'The business audit event must not be duplicated.');

        $context->clearAll();
    }

    private function startServer(): void
    {
        $publicPath = base_path('public');

        $this->server = new Process(['php', '-S', "127.0.0.1:{$this->port}", '-t', $publicPath]);
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

    private function startCurl(string $url, string $token, string $idempotencyKey): Process
    {
        $process = new Process([
            'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}',
            '-X', 'POST', $url,
            '-H', "Authorization: Bearer {$token}",
            '-H', "Idempotency-Key: {$idempotencyKey}",
            '-H', 'Content-Type: application/json',
            '-d', '{}',
        ]);
        $process->start();

        return $process;
    }
}
