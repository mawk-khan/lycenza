<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Http\Controllers\App\Concerns\MapsAcademicStructureErrors;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SWC.2 (ADR 0073 section 10): creating and changing Subject Offerings from
 * the browser -- the mutations beside the read-only roster workspace
 * (SubjectOfferingController). Both go through SubjectOfferingService, the
 * same code `/api/v1` uses; the classification freeze (ADR 0069) is the
 * service's and the database's, so a locked change is a form error here,
 * however the request was made. Deactivation is `status`, never a delete.
 *
 * Duplicate submissions (rule 29): an Offering is unique per (Year, Campus,
 * Grade, Subject), so a repeated create is a field error; updates converge.
 */
class SubjectOfferingAdminController extends Controller
{
    use AuthorizesCapability, MapsAcademicStructureErrors;

    public function store(Request $request, TenantContext $context, SubjectOfferingService $offerings): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $request->validate(['academic_year_id' => ['required', 'string']]);

        $offering = $offerings->create(
            $school,
            (string) $request->input('academic_year_id'),
            $request->only(['campus_id', 'grade_level_id', 'subject_id', 'is_required', 'sequence', 'weekly_periods_target']),
            $context->actor(),
        );

        return redirect()->route('app.subject-offerings.show', $offering->id);
    }

    public function update(Request $request, TenantContext $context, SubjectOfferingService $offerings, string $subjectOffering): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $this->withFormErrors('is_required', fn () => $offerings->updateFromInput(
            $school,
            $subjectOffering,
            $request->only(SubjectOfferingService::MUTABLE_FIELDS),
            $context->actor(),
        ));

        return redirect()->route('app.subject-offerings.show', $subjectOffering);
    }
}
