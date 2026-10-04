<?php

namespace Tests\Feature\App;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.10): the payroll run page shows the captured HRX
 * absence evidence -- units, completeness and a source-changed state -- to
 * payroll preparers only, and offers a difference check, never a
 * recalculation or overwrite of a posted run.
 */
class PayrollHrxInputUiTest extends TestCase
{
    use CreatesPayrollHrxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function preparers_see_the_evidence_and_a_changed_source_after_posting_and_others_see_nothing(): void
    {
        $w = $this->hrxWorld();
        $leave = $this->approvedLeaveOf($w, '2026-09-21', '2026-09-21', $w['unpaid']);
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'posted');
        app(LeaveRequestService::class)->cancel($w['school'], $leave->id, 'plans_changed', $w['admin']);
        $preparer = $this->createUserWithCapabilities($w['school'], ['payroll.runs.view', 'payroll.runs.prepare']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['payroll.runs.view']);

        $this->actor($w, $preparer)->get("/app/payroll/runs/{$runId}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Payroll/Runs/Show')->where('hrxInputs.differenceState', 'source_changed_after_approval')
            ->where('hrxInputs.payrollPolicy', 'pending_hrx_l4')->where('hrxInputs.inputs.0.units.approvedUnpaidLeaveHalfUnits', 2));
        $this->actor($w, $viewer)->get("/app/payroll/runs/{$runId}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('hrxInputs', null));

        $this->actor($w, $preparer)->post("/app/payroll/runs/{$runId}/hrx-inputs/difference-checks")->assertRedirect("/app/payroll/runs/{$runId}");
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'payroll.hrx_input.difference_detected')->count()));
        $this->actor($w, $viewer)->post("/app/payroll/runs/{$runId}/hrx-inputs/difference-checks")->assertForbidden();
        $this->assertSame('posted', $this->inSchool($w['school'], fn () => DB::table('payroll_runs')->where('id', $runId)->value('status')));
    }
}
