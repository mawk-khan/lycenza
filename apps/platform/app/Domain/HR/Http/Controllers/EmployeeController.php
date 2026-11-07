<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkHistory;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\Profile\EmployeeProfileVersion;
use App\Domain\HR\Application\Profile\ProfileContactNormalizer;
use App\Domain\HR\Infrastructure\Employee;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- the sole HTTP mutation
 * transport for the Employee core aggregate. Thin adapter over
 * `EmployeeService`, which already performs its own `hr.employees.*`
 * capability check against the real authenticated actor before doing
 * anything else -- this controller performs no capability logic of its
 * own beyond the route-level `capability:` middleware, mirroring
 * `EmployeeDirectoryController`/`EmployeeProfileController`'s
 * documented "no divergent capability matrix" decision (8A.14),
 * extended to mutations by this correction's item 9.
 *
 * `{employee}` is always a raw route-parameter string, resolved here
 * via a tenant-scoped `findOrFail()` (SchoolScope + TenantContext,
 * already established by the `school-membership` middleware for this
 * entire route group) -- never implicit Eloquent route-model binding.
 * A malformed (non-UUID) id is rejected as the same tenant-safe 404 a
 * genuinely nonexistent/cross-School Employee already produces (8A.15's
 * fix, applied consistently to every new HR mutation route this
 * correction adds).
 */
class EmployeeController extends Controller
{
    use AuthorizesCampusScope;

    // ORG.7E-1 (ADR 0076 §43): identity-only create (and its optional account
    // link) is a declared whole-School operation -- the route's ordinary
    // `capability:hr.employees.manage` floor; EmployeeService re-checks.
    public function store(Request $request, School $school, EmployeeService $service): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32', ProfileContactNormalizer::PHONE_RULE],
        ]);

        $employee = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($employee)], 201);
    }

    /**
     * ORG.7E-1 (ADR 0076 §43): Campus-adopted. A Campus-scoped actor reaches
     * only an Employee in scope (404 otherwise) and edits these School-wide
     * fields only with every current Campus in scope (decided under lock).
     */
    public function update(Request $request, School $school, string $employee, EmployeeService $service, HrEmployeeAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('hr.employees.manage', $school);
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $scope, $employee);

        // TCH.1 (ADR 0063 section 6): the User link is not an ordinary
        // field -- it changes only through linkUser()/unlinkUser() below.
        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'user_id' => ['prohibited'],
            // HR.2A: the version this client read (409 when stale, 422 when missing).
            'version' => ['required', 'string', 'size:64'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32', ProfileContactNormalizer::PHONE_RULE],
        ]);

        $version = (string) $validated['version'];
        unset($validated['version']);
        $updated = $service->update($model, $validated, $request->user(), $version);

        return response()->json(['data' => $this->present($updated, $scope->isWholeSchool())]);
    }

    // ORG.7E-1: account link/unlink and archive/restore are declared whole-School
    // operations (the routes' ordinary `capability:hr.employees.manage` floor).
    public function linkUser(Request $request, School $school, string $employee, EmployeeService $service, HrEmployeeAccess $access): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $linked = $service->linkUser($model, $validated['user_id'], $request->user());

        return response()->json(['data' => $this->present($linked)]);
    }

    public function accountLinks(Request $request, School $school, string $employee, EmployeeAccountLinkHistory $history, HrEmployeeAccess $access): JsonResponse
    {
        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);

        return response()->json(['data' => $history->forEmployee($model, $request->user())]);
    }

    public function requestAccountLinkCorrection(Request $request, School $school, string $employee, string $accountLink, EmployeeAccountLinkCorrectionService $corrections, EmployeeAccountLinkHistory $history, HrEmployeeAccess $access): JsonResponse
    {
        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);
        $validated = $request->validate(['reason' => ['required', Rule::enum(EmployeeAccountLinkVoidReason::class)]]);
        $corrections->request($model, $accountLink, EmployeeAccountLinkVoidReason::from($validated['reason']), $request->user());

        return response()->json(['data' => $history->forEmployee($model, $request->user())], 201);
    }

    public function decideAccountLinkCorrection(Request $request, School $school, string $employee, string $accountLinkCorrection, EmployeeAccountLinkCorrectionService $corrections, EmployeeAccountLinkHistory $history, HrEmployeeAccess $access, FreshMfaRequirement $mfa): JsonResponse
    {
        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);
        $validated = $request->validate(['decision' => ['required', Rule::in(['approve', 'refuse'])], 'reviewed_version' => ['required_if:decision,approve', 'nullable', 'string', 'size:64']]);
        $proof = $mfa->requireForAction($request, $request->user());
        if ($validated['decision'] === 'approve') {
            $corrections->approve($model, $accountLinkCorrection, $validated['reviewed_version'], $request->user(), $proof);
        } else {
            $corrections->refuse($model, $accountLinkCorrection, $request->user(), $proof);
        }

        return response()->json(['data' => $history->forEmployee($model, $request->user())]);
    }

    public function unlinkUser(Request $request, School $school, string $employee, EmployeeService $service, HrEmployeeAccess $access): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);
        $unlinked = $service->unlinkUser($model, $request->user());

        return response()->json(['data' => $this->present($unlinked)]);
    }

    public function archive(Request $request, School $school, string $employee, EmployeeService $service, HrEmployeeAccess $access): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);
        $archived = $service->archive($model, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    public function restore(Request $request, School $school, string $employee, EmployeeService $service, HrEmployeeAccess $access): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = $access->employee($school, $access->scopeOf($request->user(), 'hr.employees.manage', $school), $employee);
        $restored = $service->restore($model, $request->user());

        return response()->json(['data' => $this->present($restored)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Employee $employee, bool $wholeSchool = true): array
    {
        return [
            'id' => $employee->id,
            'employeeNumber' => $employee->employee_number,
            'fullName' => $employee->full_name,
            'workEmail' => $employee->work_email,
            'workPhone' => $employee->work_phone,
            // HR.2A: the stale-form token a PATCH must return.
            'version' => EmployeeProfileVersion::identity($employee),
            // ORG.7E-1: the account link is whole-School data; a Campus-scoped
            // caller is never told which sign-in account an Employee has.
            ...($wholeSchool ? ['userId' => $employee->user_id] : []),
            'recordStatus' => $employee->record_status,
        ];
    }
}
