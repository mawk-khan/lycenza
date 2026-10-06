<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryFineService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * OPF.4 (ADR 0067 §17) -- Library's fine API: the versioned fine policy, a
 * loan's fine, and the D4 void of an erroneous unpaid fine. `library.fines.*`
 * only; fines themselves are assessed by the ordinary check-in
 * (`library.circulation.manage`), so there is no assess endpoint, and nothing
 * here exposes FEE's charge service. Waivers are FEE concessions (Finance).
 */
class LibraryFineController extends Controller
{
    use AuthorizesCapability;

    public function showPolicy(School $school, LibraryFinePolicyService $policies): JsonResponse
    {
        $this->authorizeCapability('library.fines.view', $school);
        $current = $policies->current($school);

        return response()->json(['data' => $current === null ? null : $this->presentPolicy($current)]);
    }

    public function policyVersions(School $school, LibraryFinePolicyService $policies): JsonResponse
    {
        $this->authorizeCapability('library.fines.view', $school);

        return response()->json(['data' => $policies->versions($school)->map(fn (LibraryFinePolicy $p) => $this->presentPolicy($p))->values()->all()]);
    }

    public function publishPolicy(Request $request, School $school, LibraryFinePolicyService $policies): JsonResponse
    {
        $this->authorizeCapability('library.fines.manage', $school);

        $validated = $request->validate([
            'status' => ['required', Rule::in([LibraryFinePolicy::STATUS_ACTIVE, LibraryFinePolicy::STATUS_DISABLED])],
            'fee_head_id' => ['required_if:status,active', 'nullable', 'uuid'],
            'daily_rate' => ['required_if:status,active', 'nullable', 'string', 'max:16'],
            'grace_days' => ['required_if:status,active', 'nullable', 'integer'],
            'max_amount' => ['nullable', 'string', 'max:16'],
        ]);
        if (isset($validated['grace_days'])) {
            $validated['grace_days'] = (int) $validated['grace_days'];
        }

        $policy = $policies->publish($school, $validated, $request->user());

        return response()->json(['data' => $this->presentPolicy($policy)], 201);
    }

    public function loanFine(School $school, string $libraryLoan): JsonResponse
    {
        $this->authorizeCapability('library.fines.view', $school);

        $loan = LibraryLoan::query()->findOrFail($libraryLoan);
        $fine = LibraryFine::query()->where('library_loan_id', $loan->id)->first();

        return response()->json(['data' => $fine === null ? null : $this->presentFine($fine)]);
    }

    public function void(Request $request, School $school, string $libraryFine, LibraryFineService $fines): JsonResponse
    {
        $this->authorizeCapability('library.fines.void', $school);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $fine = $fines->void($school, $libraryFine, $validated['reason'], $request->user());

        return response()->json(['data' => $this->presentFine($fine)]);
    }

    /** @return array<string, mixed> */
    private function presentPolicy(LibraryFinePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'version' => $policy->version,
            'status' => $policy->status,
            'feeHeadId' => $policy->fee_head_id,
            'dailyRate' => $policy->daily_rate,
            'graceDays' => $policy->grace_days,
            'maxAmount' => $policy->max_amount,
            'currency' => $policy->currency,
            'createdAt' => $policy->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentFine(LibraryFine $fine): array
    {
        $void = LibraryFineVoid::query()->where('library_fine_id', $fine->id)->first();

        return [
            'id' => $fine->id,
            'libraryLoanId' => $fine->library_loan_id,
            'studentId' => $fine->student_id,
            'kind' => $fine->kind,
            'libraryFinePolicyId' => $fine->library_fine_policy_id,
            'feeHeadId' => $fine->fee_head_id,
            'academicYearId' => $fine->academic_year_id,
            'overdueDays' => $fine->overdue_days,
            'chargeableDays' => $fine->chargeable_days,
            'amount' => $fine->amount,
            'currency' => $fine->currency,
            'chargeId' => $fine->charge_id,
            'status' => $void === null ? 'assessed' : 'voided',
            'voidedAt' => $void?->created_at?->toIso8601String(),
            'createdAt' => $fine->created_at?->toIso8601String(),
        ];
    }
}
