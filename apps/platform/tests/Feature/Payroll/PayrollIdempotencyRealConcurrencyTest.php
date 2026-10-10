<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\ApiIdempotencyKey;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 9.8 idempotency correction -- REQUIRED real-concurrency proof
 * (mirroring `Tests\Feature\Idempotency\IdempotencyRealConcurrencyTest`'s
 * exact pattern): two GENUINELY separate OS processes issuing GENUINELY
 * parallel HTTP `POST .../payroll-runs/{run}/post` requests (via curl,
 * `Process::start()`, against a real `php -S` server subprocess) with
 * the identical Idempotency-Key/actor/School must still produce exactly
 * ONE Finance `JournalEntry` and exactly one `original`
 * `PayrollRunPosting` row -- not a sequential simulation.
 *
 * Deliberately does NOT use DatabaseTransactions (see
 * $connectionsToTransact below): the curl subprocesses and the
 * `php -S` server subprocess are all separate PostgreSQL sessions, and
 * fixtures must be real, committed rows for them to see at all.
 */
class PayrollIdempotencyRealConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?Process $server = null;

    private ?School $school = null;

    private ?User $poster = null;

    /**
     * Phase 9.8 correction fix: `createUserWithCapabilities()` (needed
     * here, unlike `PayrollRunLifecycleConcurrencyTest`/
     * `CompensationConcurrencyTest`, because this test drives the real
     * HTTP `.../post` endpoint end-to-end and therefore needs actors
     * that genuinely pass `Gate::authorize()`) creates a real, COMMITTED
     * ad hoc `roles` row (a platform catalog table, not School-owned/
     * RLS-protected) for every actor. Because this test deliberately
     * runs outside a DatabaseTransactions wrapper
     * ($connectionsToTransact = []), those rows are never rolled back
     * and would otherwise leak into every other test in the same run
     * that enumerates `roles` (e.g. PayrollCapabilityRegistryTest's
     * "no default role receives X" assertions) -- captured here so
     * tearDown() can remove them explicitly.
     *
     * @var array<int, string>
     */
    private array $adHocRoleIds = [];

    private int $port = 18301;

    protected function tearDown(): void
    {
        $this->server?->stop();

        // School MUST be deleted before the poster User -- a posted
        // payroll_runs row's posted_by_user_id FK is restrictOnDelete(),
        // and only cascading the School away removes that row first.
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // Best-effort only, same rationale as
                // PayrollRunLifecycleConcurrencyTest::tearDown(): the
                // freeze triggers on payroll_run_results/_lines
                // correctly reject a cascade delete once the run is
                // posted. Leftover rows are harmless test-database
                // residue.
            }
        }

        // The ad hoc roles are a GLOBAL catalog table, not School-owned
        // -- School::delete()'s cascade never reaches them. Deleting
        // Role rows cascades to role_capabilities. SR.1 (ADR 0071): it runs
        // on the admin role (the runtime role cannot write the catalogue), and
        // a role with surviving grant history is now RESTRICTed -- the School
        // delete above removes those grants first.
        if ($this->adHocRoleIds !== []) {
            try {
                Role::on('pgsql_admin')->whereIn('id', $this->adHocRoleIds)->delete();
            } catch (\Throwable) {
                // Best-effort, same rationale as above.
            }
        }

        if ($this->poster !== null) {
            $this->poster->tokens()->delete();

            try {
                // E21.4 (F1): only the migration role can delete a User (test cleanup).
                DB::connection('pgsql_admin')->table('users')->where('id', $this->poster->id)->delete();
            } catch (\Throwable) {
                // Best-effort: if the School cascade above failed and
                // left payroll_runs referencing this user, leave it --
                // harmless test-database residue, same as above.
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_post_requests_with_the_same_key_produce_exactly_one_journal_entry(): void
    {
        $runId = $this->makeApprovedRun();
        $token = $this->mfaToken($this->poster, 'concurrency-test');
        // SR.4 (ADR 0071 §26.7): both racers send the IDENTICAL body, one fresh code.
        $this->body = json_encode($this->mfaBody($token), JSON_THROW_ON_ERROR);

        $this->startServer();

        $url = "http://127.0.0.1:{$this->port}/api/v1/schools/{$this->school->id}/payroll-runs/{$runId}/post";

        $requestA = $this->startCurl($url, $token, 'race-post-key');
        $requestB = $this->startCurl($url, $token, 'race-post-key');

        $requestA->wait();
        $requestB->wait();

        $codeA = (int) trim($requestA->getOutput());
        $codeB = (int) trim($requestB->getOutput());

        // Both requests must have been handled safely -- either as the
        // one genuine execution (201), a completed replay (201), or a
        // refusal because the original claim was still in flight (409)
        // -- never a server error, and never a second execution.
        $this->assertContains($codeA, [201, 409], "Unexpected status from request A: {$codeA}. stderr: {$requestA->getErrorOutput()}");
        $this->assertContains($codeB, [201, 409], "Unexpected status from request B: {$codeB}. stderr: {$requestB->getErrorOutput()}");
        $this->assertContains(201, [$codeA, $codeB], 'At least one request must have succeeded.');

        $context = app(TenantContext::class);
        $context->set($this->school);

        $journalEntryCount = JournalEntry::query()->where('school_id', $this->school->id)->count();
        $this->assertSame(1, $journalEntryCount, 'exactly one JournalEntry must exist -- no orphan from the losing/duplicate request.');

        $originalPostingCount = PayrollRunPosting::query()
            ->where('payroll_run_id', $runId)
            ->where('posting_kind', 'original')
            ->count();
        $this->assertSame(1, $originalPostingCount);

        $completedRecords = ApiIdempotencyKey::query()
            ->where('idempotency_key', 'race-post-key')
            ->where('status', 'completed')
            ->count();
        $this->assertSame(1, $completedRecords, 'exactly one idempotency record must have reached completed.');

        $context->clearAll();
    }

    private function makeApprovedRun(): string
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $context = app(TenantContext::class);

        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);
        $approver = $this->createUserWithCapabilities($school, ['payroll.runs.approve']);
        $this->poster = $this->createUserWithCapabilities($school, ['payroll.runs.post']);

        return $context->withSchool($school, function () use ($school, $structureManager, $runManager, $approver) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-IDEMP-RC', 'Grade Idempotency Real Concurrency', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $runService = app(PayrollRunAdministrationService::class);
            $run = $runService->createRun($period, $runManager);
            $runService->calculate($run, $runManager);
            $run = $runService->approve($run->fresh(), $approver);

            // See $adHocRoleIds's docblock: captured here, before the
            // fixture-building TenantContext scope closes, so tearDown()
            // can remove these ad hoc catalog rows explicitly.
            $this->adHocRoleIds = MembershipRoleAssignment::query()
                ->where('school_id', $school->id)
                ->whereHas('role', fn ($q) => $q->where('key', 'like', 'test.capability_grant.%'))
                ->pluck('role_id')
                ->all();

            return $run->id;
        });
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

    private string $body = '{}';

    private function startCurl(string $url, string $token, string $idempotencyKey): Process
    {
        $process = new Process([
            'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}',
            '-X', 'POST', $url,
            '-H', "Authorization: Bearer {$token}",
            '-H', "Idempotency-Key: {$idempotencyKey}",
            '-H', 'Content-Type: application/json',
            '-d', $this->body,
        ]);
        $process->start();

        return $process;
    }
}
