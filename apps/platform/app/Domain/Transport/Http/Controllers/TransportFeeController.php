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

    public function showRouteFeeHead(School $school, TransportRoute $transportRoute, TransportFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('transport.routes.view', $school);

        return response()->json(['data' => $service->routeFeeHead($transportRoute)]);
    }

    public function updateRouteFeeHead(Request $request, School $school, TransportRoute $transportRoute, TransportFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);

        $validated = $request->validate([
            'fee_head_id' => ['present', 'nullable', 'uuid'],
        ]);

        $service->setRouteFeeHead($transportRoute, $validated['fee_head_id'], $request->user());

        return response()->json(['data' => $service->routeFeeHead($transportRoute)]);
    }

    public function carryForward(Request $request, School $school, TransportFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.manage', $school);

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

    public function assignmentFeeSelections(School $school, TransportStudentAssignment $transportStudentAssignment): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.view', $school);

        $links = TransportFeeSelection::query()
            ->where('transport_student_assignment_id', $transportStudentAssignment->id)
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
