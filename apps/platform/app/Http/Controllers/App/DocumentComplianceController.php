<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Documents\DocumentCompliance;
use App\Domain\Students\Application\Documents\DocumentComplianceCohort;
use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.5: the cohort document checklist -- states only (never a file,
 * title or download), whole-School `students.view` + `students.documents.view`
 * behind current MFA (route), decided again by DocumentComplianceCohort.
 * Browser-only: no export or print.
 */
class DocumentComplianceController extends Controller
{
    public function index(Request $request, TenantContext $context, DocumentComplianceCohort $cohort): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $cohort->authorize($school, $actor);
        $filters = $request->validate([
            'academic_year_id' => ['nullable', 'string', 'max:64'],
            'campus_id' => ['nullable', 'string', 'max:64'],
            'grade_level_id' => ['nullable', 'string', 'max:64'],
            'section_id' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', Rule::in(array_column(StudentDocumentCategory::requirableOptions(), 'value'))],
            'status' => ['nullable', Rule::in(array_keys(DocumentCompliance::STATE_LABELS))],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(array_keys(DocumentComplianceCohort::SORTS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $cohort->page($school, $actor, $filters, (int) ($filters['page'] ?? 1));

        return Inertia::render('App/Students/DocumentCompliance', [
            'year' => $result['year'],
            'rows' => $result['rows'],
            'options' => $result['options'],
            'sorts' => DocumentComplianceCohort::SORTS,
            'canManageRequirements' => Gate::forUser($actor)->allows('capability', ['students.documents.manage', $school]),
            'filters' => [
                'academic_year_id' => $result['year']['id'] ?? '',
                'campus_id' => $filters['campus_id'] ?? '',
                'grade_level_id' => $filters['grade_level_id'] ?? '',
                'section_id' => $filters['section_id'] ?? '',
                'category' => $filters['category'] ?? '',
                'status' => $filters['status'] ?? '',
                'q' => $filters['q'] ?? '',
                'sort' => $filters['sort'] ?? 'name',
            ],
        ]);
    }
}
