<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5B.1 §25/§26: the Student/Guardian search endpoints for the
 * Announcement composer's audience picker -- mirrors
 * App\Domain\Communications\Http\Controllers\CommunicationHubController::searchParticipants()'s
 * exact shape (same-School, `ilike` name search, capped, minimal safe
 * fields only).
 *
 * Deliberately gated by `communications.announce` -- NOT
 * `students.view`/`guardians.view` (root CLAUDE.md rule 24: a
 * capability check, never inferred from an unrelated module's
 * capability). An announcer who cannot view the Student/Guardian admin
 * module can still target one by name here; this endpoint returns only
 * what a communication composer is authorized to see (name/student
 * number identity), never a confidential field
 * (date_of_birth/GuardianContact values/etc, brief §25/§26).
 */
class CommunicationAudienceSearchController extends Controller
{
    use AuthorizesCapability;

    public function students(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $q = trim((string) $request->string('q'));

        $students = Student::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereRaw("(first_name || ' ' || coalesce(last_name, '')) ilike ?", ["%{$q}%"])
                    ->orWhere('student_number', 'ilike', "%{$q}%")))
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'student_number']);

        return response()->json([
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'label' => trim("{$s->first_name} {$s->last_name}")." ({$s->student_number})",
            ])->values(),
        ]);
    }

    public function guardians(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $q = trim((string) $request->string('q'));

        $guardians = Guardian::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query
                ->whereRaw("(first_name || ' ' || coalesce(last_name, '')) ilike ?", ["%{$q}%"]))
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name']);

        return response()->json([
            'guardians' => $guardians->map(fn (Guardian $g) => [
                'id' => $g->id,
                'label' => trim("{$g->first_name} {$g->last_name}"),
            ])->values(),
        ]);
    }

    /**
     * Phase 5B.3 §6/§34: the Announcement composer's GradeLevel
     * cohort-picker search -- same shape/gate as students()/guardians()
     * above. GradeLevel is School-wide reference data (not
     * AcademicYear-scoped), so no academic_year_id filter applies here;
     * `syncAcademicCohort()` is what actually ties a selection to an
     * AcademicYear.
     */
    public function gradeLevels(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $q = trim((string) $request->string('q'));

        $gradeLevels = GradeLevel::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('code', 'ilike', "%{$q}%")))
            ->orderBy('sequence')
            ->limit(20)
            ->get(['id', 'name', 'code']);

        return response()->json([
            'gradeLevels' => $gradeLevels->map(fn (GradeLevel $g) => [
                'id' => $g->id,
                'label' => "{$g->name} ({$g->code})",
            ])->values(),
        ]);
    }

    /**
     * Phase 5B.3 §7/§34: the Announcement composer's Section
     * cohort-picker search. `academic_year_id` is required -- a Section
     * belongs to exactly one AcademicYear, so an unscoped search would
     * be meaningless (and could otherwise surface a prior year's
     * Section, which syncAcademicCohort() would then reject anyway).
     */
    public function sections(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $academicYearId = trim((string) $request->string('academic_year_id'));
        $q = trim((string) $request->string('q'));

        if ($academicYearId === '') {
            return response()->json(['sections' => []]);
        }

        $sections = Section::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'active')
            ->with('gradeLevel:id,name')
            ->when($q !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('code', 'ilike', "%{$q}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code', 'grade_level_id']);

        return response()->json([
            'sections' => $sections->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => $s->gradeLevel !== null ? "{$s->gradeLevel->name} - {$s->name} ({$s->code})" : "{$s->name} ({$s->code})",
            ])->values(),
        ]);
    }

    /**
     * Phase 5C.1: the Announcement composer's SubjectOffering
     * cohort-picker search -- same shape/gate as gradeLevels()/
     * sections() above. `academic_year_id` is required for the same
     * reason sections() requires it: a SubjectOffering belongs to
     * exactly one AcademicYear, so an unscoped search would be
     * meaningless (and syncAcademicCohort() would reject a
     * wrong-year selection anyway). Only `active` offerings are
     * surfaced -- matching the GradeLevel/Section pickers' identical
     * "not selectable while inactive" gate. Exposes only safe
     * reference metadata (Subject name/code, GradeLevel, Campus,
     * required/elective) -- never Student membership; a School user
     * only sees a roster after actually creating/previewing the
     * audience.
     */
    public function subjectOfferings(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $academicYearId = trim((string) $request->string('academic_year_id'));
        $q = trim((string) $request->string('q'));

        if ($academicYearId === '') {
            return response()->json(['subjectOfferings' => []]);
        }

        $offerings = SubjectOffering::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'active')
            ->with(['subject:id,name,code', 'gradeLevel:id,name', 'campus:id,name'])
            ->when($q !== '', fn ($query) => $query->whereHas('subject', fn ($query) => $query
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('code', 'ilike', "%{$q}%")))
            ->orderBy('sequence')
            ->limit(20)
            ->get(['id', 'subject_id', 'grade_level_id', 'campus_id', 'is_required', 'sequence']);

        return response()->json([
            'subjectOfferings' => $offerings->map(fn (SubjectOffering $o) => [
                'id' => $o->id,
                'label' => trim(implode(' - ', array_filter([
                    $o->subject?->name !== null ? "{$o->subject->name} ({$o->subject->code})" : null,
                    $o->gradeLevel?->name,
                    $o->campus?->name,
                ]))).($o->is_required ? ' [required]' : ' [elective]'),
            ])->values(),
        ]);
    }
}
