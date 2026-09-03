<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Models\Role;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6K -- completes the final concurrency matrix: ESI
 * coverage-state concurrency was reported as structurally-covered-but-
 * not-independently-race-tested in 9.6H (no write path existed then).
 * `EmployeeEsiCoverageAdminService::correct()` (Checkpoint 9.6I) now
 * gives it a genuine write path, so this is the real two-process
 * proof, mirroring `StatutoryPfStatusMutationConcurrencyTest`'s exact
 * shape and reasoning: no state-machine transition to lose against
 * here, so both concurrent corrections succeed (serialized by the
 * row lock) and the proof is "never a torn mixed state," not "one
 * loses."
 */
class StatutoryEsiCoverageMutationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private ?Carbon $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now();
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->school->delete();
            } catch (\Throwable) {
                // Best-effort only.
            }
        }

        if ($this->startedAt !== null) {
            Role::query()->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_processes_correcting_the_same_esi_coverage_period_never_produce_a_torn_mixed_state(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $employmentRecord = $context->withSchool($this->school, fn () => $this->createEmploymentRecord($this->createEmployee($this->school)));
        $actorA = $this->createUserWithCapabilities($this->school, ['payroll.statutory.manage']);
        $actorB = $this->createUserWithCapabilities($this->school, ['payroll.statutory.manage']);

        $script = __DIR__.'/../../../Support/correct-esi-coverage.php';
        $processA = new Process(['php', $script, $this->school->id, $employmentRecord->id, $actorA->id, '2026-04-01', '2026-09-30', '18000.00', 'true']);
        $processB = new Process(['php', $script, $this->school->id, $employmentRecord->id, $actorB->id, '2026-04-01', '2026-09-30', '25000.00', 'false']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        foreach ($outputs as $output) {
            $this->assertStringStartsWith('corrected:', $output, 'Both concurrent correct() calls must succeed (no state-machine transition to lose against), got: '.implode(', ', $outputs));
        }

        $final = $context->withSchool($this->school, fn () => EmployeeEsiCoverage::query()->where('employment_record_id', $employmentRecord->id)->where('period_start', '2026-04-01')->firstOrFail());

        $matchesA = $final->entry_wage === '18000.00' && $final->is_covered === true;
        $matchesB = $final->entry_wage === '25000.00' && $final->is_covered === false;

        $this->assertTrue(
            $matchesA xor $matchesB,
            'The final row must match EXACTLY ONE of the two complete payloads -- never a torn mix of fields from both concurrent writers.',
        );

        $count = $context->withSchool($this->school, fn () => EmployeeEsiCoverage::query()->where('employment_record_id', $employmentRecord->id)->count());
        $this->assertSame(1, $count, 'Exactly one coverage row must exist for this period -- no duplicate row from the race.');
    }
}
