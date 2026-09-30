<?php

namespace App\Http\Controllers\App\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Application\Exceptions\CurriculumDeliveryException;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryReadService;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryScope;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH.3 -- "My Curriculum Delivery": the session-authenticated OWNED
 * (Tier 2) page. A teacher picks one of THEIR classes (from their own
 * TeachingAssignments) and records coverage for it -- nothing School-wide
 * is loaded and filtered in the browser.
 *
 * Authorization is TeacherDeliveryAccess (capability
 * `curriculum.delivery.teacher` + a verified ActingEmployee + ownership) and,
 * for writes, the TeacherDeliveryGuard CurriculumDeliveryService runs in its
 * transaction. An actor who is not an eligible Employee sees an empty page
 * and is refused (403) on every write; a class or delivery they do not own
 * is 404.
 *
 * Deliberately absent: other teachers' classes, TeachingAssignment
 * administration, HR data, rosters, attendance, timetable and LMS.
 */
class MyCurriculumDeliveryController extends Controller
{
    public function index(Request $request, TenantContext $context, TeacherDeliveryAccess $access, TeacherDeliveryReadService $reads): Response
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'subject_offering_id' => ['sometimes', 'uuid'],
        ]);

        // The capability is required (403 without it); an actor who holds
        // it but is not an eligible Employee today sees an explicit empty
        // state and no data -- nothing School-wide, nothing owned.
        try {
            $scope = $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            return Inertia::render('App/CurriculumDelivery/Mine', ['eligible' => false, 'contexts' => [], 'selected' => null, 'rows' => [], 'today' => null]);
        }

        $contexts = $reads->contexts($school, $scope);
        $selected = isset($validated['section_id'], $validated['subject_offering_id'])
            && $scope->ownsContext($validated['section_id'], $validated['subject_offering_id']);

        return Inertia::render('App/CurriculumDelivery/Mine', [
            'eligible' => true,
            'contexts' => $contexts,
            'selected' => $selected ? ['sectionId' => $validated['section_id'], 'subjectOfferingId' => $validated['subject_offering_id']] : null,
            'rows' => $selected ? $reads->units($school, $scope, $validated['section_id'], $validated['subject_offering_id']) : [],
            'today' => $scope->asOf,
        ]);
    }

    public function store(Request $request, TenantContext $context, TeacherDeliveryAccess $access, CurriculumDeliveryService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'syllabus_unit_id' => ['required', 'uuid'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        abort_unless($this->scope($access, $request, $school)->ownsContext($validated['section_id'], $validated['subject_offering_id']), 404);

        $this->write(fn () => $service->start(
            $school,
            $validated['subject_offering_id'],
            $validated['section_id'],
            $validated['syllabus_unit_id'],
            $validated['started_on'],
            $request->user(),
            $access->guard($request->user()),
        ), 'started_on');

        return back();
    }

    public function update(Request $request, TenantContext $context, TeacherDeliveryAccess $access, CurriculumDeliveryService $service, string $curriculumDelivery): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'started_on' => ['sometimes', 'date_format:Y-m-d'],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $this->write(fn () => $service->correctDates(
            $school,
            $curriculumDelivery,
            $validated['started_on'] ?? null,
            $validated['completed_on'] ?? null,
            $request->user(),
            $access->guard($request->user()),
        ), 'started_on');

        return back();
    }

    public function transition(Request $request, TenantContext $context, TeacherDeliveryAccess $access, CurriculumDeliveryService $service, string $curriculumDelivery): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'new_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $this->write(fn () => $service->transition(
            $school,
            $curriculumDelivery,
            $validated['expected_status'],
            $validated['new_status'],
            $validated['completed_on'] ?? null,
            $request->user(),
            $access->guard($request->user()),
        ), 'new_status');

        return back();
    }

    private function scope(TeacherDeliveryAccess $access, Request $request, School $school): TeacherDeliveryScope
    {
        try {
            return $access->scope($request->user(), $school);
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        }
    }

    /**
     * Domain refusals become form errors on this surface, as on the Tier 1
     * page; an ineligible actor is 403, an unowned delivery 404 (thrown
     * through unchanged).
     */
    private function write(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (ActingEmployeeUnavailableException) {
            abort(403);
        } catch (CurriculumDeliveryException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
