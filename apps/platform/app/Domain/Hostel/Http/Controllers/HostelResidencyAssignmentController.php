<?php

namespace App\Domain\Hostel\Http\Controllers;

use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10D -- a Student's Hostel residency assignment administrative
 * API. store() (the consequential assign mutation) carries the
 * `idempotent` middleware in routes/api.php, mirroring
 * App\Domain\Visitor\Http\Controllers\VisitorVisitController::store()
 * -- see HostelResidencyService's docblock for the concurrency
 * guarantee this endpoint delegates to. `end()` deliberately does NOT
 * carry idempotency -- see docs/modules/HOSTEL.md "Idempotency
 * decisions".
 */
class HostelResidencyAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
            'student_id' => ['sometimes', 'string'],
        ]);

        $query = HostelResidencyAssignment::query()
            ->with(['student', 'bed.room.hostel'])
            ->orderByDesc('starts_on');

        $query->where('status', $validated['status'] ?? 'active');

        if (isset($validated['student_id'])) {
            $query->where('student_id', $validated['student_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (HostelResidencyAssignment $a) => $this->present($a))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, HostelResidencyService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'hostel_bed_id' => ['required', 'string'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        $bed = HostelBed::query()->findOrFail($validated['hostel_bed_id']);

        $assignment = $service->assign($student, $bed, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['student', 'bed.room.hostel']))], 201);
    }

    public function show(School $school, string $hostelResidencyAssignment): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.view', $school);

        $model = HostelResidencyAssignment::query()->with(['student', 'bed.room.hostel'])->findOrFail($hostelResidencyAssignment);

        return response()->json(['data' => $this->present($model)]);
    }

    public function end(Request $request, School $school, string $hostelResidencyAssignment, HostelResidencyService $service): JsonResponse
    {
        $this->authorizeCapability('hostel.residency.manage', $school);

        $model = HostelResidencyAssignment::query()->findOrFail($hostelResidencyAssignment);

        $assignment = $service->end($model, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['student', 'bed.room.hostel']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HostelResidencyAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'startsOn' => $assignment->starts_on->toIso8601String(),
            'endsOn' => $assignment->ends_on?->toIso8601String(),
            'student' => [
                'id' => $assignment->student->id,
                'studentNumber' => $assignment->student->student_number,
                'firstName' => $assignment->student->first_name,
                'lastName' => $assignment->student->last_name,
            ],
            'bed' => [
                'id' => $assignment->bed->id,
                'code' => $assignment->bed->code,
                'room' => [
                    'id' => $assignment->bed->room->id,
                    'code' => $assignment->bed->room->code,
                    'hostel' => [
                        'id' => $assignment->bed->room->hostel->id,
                        'code' => $assignment->bed->room->hostel->code,
                        'name' => $assignment->bed->room->hostel->name,
                    ],
                ],
            ],
        ];
    }
}
