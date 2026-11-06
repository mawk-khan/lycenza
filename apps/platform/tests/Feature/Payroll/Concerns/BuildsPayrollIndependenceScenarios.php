<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliationReview;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** HR.R1-C6: X authors a change; a checker approves one run, X approves a later one; synthetic, every date in 2015. */
trait BuildsPayrollIndependenceScenarios
{
    protected School $school;

    /** @var array<string, mixed> */
    protected array $setup;

    protected Employee $employee;

    protected EmploymentRecord $record;

    protected User $preparer;

    protected User $checker;

    protected User $x;

    protected User $finance;

    protected User $poster;

    protected User $reviewer;

    protected function buildWorld(): void
    {
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $this->employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail());
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage', 'payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.approve']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view', 'payroll.runs.post']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view', 'payroll.runs.approve', 'payroll.compensation.sensitive.view']);
        $this->poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
    }

    protected function calculatedRun(string $month): PayrollRun
    {
        $this->travelTo(Carbon::parse($month)->addDays(24)->setTime(6, 0));

        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    protected function approve(PayrollRun $run, User $as): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($run, $as): PayrollRun {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();

            return $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
        });
    }

    protected function postRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
    }

    protected function reverseRun(PayrollRun $run, ?User $as = null): void
    {
        $as ??= $this->finance;
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $as, $this->freshMfaProofFor($as), 'test'));
    }

    protected function raise(string $effectiveFrom, string $amount, ?User $by = null, ?EmploymentRecord $record = null): EmployeeCompensationAssignment
    {
        $by ??= $this->x;

        return $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, ($record ?? $this->record)->fresh(), $this->setup['structure'], Carbon::parse($effectiveFrom),
            [new FixedComponentValueInput($this->setup['line'], $amount)], $by, $this->freshMfaProofFor($by),
        ));
    }

    /** October, independently approved and posted, discharges X's change. X approves and posts November. */
    protected function octoberAndPostedNovember(): array
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);

        return [$october, $november];
    }

    protected function obligation(PayrollRun $run): PayrollReversalReconciliation
    {
        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('payroll_run_id', $run->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail());
    }

    protected function obligations(?PayrollRun $run = null): int
    {
        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('school_id', $this->school->id)
            ->when($run !== null, fn ($q) => $q->where('payroll_run_id', $run->id))->count());
    }

    protected function reviews(): int
    {
        return $this->inSchool($this->school, fn () => PayrollReversalReconciliationReview::query()->where('school_id', $this->school->id)->count());
    }

    protected function reviewVersion(PayrollReversalReconciliation $obligation): string
    {
        return $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)->version($obligation));
    }

    protected function reviewAs(PayrollReversalReconciliation $obligation, User $as, ?string $version = null, bool $accepted = true): PayrollReversalReconciliationReview
    {
        $version ??= $this->reviewVersion($obligation);

        return app(PayrollReversalReconciliationService::class)->review($this->school, $obligation->id, $as, $this->freshMfaProofFor($as), $version, $accepted);
    }

    /** @return array<string, mixed> */
    protected function runRow(PayrollRun $run): array
    {
        return (array) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)
            ->first(['status', 'approved_by_user_id', 'approved_at', 'approved_calculation_id', 'posted_by_user_id', 'posted_at', 'prepared_by_user_id']));
    }

    /** @return list<array<string, mixed>> */
    protected function audit(string $type, PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->where('subject_id', $run->id)->get()->map(fn ($e) => $e->metadata)->all());
    }

    protected function link(User $user, ?Employee $employee = null): void
    {
        $this->inSchool($this->school, fn () => Employee::query()->whereKey(($employee ?? $this->employee)->id)->update(['user_id' => $user->id]));
    }
}
