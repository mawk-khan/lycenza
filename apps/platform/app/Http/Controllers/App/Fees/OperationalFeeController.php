<?php

namespace App\Http\Controllers\App\Fees;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\App\Concerns\MapsDomainRefusals;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SWC.3 (ADR 0073 section 19): the staff web surface of the OPF fee
 * contracts (ADR 0067) for Transport, Hostel and Admissions -- fee-head
 * mappings, recorded fee intent and next-year carry-forward. Thin: every
 * write is the OPF Application service `/api/v1` already uses, under the
 * capability that endpoint checks; domain refusals come back as form errors
 * (MapsDomainRefusals). The active School comes only from TenantContext;
 * another School's route, hostel, room or fee head is a 404 or a refusal,
 * never a write.
 *
 * Fee intent is recorded optional-fee selection, NOT a charge or a payment:
 * Finance creates charges when it assesses fees. Nothing here computes an
 * amount.
 *
 * Duplicate submissions (rule 29, X5):
 *   - a mapping update sets one value per route/hostel/room under a row
 *     lock -- a repeat converges and audits nothing new;
 *   - carry-forward is idempotent by construction: each assignment or
 *     residency is locked, an existing link for that year is skipped, and
 *     the `*_fee_selections_one_per_year` and
 *     `fee_optional_selections_one_active` unique indexes refuse a second
 *     link or selection. A repeat reports "already linked".
 */
class OperationalFeeController extends Controller
{
    use AuthorizesCapability, MapsDomainRefusals;

    public function __construct(
        private readonly OperationalFeePanels $panels,
        private readonly CapabilityResolver $capabilities,
    ) {}

    // --- Transport ---------------------------------------------------------

    public function updateTransportRouteFeeHead(Request $request, TenantContext $context, TransportFeeSelectionService $transport, string $transportRoute): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        $validated = $request->validate(['fee_head_id' => ['present', 'nullable', 'uuid']]);
        $route = TransportRoute::query()->findOrFail($transportRoute);

        $this->withFormErrors('fee_head_id', fn () => $transport->setRouteFeeHead($route, $validated['fee_head_id'], $context->actor()));

