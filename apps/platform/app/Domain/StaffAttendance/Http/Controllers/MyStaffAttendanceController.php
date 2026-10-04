<?php

namespace App\Domain\StaffAttendance\Http\Controllers;

use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRX.4 (ADR 0065 §25.6): the acting Employee's OWN staff attendance --
 * READ ONLY. `hr.staff_attendance.self` plus ActingEmployee ownership, both
 * checked in StaffAttendanceReadService::own(). There is deliberately no
 * own-attendance write of any kind: HRX.3 attendance stays administrator-
 * recorded.
 */
class MyStaffAttendanceController extends Controller
{
    public function index(Request $request, School $school, StaffAttendanceReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $to = $validated['to'] ?? CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $from = $validated['from'] ?? CarbonImmutable::createFromFormat('!Y-m-d', $to)->subDays(29)->toDateString();

        return response()->json(['data' => $reads->own($school, $from, $to, $request->user())]);
    }
}
