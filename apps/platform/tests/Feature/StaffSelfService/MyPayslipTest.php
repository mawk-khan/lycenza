<?php

namespace Tests\Feature\StaffSelfService;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.7): own payslips through PayslipReadService's ownership
 * path -- posted runs only, the acting Employee's EmploymentRecords only,
 * checked on every access; anything else is one identical private 404.
 */
class MyPayslipTest extends TestCase
{
    use CreatesSelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(array $w, string $path): string
    {
        return "/api/v1/schools/{$w['school']->id}/my{$path}";
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['me'] = $this->selfMember($w);
        $w['colleague'] = $this->selfMember($w);
        $w['posted'] = $this->payrollRun($w['school'], [$w['me']['employment'], $w['colleague']['employment']], '2026-08-01', 'posted');
        $w['approved'] = $this->payrollRun($w['school'], [], '2026-09-01', 'approved', compensate: false);

        return $w;
    }

    #[Test]
    public function the_list_shows_only_my_payslips_of_posted_runs_without_amounts(): void
    {
        $w = $this->world();

        $list = $this->as($w['me']['user'])->getJson($this->url($w, '/payslips'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertCount(1, $list, 'the approved-not-posted September run is not a self-service payslip');
        $this->assertSame([$w['posted'], $w['me']['employment']->id, '2026-08-01'], [$list[0]['payrollRunId'], $list[0]['employmentRecordId'], $list[0]['periodMonth']]);
        $this->assertSame(['employmentRecordId', 'isReversed', 'paymentDate', 'payrollRunId', 'periodMonth', 'postedAt', 'runKind'], collect($list[0])->keys()->sort()->values()->all(), 'no amount in the list');
    }

    #[Test]
    public function i_view_my_posted_payslip_and_the_access_is_audited_as_self_viewed(): void
    {
        $w = $this->world();

        $payslip = $this->as($w['me']['user'])->getJson($this->url($w, "/payslips/{$w['posted']}/{$w['me']['employment']->id}"))->assertOk()->json('data');
        $this->assertSame(['posted', '40000.00', '40000.00'], [$payslip['runStatus'], $payslip['grossAmount'], $payslip['netAmount']]);
        $this->assertSame($w['me']['employment']->employee_id, $payslip['employeeId']);
        $this->assertArrayHasKey('statutory', $payslip);
        $this->inSchool($w['school'], function () use ($w) {
            $this->assertSame(1, DB::table('school_audit_events')->where('event_type', 'payroll.payslip.self_viewed')->where('actor_user_id', $w['me']['user']->id)->count());
            $this->assertSame(0, DB::table('school_audit_events')->where('event_type', 'payroll.payslip.viewed')->count(), 'the administrative event is not emitted for a self view');
        });
    }

    #[Test]
    public function another_employees_unposted_unknown_or_foreign_payslip_is_one_identical_404(): void
    {
        $w = $this->world();
        $other = $this->attendanceWorld();
        $otherMember = $this->selfMember($other);
        $foreignRun = $this->payrollRun($other['school'], [$otherMember['employment']]);
        $mine = $w['me']['employment']->id;

        $unknown = $this->as($w['me']['user'])->getJson($this->url($w, '/payslips/'.Str::uuid().'/'.$mine))->assertNotFound()->json('error.message');
        foreach ([
            "/payslips/{$w['posted']}/{$w['colleague']['employment']->id}",   // a colleague's payslip in a run I am paid in
            "/payslips/{$w['approved']}/{$mine}",                             // my own, but the run is only approved
            "/payslips/{$foreignRun}/{$mine}",                                // another School's run
            "/payslips/{$foreignRun}/{$otherMember['employment']->id}",       // another School's employment
            '/payslips/not-a-uuid/'.$mine,
        ] as $path) {
            $this->assertSame($unknown, $this->as($w['me']['user'])->getJson($this->url($w, $path))->assertNotFound()->json('error.message'), $path);
        }
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'payroll.payslip.self_viewed')->count()), 'nothing unowned was rendered');
    }

    #[Test]
    public function capability_without_ownership_and_ownership_without_capability_are_both_refused(): void
    {
        $w = $this->world();
        $noEmployee = $this->createUserWithCapabilities($w['school'], ['payroll.payslips.self']);
        $noCapability = $this->staffMember($w['school'], ['hr.leave.self', 'hr.staff_attendance.self']);
        $payrollAdmin = $this->createUserWithCapabilities($w['school'], ['payroll.compensation.sensitive.view', 'payroll.runs.prepare']);

        $this->as($noEmployee)->getJson($this->url($w, '/payslips'))->assertNotFound();
        $this->as($noEmployee)->getJson($this->url($w, "/payslips/{$w['posted']}/{$w['me']['employment']->id}"))->assertNotFound();
        $this->as($noCapability['user'])->getJson($this->url($w, '/payslips'))->assertForbidden();
        $this->as($payrollAdmin)->getJson($this->url($w, '/payslips'))->assertForbidden();
        // The administrative payslip path is unchanged and not opened by the self capability.
        $this->as($w['me']['user'])->getJson("/api/v1/schools/{$w['school']->id}/payroll-runs/{$w['posted']}/payslips/{$w['me']['employment']->id}")->assertForbidden();
    }
}
