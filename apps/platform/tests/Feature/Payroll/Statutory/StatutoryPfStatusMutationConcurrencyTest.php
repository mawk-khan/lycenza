<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Models\Role;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6I Section 6 "PF membership / higher-wage mutation" --
 * REQUIRED real-concurrency proof, now that
 * `EmployeePfStatusAdminService::configure()` gives this resource a
 * genuine write path (it had none before this checkpoint, hence
 * "N/A this checkpoint" in 9.6H's own matrix).
 *
 * Design (documented in `EmployeePfStatusAdminService`'s own docblock):
 * there is no state-machine transition here to reject a loser against
 * (unlike a PayrollRun's status) -- `configure()`'s row lock
 * serializes two concurrent full-row writes so the final state is
 * ALWAYS exactly one caller's complete payload, never a torn mix of
 * both (e.g. never `hasUan` from process A combined with
 * `isEpsEligible` from process B). This test proves that guarantee
 * directly: two real OS processes submit two DIFFERENT, mutually
 * exclusive payloads; both must succeed (deterministic, no
 * unnecessary rejection), and the persisted row must match ONE of
 * them entirely.
 */
class StatutoryPfStatusMutationConcurrencyTest extends TestCase
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
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // Best-effort only.
            }
        }

        // createUserWithCapabilities() creates a throwaway
        // 'test.capability_grant.*' Role -- this test's own
        // $connectionsToTransact = [] means it is never rolled back
        // like an ordinary test's fixtures are, so it must be cleaned
        // up explicitly or it permanently pollutes the shared roles
        // table (PayrollCapabilityRegistryTest's "nobody by default"
        // scan would otherwise see it forever after).
        if ($this->startedAt !== null) {
            // SR.1: the runtime role cannot write the catalogue; the admin role removes the fixture role.
            Role::on('pgsql_admin')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_processes_configuring_the_same_pf_status_row_never_produce_a_torn_mixed_state(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $employmentRecord = $context->withSchool($this->school, fn () => $this->createEmploymentRecord($this->createEmployee($this->school)));
        $actorA = $this->createUserWithCapabilities($this->school, ['payroll.statutory.manage']);
        $actorB = $this->createUserWithCapabilities($this->school, ['payroll.statutory.manage']);

        $payloadA = [
            'hasExistingPfMembership' => true, 'hasUan' => true, 'hasApprovedHigherWageContribution' => false,
            'higherWageApprovalReference' => null, 'higherWageApprovalEffectiveFrom' => null,
            'isEpsEligible' => true, 'hasHigherPensionStatus' => false,
        ];
        $payloadB = [
            'hasExistingPfMembership' => false, 'hasUan' => false, 'hasApprovedHigherWageContribution' => true,
            'higherWageApprovalReference' => 'HWC-CONCURRENCY-TEST', 'higherWageApprovalEffectiveFrom' => '2026-04-01',
            'isEpsEligible' => false, 'hasHigherPensionStatus' => true,
        ];

        $script = __DIR__.'/../../../Support/configure-pf-status.php';
        $processA = new Process(['php', $script, $this->school->id, $employmentRecord->id, $actorA->id, json_encode($payloadA)]);
        $processB = new Process(['php', $script, $this->school->id, $employmentRecord->id, $actorB->id, json_encode($payloadB)]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        foreach ($outputs as $output) {
            $this->assertStringStartsWith('configured:', $output, 'Both concurrent configure() calls must succeed (no state-machine transition to lose against), got: '.implode(', ', $outputs));
        }

        $final = $context->withSchool($this->school, fn () => EmployeePfStatus::query()->where('employment_record_id', $employmentRecord->id)->firstOrFail());

        $matchesA = $final->has_existing_pf_membership === $payloadA['hasExistingPfMembership']
            && $final->has_uan === $payloadA['hasUan']
            && $final->has_approved_higher_wage_contribution === $payloadA['hasApprovedHigherWageContribution']
            && $final->is_eps_eligible === $payloadA['isEpsEligible']
            && $final->has_higher_pension_status === $payloadA['hasHigherPensionStatus'];

        $matchesB = $final->has_existing_pf_membership === $payloadB['hasExistingPfMembership']
            && $final->has_uan === $payloadB['hasUan']
            && $final->has_approved_higher_wage_contribution === $payloadB['hasApprovedHigherWageContribution']
            && $final->is_eps_eligible === $payloadB['isEpsEligible']
            && $final->has_higher_pension_status === $payloadB['hasHigherPensionStatus'];

        $this->assertTrue(
            $matchesA xor $matchesB,
            'The final row must match EXACTLY ONE of the two complete payloads -- never a torn mix of fields from both concurrent writers.',
        );

        $count = $context->withSchool($this->school, fn () => EmployeePfStatus::query()->where('employment_record_id', $employmentRecord->id)->count());
        $this->assertSame(1, $count, 'Exactly one PF status row must exist -- no duplicate row from the race.');
    }
}
