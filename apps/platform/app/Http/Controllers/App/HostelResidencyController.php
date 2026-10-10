<?php

namespace App\Http\Controllers\App;

use App\Domain\Hostel\Application\Exceptions\BedAlreadyOccupiedException;
use App\Domain\Hostel\Application\Exceptions\BedNotAvailableException;
use App\Domain\Hostel\Application\Exceptions\ResidencyAlreadyEndedException;
use App\Domain\Hostel\Application\Exceptions\StudentAlreadyResidentException;
use App\Domain\Hostel\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10D -- session-authenticated Inertia pages for Student Hostel
 * residency. Every mutation delegates to HostelResidencyService, the
 * exact same service the JSON API controller uses. The Student/Bed
 * search endpoints below explicitly call authorizeCapability() BEFORE
 * querying -- checkpoint brief section 26: carrying forward the Phase
 * 10A Library security precedent, re-verified for Transport and
 * Visitor.
 */
class HostelResidencyController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
        ]);

        $assignments = HostelResidencyAssignment::query()
            ->with(['student', 'bed.room.hostel'])
            ->where('status', $validated['status'] ?? 'active')
            ->orderByDesc('starts_on')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Hostel/Residency/Index', [
            'assignments' => $assignments->through(fn (HostelResidencyAssignment $a) => $this->present($a)),
            'filters' => ['status' => $validated['status'] ?? 'active'],
            'canManage' => $capabilities->canInSchool($context->actor(), 'hostel.residency.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        return Inertia::render('App/Hostel/Residency/Create');
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        $term = (string) $request->query('q', '');

        $students = Student::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('student_number', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%")))
            ->limit(10)
            ->get();

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'id' => $s->id,
            'studentNumber' => $s->student_number,
            'name' => trim($s->first_name.' '.$s->last_name),
        ])->all()]);
    }

    public function searchBeds(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        $term = (string) $request->query('q', '');

        $beds = HostelBed::query()
            ->where('status', 'active')
            ->whereDoesntHave('activeResidency')
            ->whereHas('room', fn ($q) => $q->where('status', 'active')->whereHas('hostel', fn ($q2) => $q2->where('status', 'active')))
            ->with('room.hostel')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $beds->map(fn (HostelBed $b) => [
            'id' => $b->id,
            'code' => $b->code,
            'roomCode' => $b->room->code,
            'hostelName' => $b->room->hostel->name,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, HostelResidencyService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'hostel_bed_id' => ['required', 'string'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        $bed = HostelBed::query()->findOrFail($validated['hostel_bed_id']);

        try {
            $service->assign($student, $bed, $context->actor());
        } catch (StudentNotEligibleException|StudentAlreadyResidentException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (BedNotAvailableException|BedAlreadyOccupiedException $e) {
            throw ValidationException::withMessages(['hostel_bed_id' => [$e->getMessage()]]);
        }

        return redirect('/app/hostel-residency')->with('flash', 'Student assigned to Hostel.');
    }

    public function end(TenantContext $context, HostelResidencyService $service, string $hostelResidencyAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        $model = HostelResidencyAssignment::query()->findOrFail($hostelResidencyAssignment);

        try {
            $service->end($model, $context->actor());
        } catch (ResidencyAlreadyEndedException $e) {
            throw ValidationException::withMessages(['hostel_residency_assignment' => [$e->getMessage()]]);
        }

        return redirect('/app/hostel-residency')->with('flash', 'Hostel residency ended.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HostelResidencyAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'studentName' => trim($assignment->student->first_name.' '.$assignment->student->last_name),
            'studentNumber' => $assignment->student->student_number,
            'bedCode' => $assignment->bed->code,
            'roomCode' => $assignment->bed->room->code,
            'hostelName' => $assignment->bed->room->hostel->name,
            'startsOn' => $assignment->starts_on->toDateString(),
        ];
    }
}
