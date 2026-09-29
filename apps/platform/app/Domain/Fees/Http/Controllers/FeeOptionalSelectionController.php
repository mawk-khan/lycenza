<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Http\TranslatesFeeSetupErrors;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FEE.1 (ADR 0062 §8): explicit optional-fee selections. Listing needs
 * finance.fee_structures.view and a student_id or fee_structure_line_id
 * filter; select/withdraw need finance.fee_structures.manage. No DELETE:
 * a selection is withdrawn, never removed.
 */
class FeeOptionalSelectionController extends Controller
{
    use TranslatesFeeSetupErrors;

    public function index(Request $request, School $school, FeeStructureReadService $reads): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['required_without:fee_structure_line_id', 'uuid'],
            'fee_structure_line_id' => ['required_without:student_id', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in([FeeOptionalSelection::STATUS_ACTIVE, FeeOptionalSelection::STATUS_WITHDRAWN])],
        ]);

        $selections = $this->translatingFeeSetupErrors(fn () => $reads->listSelections($school, $filters, $request->user()));

        return response()->json(['data' => $selections->map(fn (FeeOptionalSelection $s) => FeeSetupPresenter::selection($s))->values()->all()]);
    }

    public function store(Request $request, School $school, FeeOptionalSelectionService $service): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'uuid'],
            'fee_structure_line_id' => ['required', 'uuid'],
        ]);

        $selection = $this->translatingFeeSetupErrors(
            fn () => $service->select($school, $validated['student_id'], $validated['fee_structure_line_id'], $request->user()),
        );

        return response()->json(['data' => FeeSetupPresenter::selection($selection)], 201);
    }

    public function withdraw(Request $request, School $school, string $selection, FeeOptionalSelectionService $service): JsonResponse
    {
        return response()->json(['data' => FeeSetupPresenter::selection($service->withdraw($school, $selection, $request->user()))]);
    }
}
