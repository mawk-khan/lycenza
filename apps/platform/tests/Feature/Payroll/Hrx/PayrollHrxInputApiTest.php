<?php

namespace Tests\Feature\Payroll\Hrx;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.10, §26.12): the run's HRX-input API is Payroll
 * administration (`payroll.runs.prepare`), `private-no-store`; the
 * difference check is idempotent; nobody else -- self-service, teacher,
 * run viewers -- reaches it; another School's run is the 404.
 */
class PayrollHrxInputApiTest extends TestCase
{
    use CreatesPayrollHrxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function as(User $user, ?string $key = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());
    }

    #[Test]
    public function only_payroll_preparers_read_and_check_and_the_check_replays_without_a_second_audit(): void
    {
        $w = $this->hrxWorld();
        $leave = $this->approvedLeaveOf($w, '2026-09-21', '2026-09-21', $w['unpaid']);
        $runId = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'posted');
        app(LeaveRequestService::class)->cancel($w['school'], $leave->id, 'plans_changed', $w['admin']);
        $base = "/api/v1/schools/{$w['school']->id}/payroll-runs/{$runId}/hrx-inputs";
        $preparer = $this->createUserWithCapabilities($w['school'], ['payroll.runs.prepare']);

        $this->as($preparer)->getJson($base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.differenceState', 'source_changed_after_approval')->assertJsonPath('data.payrollPolicy', 'pending_hrx_l4')
            ->assertJsonPath('data.inputs.0.units.approvedUnpaidLeaveHalfUnits', 2)->assertJsonPath('data.inputs.0.current.units.approvedUnpaidLeaveHalfUnits', 0)
            ->assertJsonMissingPath('data.inputs.0.reason')->assertJsonMissingPath('data.inputs.0.evidence');

        $key = (string) Str::uuid();
        $this->as($preparer, $key)->postJson("{$base}/difference-checks")->assertOk()->assertJsonPath('data.changed', 1);
        $this->as($preparer, $key)->postJson("{$base}/difference-checks")->assertOk()->assertJsonPath('data.changed', 1);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'payroll.hrx_input.difference_detected')->count()), 'a replay records nothing twice');

        foreach ([['payroll.runs.view'], ['payroll.compensation.sensitive.view'], self::SELF_CAPABILITIES, ['attendance.teacher', 'curriculum.delivery.teacher'], ['hr.staff_attendance.view', 'hr.leave.view']] as $capabilities) {
            $other = $this->createUserWithCapabilities($w['school'], $capabilities);
            $this->as($other)->getJson($base)->assertForbidden();
            $this->as($other)->postJson("{$base}/difference-checks")->assertForbidden();
        }

        $foreign = $this->hrxWorld();
        $foreignRun = $this->payrollRun($foreign['school'], [$foreign['employment']], '2026-09-01', 'calculated');
        $this->as($preparer)->getJson("/api/v1/schools/{$w['school']->id}/payroll-runs/{$foreignRun}/hrx-inputs")->assertNotFound();
        $this->as($preparer)->getJson("/api/v1/schools/{$w['school']->id}/payroll-runs/not-a-uuid/hrx-inputs")->assertNotFound();
        $this->as($preparer)->getJson("/api/v1/schools/{$w['school']->id}/my/payslips")->assertForbidden();
    }

    #[Test]
    public function an_unposted_run_says_recalculate_and_own_payslips_never_show_hrx_inputs(): void
    {
        $w = $this->hrxWorld();
        $me = $this->selfMember($w);
        $runId = $this->payrollRun($w['school'], [$me['employment']], '2026-09-01', 'calculated');
        $this->recordAttendance($w, '2026-09-22', 'absent', null, $me['employment']);
        $preparer = $this->createUserWithCapabilities($w['school'], ['payroll.runs.prepare']);

        $this->as($preparer)->getJson("/api/v1/schools/{$w['school']->id}/payroll-runs/{$runId}/hrx-inputs")->assertOk()
            ->assertJsonPath('data.frozen', false)->assertJsonPath('data.differenceState', 'recalculate_to_refresh');

        $posted = $this->payrollRun($w['school'], [], '2026-10-01', 'posted', compensate: false);
        $payslip = $this->as($me['user'])->getJson("/api/v1/schools/{$w['school']->id}/my/payslips/{$posted}/{$me['employment']->id}")->assertOk()->json('data');
        foreach (['fingerprint', 'hrx', 'hrxInputs', 'absence', 'ncp', 'lossOfPay'] as $key) {
            $this->assertArrayNotHasKey($key, $payslip, "own payslips expose no {$key}");
        }
    }
}
