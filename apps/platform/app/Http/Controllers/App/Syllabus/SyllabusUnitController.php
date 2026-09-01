<?php

namespace App\Http\Controllers\App\Syllabus;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H.3A -- the session-authenticated administrative Syllabus
 * surface. One page: resolve an AcademicYear -> SubjectOffering
 * context, list that Offering's units in teaching order, create a
 * unit, edit its code/title/sequence/status.
 *
 * The AcademicYear/Offering context reuses the EXACT filter shape
 * App\Http\Controllers\App\SubjectOfferingController::index() already
 * established (an optional `academic_year_id`, defaulting to the
 * School's active year) rather than building a new Offering search or
 * discovery API -- there is no new lookup infrastructure here.
 *
 * Deliberately absent: Student roster, marks, grading, delivery
 * tracking, lesson plans, attachments, teacher portal, and any
 * Student- or Guardian-facing view.
 */
class SyllabusUnitController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('syllabus.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'subject_offering_id' => ['sometimes', 'uuid'],
        ]);

        $academicYearId = $validated['academic_year_id']
            ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        $offerings = SubjectOffering::query()
            ->with(['subject', 'gradeLevel', 'campus'])
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->where('status', 'active')
            ->get();

        $selectedOfferingId = $validated['subject_offering_id'] ?? null;
        $units = [];

        if ($selectedOfferingId !== null && $offerings->contains('id', $selectedOfferingId)) {
            $units = SyllabusUnit::query()
                ->where('subject_offering_id', $selectedOfferingId)
                ->orderBy('sequence')
                ->orderByRaw('upper(code)')
                ->get()
                ->map(fn (SyllabusUnit $unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'title' => $unit->title,
                    'sequence' => $unit->sequence,
                    'status' => $unit->status,
                ])->values()->all();
        } else {
            $selectedOfferingId = null;
        }

        return Inertia::render('App/Syllabus/Index', [
            'academicYears' => AcademicYear::query()
                ->where('school_id', $school->id)
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])
                ->values()->all(),
            'offerings' => $offerings->map(fn (SubjectOffering $o) => [
                'id' => $o->id,
                'subjectCode' => $o->subject?->code,
                'subjectName' => $o->subject?->name,
                'gradeLevelName' => $o->gradeLevel?->name,
                'campusName' => $o->campus?->name,
                'isRequired' => $o->is_required,
            ])->values()->all(),
            'filters' => [
                'academicYearId' => $academicYearId ?? '',
                'subjectOfferingId' => $selectedOfferingId ?? '',
            ],
            'units' => $units,
            'statuses' => SyllabusUnit::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'syllabus.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('syllabus.manage', $school);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(SyllabusUnit::STATUSES)],
        ]);

        $offering = SubjectOffering::query()->findOrFail($validated['subject_offering_id']);

        $request->validate([
            'code' => [
                Rule::unique('syllabus_units', 'code')
                    ->where('school_id', $school->id)
                    ->where('subject_offering_id', $offering->id),
            ],
        ]);

        DB::transaction(function () use ($school, $offering, $validated, $request, $audit) {
            $unit = SyllabusUnit::query()->create([
                'school_id' => $school->id,
                'subject_offering_id' => $offering->id,
                'code' => $validated['code'],
                'title' => $validated['title'],
                'sequence' => $validated['sequence'],
                'status' => $validated['status'] ?? 'active',
            ]);

            $audit->school($school, 'syllabus.unit.created', actor: $request->user(), subject: $unit, metadata: [
                'unitId' => $unit->id,
                'subjectOfferingId' => $unit->subject_offering_id,
                'code' => $unit->code,
                'sequence' => $unit->sequence,
            ]);
        });

        return redirect()
            ->route('app.syllabus.index', [
                'academic_year_id' => $offering->academic_year_id,
                'subject_offering_id' => $offering->id,
            ])
            ->with('status', 'Syllabus unit created.');
    }

    public function update(Request $request, TenantContext $context, AuditRecorder $audit, string $syllabusUnit): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('syllabus.manage', $school);

        $unit = SyllabusUnit::query()->findOrFail($syllabusUnit);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('syllabus_units', 'code')
                    ->where('school_id', $school->id)
                    ->where('subject_offering_id', $unit->subject_offering_id)
                    ->ignore($unit->id),
            ],
            'title' => ['sometimes', 'string', 'max:255'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(SyllabusUnit::STATUSES)],
        ]);

        $before = $unit->only(array_keys($validated));
        $offeringId = $unit->subject_offering_id;

        DB::transaction(function () use ($school, $unit, $validated, $before, $request, $audit) {
            $unit->update($validated);

            $audit->school($school, 'syllabus.unit.updated', actor: $request->user(), subject: $unit, metadata: [
                'unitId' => $unit->id,
                'subjectOfferingId' => $unit->subject_offering_id,
                'changedFields' => array_keys($validated),
                'before' => array_intersect_key($before, array_flip(['code', 'sequence', 'status'])),
                'after' => array_intersect_key($validated, array_flip(['code', 'sequence', 'status'])),
            ]);
        });

        $offering = SubjectOffering::query()->find($offeringId);

        return redirect()
            ->route('app.syllabus.index', [
                'academic_year_id' => $offering?->academic_year_id,
                'subject_offering_id' => $offeringId,
            ])
            ->with('status', 'Syllabus unit updated.');
    }
}
