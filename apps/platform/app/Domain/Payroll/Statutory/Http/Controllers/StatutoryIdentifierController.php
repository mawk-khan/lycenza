<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryIdentifierAdminService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "Statutory identifiers") -- thin HTTP
 * transport over `StatutoryIdentifierAdminService`. `index()` NEVER
 * carries a raw value -- it calls `list()`, which only ever returns
 * the masked form (`payroll.statutory.view` suffices). `reveal()` is
 * a SEPARATE endpoint, gated by `payroll.statutory.identifiers.view`
 * and audited on every call, so the raw value is never present in any
 * response an unauthorized (or even ordinarily-authorized-for-view-only)
 * caller can trigger.
 */
class StatutoryIdentifierController extends Controller
{
    public function index(Request $request, School $school, string $employmentRecord, StatutoryIdentifierAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);
        $identifiers = $service->list($school, $employmentRecord, $request->user());

        return response()->json(['data' => $identifiers->all()]);
    }

    public function reveal(Request $request, School $school, string $employmentRecord, string $identifierType, StatutoryIdentifierAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);
        $value = $service->reveal($school, $employmentRecord, $identifierType, $request->user());

        return response()->json(['data' => ['identifierType' => $identifierType, 'value' => $value]]);
    }

    public function store(Request $request, School $school, string $employmentRecord, StatutoryIdentifierAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);

        $validated = $request->validate([
            'identifier_type' => ['required', 'in:pan,uan,pf_member_id,esic_ip_number'],
            'value' => ['required', 'string', 'max:64'],
        ]);

        $identifier = $service->set($school, $employmentRecord, $validated['identifier_type'], $validated['value'], $request->user());

        return response()->json(['data' => [
            'identifierType' => $identifier->identifier_type,
            'updatedAt' => $identifier->updated_at->toIso8601String(),
        ]], 201);
    }
}
