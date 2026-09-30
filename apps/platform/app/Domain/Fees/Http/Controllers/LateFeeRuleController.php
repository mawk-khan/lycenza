<?php

namespace App\Domain\Fees\Http\Controllers;

use App\Domain\Fees\Application\Exceptions\InvalidFeeLateFeeRuleException;
use App\Domain\Fees\Application\LateFeeRuleService;
use App\Domain\Fees\Infrastructure\FeeLateFeeRule;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * FEE.5 (ADR 0062 §16.1; owner decision H): the late-fee rule API. Reads
 * need finance.fee_structures.view; create/update/activate/deactivate need
 * finance.fee_structures.manage -- on the route and in the Application
 * service. No DELETE. Legal status: DEVELOPMENT AUTHORISED — PROD LEGAL
 * SIGN-OFF REQUIRED (ADR 0058 E31).
 */
class LateFeeRuleController extends Controller
{
    /** @return array<string, mixed> */
    public static function present(FeeLateFeeRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'feeStructureId' => $r->fee_structure_id,
            'feeHeadId' => $r->fee_head_id,
            'lateFeeHeadId' => $r->late_fee_head_id,
            'graceDays' => $r->grace_days,
            'kind' => $r->kind,
            'fixedAmount' => $r->fixed_amount,
            'percentage' => $r->percentage,
            'maxAmount' => $r->max_amount,
            'currency' => $r->currency,
            'status' => $r->status,
            'configurationVersion' => $r->configuration_version,
        ];
    }

    public function index(Request $request, School $school, LateFeeRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->list($school, $request->user())->map(fn (FeeLateFeeRule $r) => self::present($r))->values()->all()]);
    }

    public function show(Request $request, School $school, string $rule, LateFeeRuleService $rules): JsonResponse
    {
        return response()->json(['data' => self::present($rules->get($school, $rule, $request->user()))]);
    }

    public function store(Request $request, School $school, LateFeeRuleService $rules): JsonResponse
    {
        $data = $this->validated($request, withStructure: true);

        return response()->json(['data' => self::present($this->fieldErrors(fn () => $rules->create($school, $data, $request->user())))], 201);
    }

    public function update(Request $request, School $school, string $rule, LateFeeRuleService $rules): JsonResponse
    {
        $data = $this->validated($request, withStructure: false);

        return response()->json(['data' => self::present($this->fieldErrors(fn () => $rules->update($school, $rule, $data, $request->user())))]);
    }

    public function activate(Request $request, School $school, string $rule, LateFeeRuleService $rules): JsonResponse
    {
        return response()->json(['data' => self::present($this->fieldErrors(fn () => $rules->activate($school, $rule, $request->user())))]);
    }

    public function deactivate(Request $request, School $school, string $rule, LateFeeRuleService $rules): JsonResponse
    {
        return response()->json(['data' => self::present($rules->deactivate($school, $rule, $request->user()))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withStructure): array
    {
        return $request->validate(array_filter([
            'fee_structure_id' => $withStructure ? ['required', 'uuid'] : null,
            'name' => ['required', 'string', 'max:120'],
            'fee_head_id' => ['nullable', 'uuid'],
            'late_fee_head_id' => ['required', 'uuid'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'kind' => ['required', 'string', 'in:fixed,percentage'],
            'fixed_amount' => ['nullable', 'string', 'max:20'],
            'percentage' => ['nullable', 'string', 'max:8'],
            'max_amount' => ['nullable', 'string', 'max:20'],
        ]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function fieldErrors(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidFeeLateFeeRuleException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }
    }
}
