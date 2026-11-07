<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeePickerReadService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAlreadyLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeNotActiveException;
use App\Domain\HR\Application\Exceptions\EmployeeNotLinkedException;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Application\Exceptions\UserAlreadyLinkedException;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * UAT-C6 (UAT-STF-05, ADR 0075 section 19): the browser face of the
 * governed Employee <-> User link (TCH.1, ADR 0063 section 6). Every write is
 * `EmployeeService::linkUser()` / `unlinkUser()` -- the same path as the
 * JSON API: `hr.employees.manage`, never yourself, an ACTIVE same-School
 * membership, an enabled User, an active and unlinked Employee, one Employee
 * per User (database-enforced), locks and audit. Relinking is never
 * implicit: the current link is removed first. Refusals are form messages
 * without identifiers.
 */
class HrEmployeeAccountLinkController extends Controller
{
    use AuthorizesCapability;

    public function search(Request $request, TenantContext $context, EmployeePickerReadService $reads, string $employee): JsonResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.manage` floor, which a Campus grant never satisfies.

        return response()->json([
            'candidates' => $reads->accountCandidates($school, $this->employee($employee), (string) $request->string('q'), $context->actor()),
        ]);
    }

    public function store(Request $request, TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.manage` floor, which a Campus grant never satisfies.
        $model = $this->employee($employee);

        $validated = $request->validate(['account' => ['required', 'uuid']], ['account.*' => 'Choose a Lycenza account from the list.']);

        try {
            $service->linkUser($model, $validated['account'], $context->actor());
        } catch (UnrelatedUserLinkageException) {
            throw ValidationException::withMessages(['account' => ['That account is not an active member of this School.']]);
        } catch (UserAlreadyLinkedException) {
            throw ValidationException::withMessages(['account' => ['That account is already linked to another Employee of this School.']]);
        } catch (EmployeeAlreadyLinkedException) {
            throw ValidationException::withMessages(['account' => ['This Employee is already linked to an account. Remove that link first.']]);
        } catch (EmployeeNotActiveException) {
            throw ValidationException::withMessages(['account' => ['An archived Employee cannot be linked to an account.']]);
        }

        return redirect("/app/hr/employees/{$model->id}")->with('flash', 'Account linked.');
    }

    public function destroy(TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7E-1 (ADR 0076 §43): a declared whole-School operation -- authorized by
        // the route's ordinary `capability:hr.employees.manage` floor, which a Campus grant never satisfies.
        $model = $this->employee($employee);

        try {
            $service->unlinkUser($model, $context->actor());
        } catch (EmployeeNotLinkedException) {
            throw ValidationException::withMessages(['account' => ['This Employee is not linked to an account.']]);
        }

        return redirect("/app/hr/employees/{$model->id}")->with('flash', 'Account link removed.');
    }

    /** HR.R1-C8 (OD-HR-25 as amended): a request changes nothing. Another linked staff member decides. */
    public function requestCorrection(Request $request, TenantContext $context, EmployeeAccountLinkCorrectionService $corrections, string $employee, string $accountLink): RedirectResponse
    {
        $context->requireSchool();
        $model = $this->employee($employee);
        $validated = $request->validate(['reason' => ['required', Rule::enum(EmployeeAccountLinkVoidReason::class)]]);
        try {
            $corrections->request($model, $accountLink, EmployeeAccountLinkVoidReason::from($validated['reason']), $context->actor());
        } catch (HrException $e) {
            throw ValidationException::withMessages(['account' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$model->id}")->with('flash', 'Correction requested. Nothing changes until another person approves it.');
    }

    /** The route's floor, then a fresh code; the service re-reads authority under its locks. */
    public function decideCorrection(Request $request, TenantContext $context, EmployeeAccountLinkCorrectionService $corrections, FreshMfaRequirement $mfa, string $employee, string $accountLinkCorrection): RedirectResponse
    {
        $school = $context->requireSchool();
        $model = $this->employee($employee);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'refuse'])],
            'reviewed_version' => ['required_if:decision,approve', 'nullable', 'string', 'size:64'],
            'confirm' => ['accepted_if:decision,approve'],
        ]);
        abort_if(! Str::isUuid($accountLinkCorrection), 404);
        $proof = $mfa->requireForAction($request, $context->actor());
        try {
            if ($validated['decision'] === 'approve') {
                $corrections->approve($school, $accountLinkCorrection, $validated['reviewed_version'], $context->actor(), $proof);
            } else {
                $corrections->refuse($school, $accountLinkCorrection, $context->actor(), $proof);
            }
        } catch (HrException $e) {
            throw ValidationException::withMessages(['account' => [$e->getMessage()]]);
        }

        return redirect("/app/hr/employees/{$model->id}")->with('flash', $validated['decision'] === 'approve' ? 'Account link corrected.' : 'Correction refused. The link stands.');
    }

    private function employee(string $employee): Employee
    {
        abort_if(! Str::isUuid($employee), 404);

        return Employee::query()->findOrFail($employee);
    }
}
