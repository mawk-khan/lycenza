<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeEsiCoverageAdminService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkpoint 9.6I (Section 2 "ESI coverage") -- thin HTTP transport
 * over `EmployeeEsiCoverageAdminService`. `index()` returns the full
 * period-by-period history INCLUDING the derived continuity flag
 * (never a separately-stored, independently-editable field -- see the
 * service's own docblock); `store()` (named `correct` in the service)
 * is refused for a period already consumed by a finalized statutory
 * calculation.
 */
class StatutoryEsiCoverageController extends Controller
{
    public function index(Request $request, School $school, string $employmentRecord, EmployeeEsiCoverageAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);
        $history = $service->history($school, $employmentRecord, $request->user());

        return response()->json(['data' => $history->map(fn (array $row) => [
            'periodStart' => $row['coverage']->period_start->toDateString(),
            'periodEnd' => $row['coverage']->period_end->toDateString(),
            'entryWage' => $row['coverage']->entry_wage,
            'isCovered' => $row['coverage']->is_covered,
            'continuous' => $row['continuous'],
        ])->all()]);
    }

    public function store(Request $request, School $school, string $employmentRecord, EmployeeEsiCoverageAdminService $service): JsonResponse
    {
        EmploymentRecord::query()->where('school_id', $school->id)->findOrFail($employmentRecord);

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'entry_wage' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'is_covered' => ['required', 'boolean'],
        ]);

        $coverage = $service->correct(
            $school, $employmentRecord,
            $validated['period_start'], $validated['period_end'], $validated['entry_wage'], $validated['is_covered'],
            $request->user(),
        );

        return response()->json(['data' => [
            'periodStart' => $coverage->period_start->toDateString(),
            'periodEnd' => $coverage->period_end->toDateString(),
            'entryWage' => $coverage->entry_wage,
            'isCovered' => $coverage->is_covered,
        ]], 201);
    }
}
