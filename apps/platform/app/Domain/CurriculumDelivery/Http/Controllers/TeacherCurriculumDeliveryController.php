<?php

namespace App\Domain\CurriculumDelivery\Http\Controllers;

use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryReadService;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * TCH.3 (ADR 0063 sections 11, 16, 18, 23) -- the OWNED (Tier 2) Curriculum
 * Delivery API under `/my/`: the calling teacher's own classes and the
 * deliveries their TeachingAssignments cover. A separate surface, so the
 * Tier 1 administrative endpoints keep their School-wide meaning unchanged.
 *
 * Route middleware requires `curriculum.delivery.teacher`;
 * TeacherDeliveryAccess re-checks it and adds a verified ActingEmployee and
 * the ownership periods. Writes run the SAME CurriculumDeliveryService with a
 * TeacherDeliveryGuard (identity and ownership held inside its transaction).
 *
 * Non-disclosure (section 18): another teacher's delivery, another class,
 * another School's id and a malformed id are all the same 404. The request
 * body never names the School, the Employee or the owner.
 */
class TeacherCurriculumDeliveryController extends Controller
{
    public function contexts(Request $request, School $school, TeacherDeliveryAccess $access, TeacherDeliveryReadService $reads): JsonResponse
    {
        return response()->json(['data' => $reads->contexts($school, $access->scope($request->user(), $school))]);
    }

    public function index(Request $request, School $school, TeacherDeliveryAccess $access, TeacherDeliveryReadService $reads): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => ['required', 'uuid'],
            'subject_offering_id' => ['required', 'uuid'],
        ]);

        $scope = $access->scope($request->user(), $school);

        return response()->json(['data' => $reads->units($school, $scope, $validated['section_id'], $validated['subject_offering_id'])]);
    }

    public function show(Request $request, School $school, string $curriculumDelivery, TeacherDeliveryAccess $access, TeacherDeliveryReadService $reads): JsonResponse
    {
        abort_if(! Str::isUuid($curriculumDelivery), 404);

        $delivery = $reads->find($school, $access->scope($request->user(), $school), $curriculumDelivery);

        return response()->json(['data' => TeacherDeliveryReadService::present($delivery)]);
    }

    public function store(Request $request, School $school, TeacherDeliveryAccess $access, CurriculumDeliveryService $service): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => ['required', 'uuid'],
            'subject_offering_id' => ['required', 'uuid'],
            'syllabus_unit_id' => ['required', 'uuid'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        // A class the teacher does not own is not found, before any other
        // validation can say anything about it.
        if (! $access->scope($request->user(), $school)->ownsContext($validated['section_id'], $validated['subject_offering_id'])) {
            throw (new ModelNotFoundException)->setModel(CurriculumDelivery::class);
        }

        $delivery = $service->start(
            $school,
            $validated['subject_offering_id'],
            $validated['section_id'],
            $validated['syllabus_unit_id'],
            $validated['started_on'],
            $request->user(),
            $access->guard($request->user()),
        );

        return response()->json(['data' => TeacherDeliveryReadService::present($delivery)], 201);
    }

    public function update(Request $request, School $school, string $curriculumDelivery, TeacherDeliveryAccess $access, CurriculumDeliveryService $service): JsonResponse
    {
        abort_if(! Str::isUuid($curriculumDelivery), 404);

        $validated = $request->validate([
            'started_on' => ['sometimes', 'date_format:Y-m-d'],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $delivery = $service->correctDates(
            $school,
            $curriculumDelivery,
            $validated['started_on'] ?? null,
            $validated['completed_on'] ?? null,
            $request->user(),
            $access->guard($request->user()),
        );

        return response()->json(['data' => TeacherDeliveryReadService::present($delivery)]);
    }

    public function transition(Request $request, School $school, string $curriculumDelivery, TeacherDeliveryAccess $access, CurriculumDeliveryService $service): JsonResponse
    {
        abort_if(! Str::isUuid($curriculumDelivery), 404);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'new_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $delivery = $service->transition(
            $school,
            $curriculumDelivery,
            $validated['expected_status'],
            $validated['new_status'],
            $validated['completed_on'] ?? null,
            $request->user(),
            $access->guard($request->user()),
        );

        return response()->json(['data' => TeacherDeliveryReadService::present($delivery)]);
    }
}
