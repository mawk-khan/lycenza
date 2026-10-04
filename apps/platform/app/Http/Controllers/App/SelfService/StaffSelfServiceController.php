<?php

namespace App\Http\Controllers\App\SelfService;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Payroll\Application\PayslipPresenter;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceCapabilities;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRX.4 (ADR 0065 §25): the session-authenticated Staff Self-Service pages --
 * My Leave, My Attendance (read only), My Payslips.
 *
 * Each page needs its own `.self` capability; the services check it again
 * together with ActingEmployee ownership. A signed-in User whose
 * ActingEmployee cannot be resolved in this School sees an "unavailable"
 * state, never anyone's data; an unowned request or payslip is the private
 * 404. No administrative control is offered here.
 */
class StaffSelfServiceController extends Controller
{
    use AuthorizesCapability;

    private const PORTIONS = ['full', 'first_half', 'second_half'];

    public function leave(TenantContext $context, LeaveRequestReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::SELF, $school);

        try {
            $overview = $reads->ownOverview($school, $context->actor());
            $requests = $reads->own($school, $context->actor());
        } catch (ModelNotFoundException) {
            return Inertia::render('App/My/Leave', ['available' => false]);
        }

        return Inertia::render('App/My/Leave', [
            'available' => true,
            'overview' => $overview,
            'requests' => $requests,
            'requestReasons' => LeaveRequest::REASONS,
            'closingReasons' => LeaveDecision::CLOSING_REASONS,
            'portions' => self::PORTIONS,
        ]);
    }

    public function leaveRequest(TenantContext $context, LeaveRequestReadService $reads, string $leaveRequest): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::SELF, $school);
        abort_if(! Str::isUuid($leaveRequest), 404);

        return Inertia::render('App/My/LeaveRequest', [
            'request' => $reads->ownShow($school, $leaveRequest, $context->actor()),
            'types' => $reads->ownOverview($school, $context->actor())['types'],
        ]);
    }

    public function submitLeave(Request $request, TenantContext $context, LeaveRequestService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'start_portion' => ['required', Rule::in(self::PORTIONS)],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'end_portion' => ['required', Rule::in(self::PORTIONS)],
            'reason_code' => ['sometimes', 'nullable', Rule::in(LeaveRequest::REASONS)],
            // ADR 0065 §25.1: identity is ActingEmployee's, never the client's -- naming one is refused, not ignored.
            'employee_id' => ['prohibited'], 'employment_record_id' => ['prohibited'], 'school_id' => ['prohibited'], 'requester_user_id' => ['prohibited'], 'manager_id' => ['prohibited'],
        ]);

        return $this->command(fn () => $service->submitOwn($school, $validated['leave_type_id'], $validated['starts_on'], $validated['start_portion'],
            $validated['ends_on'], $validated['end_portion'], $validated['reason_code'] ?? null, $request->user()), 'Leave request submitted.');
    }

    public function decideLeave(Request $request, TenantContext $context, LeaveRequestService $service, string $leaveRequest, string $action): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($leaveRequest) || ! in_array($action, ['withdraw', 'cancel'], true), 404);
        $reason = $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::CLOSING_REASONS)]])['reason_code'];

        return $this->command(fn () => $action === 'withdraw'
            ? $service->withdrawOwn($school, $leaveRequest, $reason, $request->user())
            : $service->cancelOwn($school, $leaveRequest, $reason, $request->user()), $action === 'withdraw' ? 'Leave request withdrawn.' : 'Leave cancelled.');
    }

    public function attendance(Request $request, TenantContext $context, StaffAttendanceReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(StaffAttendanceCapabilities::SELF, $school);
        $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $validated = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $to = $validated['to'] ?? $today;
        $from = $validated['from'] ?? CarbonImmutable::createFromFormat('!Y-m-d', $to)->subDays(29)->toDateString();

        try {
            $own = $reads->own($school, $from, $to, $context->actor());
        } catch (ModelNotFoundException) {
            return Inertia::render('App/My/StaffAttendance', ['available' => false, 'today' => $today]);
        } catch (StaffAttendanceException $e) {
            throw ValidationException::withMessages(['attendance' => "{$e->getMessage()} ({$e->errorCode()})"]);
        }

        return Inertia::render('App/My/StaffAttendance', ['available' => true, 'attendance' => $own, 'today' => $today]);
    }

    public function payslips(TenantContext $context, PayslipReadService $payslips): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PayslipReadService::SELF, $school);

        try {
            return Inertia::render('App/My/Payslips', ['available' => true, 'payslips' => $payslips->ownPayslips($school, $context->actor())]);
        } catch (ModelNotFoundException) {
            return Inertia::render('App/My/Payslips', ['available' => false]);
        }
    }

    public function payslip(TenantContext $context, PayslipReadService $payslips, string $payrollRun, string $employmentRecord): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PayslipReadService::SELF, $school);
        abort_if(! Str::isUuid($payrollRun) || ! Str::isUuid($employmentRecord), 404);

        return Inertia::render('App/Payroll/Payslips/Show', [
            'payslip' => PayslipPresenter::present($payslips->renderOwn($school, $payrollRun, $employmentRecord, $context->actor())),
            'back' => ['href' => '/app/my-payslips', 'label' => 'My payslips'],
        ]);
    }

    /** Runs a command and redirects back; a domain refusal becomes a form error, an unowned id the private 404. */
    private function command(callable $command, string $success): RedirectResponse
    {
        try {
            $command();
        } catch (LeaveException $e) {
            throw ValidationException::withMessages(['leave' => "{$e->getMessage()} ({$e->errorCode()})"]);
        }

        return back()->with('status', $success);
    }
}
