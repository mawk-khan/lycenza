<?php

namespace App\Http\Controllers\App;

use App\Domain\Hostel\Application\Exceptions\BedAlreadyOccupiedException;
use App\Domain\Hostel\Application\Exceptions\BedNotAvailableException;
use App\Domain\Hostel\Application\Exceptions\HostelResidencyUnavailableException;
use App\Domain\Hostel\Application\Exceptions\ResidencyAlreadyEndedException;
use App\Domain\Hostel\Application\Exceptions\StudentAlreadyResidentException;
use App\Domain\Hostel\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Hostel\Application\HostelResidencyAccess;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
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
 *
 * ORG.7D-3 (ADR 0076 §39): `hostel.residency.*` are Campus-adopted --
 * residencies, beds and resident candidates come only through
 * HostelResidencyAccess within the actor's CampusScope (out of scope = 404),
 * and a Campus-scoped write runs HostelResidencyCampusGuard.
 */
class HostelResidencyController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, HostelResidencyAccess $access): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hostel.residency.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
        ]);

        $assignments = $access->residencies($scope)
            ->with(['student', 'bed.room.hostel'])
            ->where('status', $validated['status'] ?? 'active')
            ->orderByDesc('starts_on')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Hostel/Residency/Index', [
            'assignments' => $assignments->through(fn (HostelResidencyAssignment $a) => $this->present($a)),
            'filters' => ['status' => $validated['status'] ?? 'active'],
            'canManage' => ! $scopes->campusScope($context->actor(), 'hostel.residency.manage', $school)->isNone(),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('hostel.residency.manage', $school);

        return Inertia::render('App/Hostel/Residency/Create');
    }

    /**
     * ORG.7D-3: the resident picker -- current operational Students of the
     * actor's Hostel Campuses only (StudentPurposeLookup), minimal fields.
     */
    public function searchStudents(Request $request, TenantContext $context, HostelResidencyAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hostel.residency.manage', $school);

        $term = (string) $request->query('q', '');

        return response()->json(['data' => array_map(fn (StudentLookupCandidate $c) => [
            'id' => $c->id,
            'studentNumber' => $c->studentNumber,
            'name' => $c->displayName,
        ], $access->residentCandidates($school, $scope, $term))]);
    }

    public function searchBeds(Request $request, TenantContext $context, HostelResidencyAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hostel.residency.manage', $school);

        $term = (string) $request->query('q', '');

        $beds = $access->beds($scope)
            ->where('status', 'active')
            ->whereDoesntHave('activeResidency')
            ->whereHas('room', fn ($q) => $q->where('status', 'active')->whereHas('hostel', fn ($q2) => $q2->where('status', 'active')))
            ->with('room.hostel')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', '%'.addcslashes($term, '%_\\').'%'))
            ->limit(10)
            ->get();

        return response()->json(['data' => $beds->map(fn (HostelBed $b) => [
            'id' => $b->id,
            'code' => $b->code,
            'roomCode' => $b->room->code,
            'hostelName' => $b->room->hostel->name,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, HostelResidencyService $service, HostelResidencyAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hostel.residency.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'hostel_bed_id' => ['required', 'string'],
        ]);

        try {
            $bed = $access->bed($scope, $validated['hostel_bed_id']);
            $student = $access->residentStudent($scope, $validated['student_id']);
            $service->assign($student, $bed, $context->actor(), $access->writeGuard($school, $scope));
        } catch (StudentNotEligibleException|StudentAlreadyResidentException|HostelResidencyUnavailableException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (BedNotAvailableException|BedAlreadyOccupiedException $e) {
            throw ValidationException::withMessages(['hostel_bed_id' => [$e->getMessage()]]);
        }

        return redirect('/app/hostel-residency')->with('flash', 'Student assigned to Hostel.');
    }

    public function end(TenantContext $context, HostelResidencyService $service, HostelResidencyAccess $access, string $hostelResidencyAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('hostel.residency.manage', $school);

        $model = $access->residency($scope, $hostelResidencyAssignment);

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
