<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPF.1 (ADR 0067 §14) -- Transport's fee-integration API: the route (pricing
 * tier) -> fee head mapping, the explicit academic-year carry-forward and an
 * assignment's recorded fee intent. Transport capabilities only; nothing
 * here can assess, cancel or alter money (that stays with Finance, D9).
 */
class TransportFeeController extends Controller
{
    use AuthorizesCapability;

    /*
     * ORG.7D-5: route ids are resolved here, inside the School context the
     * `school-membership` middleware sets -- implicit route-model binding of a
     * tenant model runs before that middleware and answered NOT FOUND for
     * everyone once no ambient context was present (found by ORG.7D-5 tests).
     */
    public function showRouteFeeHead(School $school, string $transportRoute, TransportFeeSelectionService $service): JsonResponse
    {
        // ORG.7D-5 (ADR 0076 §41): a declared whole-School-only operation of a Campus-
        // adopted key, authorized by the route's ordinary `capability:transport.routes.view` floor.
        $route = TransportRoute::query()->findOrFail($transportRoute);

        return response()->json(['data' => $service->routeFeeHead($route)]);
    }

    public function updateRouteFeeHead(Request $request, School $school, string $transportRoute, TransportFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);
        $route = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'fee_head_id' => ['present', 'nullable', 'uuid'],
        ]);

        $service->setRouteFeeHead($route, $validated['fee_head_id'], $request->user());

        return response()->json(['data' => $service->routeFeeHead($route)]);
    }

    public function carryForward(Request $request, School $school, TransportFeeSelectionService $service): JsonResponse
    {
        // ORG.7D-5 (ADR 0076 §41): a declared whole-School-only operation of a Campus-
        // adopted key, authorized by the route's ordinary `capability:transport.assignments.manage` floor.

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
        ]);

        $totals = $service->carryForward($school, $validated['academic_year_id'], $request->user());

        return response()->json(['data' => [
            'academicYearId' => $validated['academic_year_id'],
            'linked' => $totals['linked'],
            'alreadyLinked' => $totals['already_linked'],
            'unmapped' => $totals['unmapped'],
            'ended' => $totals['ended'],
            'notApplicable' => $totals['not_applicable'],
        ]]);
    }

    public function assignmentFeeSelections(School $school, string $transportStudentAssignment): JsonResponse
    {
        // ORG.7D-5 (ADR 0076 §41): a declared whole-School-only operation of a Campus-
        // adopted key, authorized by the route's ordinary `capability:transport.assignments.view` floor.
        $assignment = TransportStudentAssignment::query()->findOrFail($transportStudentAssignment);

        $links = TransportFeeSelection::query()
            ->where('transport_student_assignment_id', $assignment->id)
            ->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => $links->map(fn (TransportFeeSelection $link) => [
            'id' => $link->id,
            'academicYearId' => $link->academic_year_id,
            'feeHeadId' => $link->fee_head_id,
            'feeOptionalSelectionId' => $link->fee_optional_selection_id,
            'linkReason' => $link->link_reason,
            'selectionOutcome' => $link->selection_outcome,
            'createdAt' => $link->created_at?->toIso8601String(),
        ])->values()->all()]);
    }
}