        return redirect()->route('app.transport.routes.show', $route->id);
    }

    public function transportFees(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.view', $school);

        $assignments = TransportStudentAssignment::query()
            ->with(['student', 'route'])
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate(25);
        $links = TransportFeeSelection::query()
            ->whereIn('transport_student_assignment_id', $assignments->getCollection()->pluck('id'))
            ->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('transport_student_assignment_id');
        $directory = $this->panels->directory($school);
        $years = $this->years();

        return Inertia::render('App/Transport/Fees', [
            'assignments' => $assignments->through(fn (TransportStudentAssignment $a) => [
                'id' => $a->id,
                'studentName' => trim($a->student->first_name.' '.$a->student->last_name),
                'routeName' => $a->route->name,
                'routeId' => $a->route_id,
                'intent' => $this->intentRows($links->get($a->id, collect()), $directory, $years),
            ]),
            'openYears' => $this->openYearOptions($years),
            'carryForward' => $request->session()->get('opf.transport.carry_forward'),
            'canCarryForward' => $this->can($school, 'transport.assignments.manage'),
        ]);
    }

    public function transportCarryForward(Request $request, TenantContext $context, TransportFeeSelectionService $transport): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        $validated = $request->validate(['academic_year_id' => ['required', 'uuid']]);
        $totals = $this->withFormErrors('academic_year_id', fn () => $transport->carryForward($school, $validated['academic_year_id'], $context->actor()));

        return redirect()->route('app.transport.fees')
            ->with('opf.transport.carry_forward', $this->presentTotals($validated['academic_year_id'], $totals));
    }

    // --- Hostel ------------------------------------------------------------

    public function updateHostelFeeHead(Request $request, TenantContext $context, HostelFeeSelectionService $hostels, string $hostel): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        $validated = $request->validate(['fee_head_id' => ['present', 'nullable', 'uuid']]);
        $model = Hostel::query()->findOrFail($hostel);

        $this->withFormErrors('fee_head_id', fn () => $hostels->setHostelFeeHead($model, $validated['fee_head_id'], $context->actor()));

        return redirect()->route('app.hostels.show', $model->id);
    }

    public function updateRoomFeeHead(Request $request, TenantContext $context, HostelFeeSelectionService $hostels, string $hostelRoom): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        $validated = $request->validate(['fee_head_id' => ['present', 'nullable', 'uuid']]);
        $room = HostelRoom::query()->findOrFail($hostelRoom);

        $this->withFormErrors('fee_head_id', fn () => $hostels->setRoomFeeHead($room, $validated['fee_head_id'], $context->actor()));

        return redirect()->route('app.hostel-rooms.show', $room->id);
    }

    public function hostelFees(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.view', $school);

        $residencies = HostelResidencyAssignment::query()
            ->with(['student', 'bed.room.hostel'])
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate(25);
        $links = HostelFeeSelection::query()
            ->whereIn('hostel_residency_assignment_id', $residencies->getCollection()->pluck('id'))
            ->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('hostel_residency_assignment_id');
        $directory = $this->panels->directory($school);
        $years = $this->years();

        return Inertia::render('App/Hostel/Fees', [
            'residencies' => $residencies->through(fn (HostelResidencyAssignment $r) => [
                'id' => $r->id,
                'studentName' => trim($r->student->first_name.' '.$r->student->last_name),
                'place' => trim($r->bed->room->hostel->name.' · '.$r->bed->room->code.' · '.$r->bed->code),
                'intent' => $this->intentRows($links->get($r->id, collect()), $directory, $years),
            ]),
            'openYears' => $this->openYearOptions($years),
            'carryForward' => $request->session()->get('opf.hostel.carry_forward'),
            'canCarryForward' => $this->can($school, 'hostel.residency.manage'),
        ]);
    }

    public function hostelCarryForward(Request $request, TenantContext $context, HostelFeeSelectionService $hostels): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.residency.manage', $school);

        $validated = $request->validate(['academic_year_id' => ['required', 'uuid']]);
        $totals = $this->withFormErrors('academic_year_id', fn () => $hostels->carryForward($school, $validated['academic_year_id'], $context->actor()));

        return redirect()->route('app.hostel-residency.fees')
            ->with('opf.hostel.carry_forward', $this->presentTotals($validated['academic_year_id'], $totals));
    }

    // --- Admissions ----------------------------------------------------------

    public function admissionFeeHead(TenantContext $context, AdmissionFeeSelectionService $admissions): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.view', $school);
        $canManage = $this->can($school, 'admissions.manage');

        return Inertia::render('App/Admissions/FeeHead', [
            'feeHead' => $this->panels->label($school, $admissions->feeHead($school)),
            'options' => $this->panels->feeHeadOptions($school, $canManage),
            'canManage' => $canManage,
        ]);
    }

    public function updateAdmissionFeeHead(Request $request, TenantContext $context, AdmissionFeeSelectionService $admissions): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate(['fee_head_id' => ['present', 'nullable', 'uuid']]);
        $this->withFormErrors('fee_head_id', fn () => $admissions->setFeeHead($school, $validated['fee_head_id'], $context->actor()));

        return redirect()->route('app.admissions.fee-head');
    }

    // --- Shared --------------------------------------------------------------

    /**
     * @param  Collection<int, TransportFeeSelection|HostelFeeSelection>  $links
     * @param  array<string, array{id: string, code: string, name: string, active: bool}>  $directory
     * @param  Collection<string, AcademicYear>  $years
     * @return list<array<string, mixed>>
     */
    private function intentRows(Collection $links, array $directory, Collection $years): array
    {
        return $links->map(fn (TransportFeeSelection|HostelFeeSelection $link) => [
            'academicYear' => $years->get($link->academic_year_id)?->name,
            'feeHead' => $directory[$link->fee_head_id] ?? null,
            'mappingScope' => $link instanceof HostelFeeSelection ? $link->mapping_scope : null,
            'linkReason' => $link->link_reason,
            'selectionOutcome' => $link->selection_outcome,
            'recordedAt' => $link->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /** @return Collection<string, AcademicYear> */
    private function years(): Collection
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get()->keyBy('id');
    }

    /**
     * Only an OPEN Academic Year (draft or active) can be carried forward to
     * -- the service's own rule (CurrentAcademicYearResolver::openYear()).
     *
     * @param  Collection<string, AcademicYear>  $years
     * @return list<array{id: string, name: string, status: string}>
     */
    private function openYearOptions(Collection $years): array
    {
        return $years->whereIn('status', ['draft', 'active'])
            ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'status' => $y->status])
            ->values()->all();
    }

    /**
     * @param  array{linked: int, already_linked: int, unmapped: int, ended: int, not_applicable: int}  $totals
     * @return array<string, int|string>
     */
    private function presentTotals(string $academicYearId, array $totals): array
    {
        return [
            'academicYearId' => $academicYearId,
            'linked' => $totals['linked'],
            'alreadyLinked' => $totals['already_linked'],
            'unmapped' => $totals['unmapped'],
            'ended' => $totals['ended'],
            'notApplicable' => $totals['not_applicable'],
        ];
    }

    private function can(School $school, string $capability): bool
    {
        return $this->capabilities->canInSchool(app(TenantContext::class)->actor(), $capability, $school);
    }
}
