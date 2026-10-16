<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeePickerReadService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAlreadyLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeNotActiveException;
use App\Domain\HR\Application\Exceptions\EmployeeNotLinkedException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Application\Exceptions\UserAlreadyLinkedException;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $this->authorizeCapability('hr.employees.manage', $school);

        return response()->json([
            'candidates' => $reads->accountCandidates($school, $this->employee($employee), (string) $request->string('q'), $context->actor()),
        ]);
    }

    public function store(Request $request, TenantContext $context, EmployeeService $service, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hr.employees.manage', $school);
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
        $this->authorizeCapability('hr.employees.manage', $school);
        $model = $this->employee($employee);

        try {
            $service->unlinkUser($model, $context->actor());
        } catch (EmployeeNotLinkedException) {
            throw ValidationException::withMessages(['account' => ['This Employee is not linked to an account.']]);
        }

        return redirect("/app/hr/employees/{$model->id}")->with('flash', 'Account link removed.');
    }

    private function employee(string $employee): Employee
    {
        abort_if(! Str::isUuid($employee), 404);

        return Employee::query()->findOrFail($employee);
    }
}
