<?php

namespace App\Domain\HR\Application\Profile;

use App\Domain\HR\Application\EmployeeProfileEmploymentEntry;
use App\Domain\HR\Application\EmployeeProfileExperienceEntry;
use App\Domain\HR\Application\EmployeeProfileWorkspace;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Domain\HR\Infrastructure\EmployeeReferenceCode;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;

/**
 * HR.2A: the Staff Profile's HR.2A additions, beside the 8A.9 workspace
 * (which already decides section authority). Each part is ABSENT unless the
 * reader holds that section's capability -- never blanked:
 *
 *   referenceCodes     the Profile reader (the workspace already required
 *                      `hr.employees.personal.view`)
 *   experience         previous: `hr.employees.qualifications.view` (the
 *                      section that lists the records); service in this
 *                      School: whole-School `hr.employees.assignments.view`
 *                      (the employment tier) -- both DERIVED
 *                      (ExperienceCalculator), never stored
 *   versions           the stale-form tokens, only for an editor of that
 *                      record (identity: whole-School `hr.employees.manage`;
 *                      personal: `hr.employees.personal.manage`)
 *   options            the closed catalogues the forms offer (code-owned)
 *
 * A constant number of queries (one for reference codes, one for the
 * personal row when a token is due) whatever the history length. Reads
 * inside the target School's context only.
 */
final class StaffProfileReader
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly HrEmployeeAccess $access,
    ) {}

    /** @return array<string, mixed> */
    /** `$withFormOptions`: the closed catalogues the web forms offer (absent from the API). */
    public function forEmployee(School $school, EmployeeProfileWorkspace $workspace, User $actor, bool $withFormOptions = true): array
    {
        $employeeId = $workspace->summary->employeeId;
        $today = SchoolTimezone::today($school);
        $canQualifications = $this->capabilities->canInSchool($actor, 'hr.employees.qualifications.view', $school);
        $canEmployment = $this->access->holdsWholeSchool($actor, 'hr.employees.assignments.view', $school);
        $canManageIdentity = $this->access->holdsWholeSchool($actor, 'hr.employees.manage', $school);
        $canManagePersonal = $this->capabilities->canInSchool($actor, 'hr.employees.personal.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $workspace, $employeeId, $today, $canQualifications, $canEmployment, $canManageIdentity, $canManagePersonal, $withFormOptions): array {
            $codes = EmployeeReferenceCode::query()->where('school_id', $school->id)->where('employee_id', $employeeId)
                ->orderByRaw('retired_at IS NOT NULL')->orderBy('kind')->orderBy('created_at')->get();

            $experience = [];
            if ($canQualifications) {
                $experience['previous'] = ExperienceCalculator::previous(
                    array_map(fn (EmployeeProfileExperienceEntry $e) => [$e->startsOn, $e->endsOn], $workspace->experience),
                    $today,
                );
            }
            if ($canEmployment) {
                $experience['service'] = ExperienceCalculator::service(
                    array_values(array_map(fn (EmployeeProfileEmploymentEntry $e) => [$e->startsOn, $e->endsOn],
                        array_filter($workspace->employmentHistory, fn (EmployeeProfileEmploymentEntry $e) => $e->status !== 'draft'))),
                    $today,
                );
            }

            $versions = [];
            if ($canManageIdentity) {
                $employee = Employee::query()->where('school_id', $school->id)->findOrFail($employeeId);
                $versions['identity'] = EmployeeProfileVersion::identity($employee);
            }
            if ($canManagePersonal) {
                $versions['personal'] = EmployeeProfileVersion::personal($employeeId, EmployeePersonalDetail::query()->where('employee_id', $employeeId)->first());
            }

            return [
                'referenceCodes' => $codes->map(fn (EmployeeReferenceCode $c) => [
                    'id' => $c->id,
                    'kind' => $c->kind,
                    'kindLabel' => EmployeeReferenceKind::tryFrom($c->kind)?->label() ?? $c->kind,
                    'value' => $c->value,
                    'retired' => $c->retired_at !== null,
                    'retireReasonLabel' => $c->retire_reason === null ? null : (ReferenceCodeRetireReason::tryFrom($c->retire_reason)?->label() ?? $c->retire_reason),
                    'recordedOn' => $c->created_at->toDateString(),
                ])->values()->all(),
                'experience' => $experience,
                'versions' => $versions,
                'can' => ['manageReferenceCodes' => $canManageIdentity],
            ] + (! $withFormOptions ? [] : [
                'options' => [
                    'documentCategories' => EmployeeDocumentCategory::options(),
                    'archiveReasons' => ProfileRecordArchiveReason::options(),
                    'referenceKinds' => EmployeeReferenceKind::options(),
                    'retireReasons' => ReferenceCodeRetireReason::options(),
                ],
            ]);
        });
    }
}
