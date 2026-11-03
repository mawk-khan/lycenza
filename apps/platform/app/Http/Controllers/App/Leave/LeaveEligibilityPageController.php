<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\HR\Application\EmployeeCategoryReference;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityOutcome;
use App\Domain\Leave\Http\Requests\LeavePolicyInput;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.9 (ADR 0065 §28.4): the Leave eligibility page and the policy editor's
 * draft preview. Session-authenticated, School in context.
 *
 * - GET  /app/leave/eligibility (`hr.leave.view`): one leave type's active
 *   versions with their rules, and every employment's outcome on a date --
 *   assigned (with drift), eligible, ambiguous, no matching rule, not
 *   eligible -- filterable by outcome (the unassigned staff view).
 * - POST /app/leave/eligibility-runs (`hr.leave.manage`): the additive run.
 * - POST /app/leave/policies/preview (`hr.leave.configure`): JSON, the same
 *   evaluation for the version being edited; writes nothing.
 *
 * Every answer comes from LeaveEligibilityService (which authorizes again);
 * the page holds no rule or calculation of its own.
 */
class LeaveEligibilityPageController extends Controller
{
    use AuthorizesCapability, LeavePageSupport;

    public function show(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveEligibilityService $eligibility,
        EmployeeCategoryReference $categories, DepartmentReference $departments): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate(['leave_type_id' => ['sometimes', 'uuid'], 'date' => ['sometimes', 'date_format:Y-m-d'],
            'outcome' => ['sometimes', 'nullable', 'in:'.implode(',', array_column(LeaveEligibilityOutcome::options(), 'value'))],
            'page' => ['sometimes', 'integer', 'min:1']]);
        $today = SchoolTimezone::today($school);
        $date = $validated['date'] ?? $today;
        $types = $reads->types($school, $actor);
        $typeId = $validated['leave_type_id'] ?? ($types[0]['id'] ?? null);

        $result = null;
        $error = null;
        if ($typeId !== null) {
            try {
                // HR.10: the complete population is evaluated; the page shows 100 rows of the chosen outcome at a time.
                $result = $eligibility->applicability($school, $typeId, $date, $actor, ($validated['outcome'] ?? '') === '' ? null : $validated['outcome'], (int) ($validated['page'] ?? 1), 100);
            } catch (ModelNotFoundException) {
                $error = 'Choose a leave type of this School.';
            } catch (LeaveException $e) {
                $error = "{$e->getMessage()} ({$e->errorCode()})";
            }
        }

        return Inertia::render('App/Leave/Eligibility', [
            'types' => $types,
            'leaveTypeId' => $typeId ?? '',
            'date' => $date,
            'today' => $today,
            'outcome' => $validated['outcome'] ?? '',
            'result' => $result,
            'error' => $error,
            'outcomes' => LeaveEligibilityOutcome::options(),
            'staffTypes' => StaffType::options(),
            'labels' => $this->labels($context, $school, $categories, $departments, $result),
            'canManage' => $capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school),
        ]);
    }

    public function executeRun(Request $request, TenantContext $context, LeaveEligibilityService $eligibility): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $run = null;
        $response = $this->command(function () use (&$run, $eligibility, $school, $validated, $request) {
            $run = $eligibility->executeRun($school, $validated['leave_type_id'], $validated['as_of'], $request->user());
        }, 'Eligibility run complete.');

        return $run === null ? $response : $response->with('status', "Eligibility run complete: {$run->assigned_count} assigned, {$run->skipped_count} not assigned.");
    }

    public function preview(Request $request, TenantContext $context, LeaveEligibilityService $eligibility): JsonResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'supersedes_policy_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'annual_allocation_units' => ['required', 'integer', 'min:0', 'max:1000'],
            ...LeavePolicyInput::eligibilityRules(),
        ]);
        try {
            $result = $eligibility->previewDraft($school, $validated['leave_type_id'], $validated['date'] ?? SchoolTimezone::today($school),
                $validated['supersedes_policy_id'] ?? null, $validated['name'], (int) $validated['annual_allocation_units'], LeavePolicyInput::criteria($validated), $request->user());
        } catch (LeaveException $e) {
            return response()->json(['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], $e->getStatusCode());
        } catch (ModelNotFoundException) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Choose a record of this School.']], 404);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Category and Department names for the rules and facts shown -- ONE read
     * each, through HR's readers.
     *
     * @param  array<string, mixed>|null  $result
     * @return array{categories: array<string, string>, departments: array<string, string>}
     */
    private function labels(TenantContext $context, School $school, EmployeeCategoryReference $categories, DepartmentReference $departments, ?array $result): array
    {
        if ($result === null) {
            return ['categories' => [], 'departments' => []];
        }
        $categoryIds = [];
        $departmentIds = [];
        foreach ($result['versions'] as $version) {
            foreach ($version['rules'] as $rule) {
                $categoryIds[] = $rule['employeeCategoryId'];
                $departmentIds[] = $rule['departmentId'];
            }
        }
        foreach ($result['rows'] as $row) {
            $categoryIds[] = $row['facts']['employeeCategoryId'];
            $departmentIds[] = $row['facts']['departmentId'];
        }

        return $context->withSchool($school, fn () => [
            'categories' => $categories->names($school, array_values(array_unique(array_filter($categoryIds)))),
            'departments' => $departments->names($school, array_values(array_unique(array_filter($departmentIds)))),
        ]);
    }
}
