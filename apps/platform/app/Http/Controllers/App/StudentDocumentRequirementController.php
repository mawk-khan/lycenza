<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Domain\Students\Application\Documents\StudentDocumentRequirementService;
use App\Domain\Students\Infrastructure\StudentDocumentRequirement;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.5: a School's document requirement rules (Settings-style admin
 * page). Thin: whole-School `students.documents.manage` is the route floor,
 * StudentDocumentRequirementService the one writer (re-checks it, validates
 * the closed dimensions, audits). Rules carry no person data; a rule is
 * advisory -- it never blocks admission, conversion or placement.
 */
class StudentDocumentRequirementController extends Controller
{
    public function index(TenantContext $context, StudentDocumentRequirementService $service): Response
    {
        $school = $context->requireSchool();
        $rules = $service->list($school);

        return $context->withSchool($school, function () use ($rules): Response {
            $years = AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'status']);
            $campuses = Campus::query()->orderBy('name')->get(['id', 'name']);
            $grades = GradeLevel::query()->orderBy('sequence')->get(['id', 'name']);
            $yearNames = $years->pluck('name', 'id');
            $campusNames = $campuses->pluck('name', 'id');
            $gradeNames = $grades->pluck('name', 'id');

            return Inertia::render('App/Students/DocumentRequirements', [
                'requirements' => $rules->map(fn (StudentDocumentRequirement $r) => [
                    'id' => $r->id,
                    'subject' => $r->subject,
                    'subjectLabel' => $r->subject === StudentDocumentRequirement::APPLICANT ? 'Applicants' : 'Students',
                    'categoryLabel' => $r->category->label(),
                    'required' => $r->required,
                    'year' => $yearNames[$r->academic_year_id] ?? '—',
                    'campus' => $r->campus_id === null ? 'All Campuses' : ($campusNames[$r->campus_id] ?? '—'),
                    'grade' => $r->grade_level_id === null ? 'All Grades' : ($gradeNames[$r->grade_level_id] ?? '—'),
                    'effectiveFrom' => $r->effective_from->toDateString(),
                    'retiredAt' => $r->retired_at?->toIso8601String(),
                ])->values()->all(),
                'options' => [
                    'subjects' => [
                        ['value' => StudentDocumentRequirement::STUDENT, 'label' => 'Students (placed in the year)'],
                        ['value' => StudentDocumentRequirement::APPLICANT, 'label' => 'Applicants (applying for the year)'],
                    ],
                    'categories' => StudentDocumentCategory::requirableOptions(),
                    'years' => $years->where('status', '!=', 'closed')->map(fn ($y) => ['value' => $y->id, 'label' => $y->name])->values()->all(),
                    'campuses' => $campuses->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
                    'grades' => $grades->map(fn ($g) => ['value' => $g->id, 'label' => $g->name])->values()->all(),
                ],
            ]);
        });
    }

    public function store(Request $request, TenantContext $context, StudentDocumentRequirementService $service): RedirectResponse
    {
        $service->create($context->requireSchool(), $context->actor(), $request->only(['subject', 'category', 'required', 'academic_year_id', 'campus_id', 'grade_level_id', 'effective_from']));

        return redirect('/app/students/document-requirements')->with('status', 'Requirement added.');
    }

    public function retire(TenantContext $context, StudentDocumentRequirementService $service, string $requirement): RedirectResponse
    {
        $service->retire($context->requireSchool(), $context->actor(), $requirement);

        return redirect('/app/students/document-requirements')->with('status', 'Requirement retired.');
    }
}
