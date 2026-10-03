<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRX.2 (ADR 0065 §23.6, §23.14): the manager's leave approvals page.
 *
 * - It needs `hr.leave.approve`.
 * - It lists ONLY the acting Employee's current direct reports' requests,
 *   resolved server-side by LeaveRequestReadService (HR's ReportingLine).
 *   Rows are never filtered in Vue.
 * - Any other request is the private 404.
 * - Decisions go through LeaveRequestService's manager path, which
 *   re-checks capability AND fresh ownership inside its transaction.
 */
class LeaveApprovalsController extends Controller
{
    use AuthorizesCapability, LeavePageSupport;

    public function index(TenantContext $context, LeaveReadService $reads, LeaveRequestReadService $requests): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::APPROVE, $school);
        $rows = $requests->managed($school, $context->actor());

        return Inertia::render('App/Leave/Approvals', [
            'requests' => $rows,
            'employees' => collect($this->employments($context, $school, array_values(array_unique(array_column($rows, 'employmentRecordId')))))->keyBy('employmentRecordId')->all(),
            'types' => $reads->typeNames($school, $context->actor()),
            'rejectionReasons' => LeaveDecision::REJECTION_REASONS,
        ]);
    }

    public function decide(Request $request, TenantContext $context, LeaveRequestService $service, string $leaveRequest, string $action): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::APPROVE, $school);
        abort_if(! Str::isUuid($leaveRequest) || ! in_array($action, ['approve', 'reject'], true), 404);
        $reason = $action === 'reject' ? $request->validate(['reason_code' => ['required', Rule::in(LeaveDecision::REJECTION_REASONS)]])['reason_code'] : null;

        try {
            $action === 'approve'
                ? $service->approveAsManager($school, $leaveRequest, $request->user())
                : $service->rejectAsManager($school, $leaveRequest, (string) $reason, $request->user());
        } catch (ModelNotFoundException) {
            // Not a current direct report's request: the private 404, never a form error that confirms it exists.
            abort(404);
        } catch (LeaveException $e) {
            throw ValidationException::withMessages(['leave' => "{$e->getMessage()} ({$e->errorCode()})"]);
        }

        return back()->with('status', 'Leave request decided.');
    }
}
