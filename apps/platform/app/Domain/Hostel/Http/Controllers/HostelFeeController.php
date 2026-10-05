<?php

namespace App\Domain\Hostel\Http\Controllers;

use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPF.2 (ADR 0067 §15) -- Hostel's fee-integration API: the accommodation
 * tier -> fee head mapping (Hostel default, room override), the explicit
 * academic-year carry-forward and a residency's recorded fee intent. Hostel
 * capabilities only; nothing here can assess, cancel or alter money (that
 * stays with Finance, D9).
 */
class HostelFeeController extends Controller
{
    use AuthorizesCapability;

    public function showHostelFeeHead(School $school, string $hostel, HostelFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        return response()->json(['data' => $service->hostelFeeHead(Hostel::query()->findOrFail($hostel))]);
    }

    public function updateHostelFeeHead(Request $request, School $school, string $hostel, HostelFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);

        $validated = $request->validate([
            'fee_head_id' => ['present', 'nullable', 'uuid'],
        ]);

        $model = Hostel::query()->findOrFail($hostel);
        $service->setHostelFeeHead($model, $validated['fee_head_id'], $request->user());

        return response()->json(['data' => $service->hostelFeeHead($model)]);
    }

    public function showRoomFeeHead(School $school, string $hostelRoom, HostelFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        return response()->json(['data' => $service->roomFeeHead(HostelRoom::query()->findOrFail($hostelRoom))]);
    }

    public function updateRoomFeeHead(Request $request, School $school, string $hostelRoom, HostelFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);

        $validated = $request->validate([
            'fee_head_id' => ['present', 'nullable', 'uuid'],
        ]);

        $model = HostelRoom::query()->findOrFail($hostelRoom);
        $service->setRoomFeeHead($model, $validated['fee_head_id'], $request->user());

        return response()->json(['data' => $service->roomFeeHead($model)]);
    }

    public function carryForward(Request $request, School $school, HostelFeeSelectionService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.manage', $school);

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

    public function residencyFeeSelections(School $school, string $hostelResidencyAssignment): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.view', $school);

        $residency = HostelResidencyAssignment::query()->findOrFail($hostelResidencyAssignment);
        $links = HostelFeeSelection::query()
            ->where('hostel_residency_assignment_id', $residency->id)
            ->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => $links->map(fn (HostelFeeSelection $link) => [
            'id' => $link->id,
            'academicYearId' => $link->academic_year_id,
            'feeHeadId' => $link->fee_head_id,
            'feeOptionalSelectionId' => $link->fee_optional_selection_id,
            'mappingScope' => $link->mapping_scope,
            'linkReason' => $link->link_reason,
            'selectionOutcome' => $link->selection_outcome,
            'createdAt' => $link->created_at?->toIso8601String(),
        ])->values()->all()]);
    }
}
