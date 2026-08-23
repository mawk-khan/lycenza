<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.9 -- the sole read path for the Employee Profile Workspace
 * (docs/modules/HR.md "Employee Profile Workspace (8A.9,
 * implemented)"). The Restricted-tier HR-internal counterpart to
 * `EmployeeDirectoryService` (8A.8) -- deliberately NOT a reuse of
 * `EmployeeDirectoryEntry`; every section here is its own explicit
 * DTO, built from an explicit, scoped query, never
 * `Employee::with([...everything...])->toArray()`.
 *
 * READ ONLY. No write/mutation method exists on this class and none
 * should ever be added here -- the existing per-domain services
 * (`EmployeePersonalDetailService`, `EmployeeAddressService`, etc.)
 * remain the sole write paths; a future authorized UI/controller calls
 * those directly, never through an aggregate "update everything"
 * method on this service.
 *
 * Tenant-safe resolution: `build()` takes the trusted `School` and a
 * raw Employee id string -- it resolves the Employee itself under
 * `TenantContext::withSchool($school, ...)` AND an explicit
 * `where('school_id', $school->id)`, returning `null` (not an
 * exception, not a distinguishing error) if no such Employee exists in
 * that School. A School B Employee id passed while resolving under
 * School A is indistinguishable from an id that does not exist at all
 * -- no cross-tenant existence oracle.
 *
 * Archived Employees ARE resolvable here (unlike
 * `EmployeeDirectoryService`'s default active-only filtering) --
 * historical HR access to a separated/archived Employee's full record
 * is a legitimate, expected HR use case, per docs/modules/HR.md.
 *
 * Highly Sensitive `EmployeeDocument` rows are excluded at the
 * DATABASE QUERY level (`where('classification_tier', 'restricted')`)
 * -- never fetched, let alone filtered out afterward. This is the
 * core 8A.9 acceptance gate: the read model itself must omit them, not
 * a serializer or a future UI layer.
 *
 * Batch-hydration for Assignments' Department/Position/Campus, exactly
 * like `EmployeeDirectoryService::hydrate()` -- a handful of `whereIn`
 * queries, never one query per Assignment. Manager resolution is
 * exactly one hop from the current primary Assignment's live
 * `manager_assignment_id` pointer (8A.5) -- no recursion, no
 * historical-manager reconstruction claim.
 */
class EmployeeProfileWorkspaceService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function build(School $school, string $employeeId): ?EmployeeProfileWorkspace
    {
        return $this->context->withSchool($school, fn () => $this->buildWithinContext($school, $employeeId));
    }

    private function buildWithinContext(School $school, string $employeeId): ?EmployeeProfileWorkspace
    {
        $employee = Employee::query()->where('school_id', $school->id)->find($employeeId);

        if ($employee === null) {
            return null;
        }

        $today = Carbon::today()->toDateString();

        $personalDetail = EmployeePersonalDetail::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->first();

        $addresses = EmployeeAddress::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderBy('address_type')
            ->orderBy('id')
            ->get();

        $emergencyContacts = EmployeeEmergencyContact::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $employments = EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get();

        $employmentIds = $employments->pluck('id')->all();

        $assignments = $employmentIds === [] ? collect() : EmployeeAssignment::query()
            ->where('school_id', $school->id)
            ->whereIn('employment_record_id', $employmentIds)
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get();

        $departmentIds = $assignments->pluck('department_id')->filter()->unique()->all();
        $positionIds = $assignments->pluck('position_id')->filter()->unique()->all();
        $campusIds = $assignments->pluck('campus_id')->filter()->unique()->all();

        $departmentsById = $departmentIds === [] ? collect() : Department::query()->where('school_id', $school->id)->whereIn('id', $departmentIds)->get()->keyBy('id');
        $positionsById = $positionIds === [] ? collect() : Position::query()->where('school_id', $school->id)->whereIn('id', $positionIds)->get()->keyBy('id');
        $campusesById = $campusIds === [] ? collect() : Campus::query()->where('school_id', $school->id)->whereIn('id', $campusIds)->get()->keyBy('id');

        $currentEmployment = $employments->first(fn (EmploymentRecord $e) => $this->isCurrentInterval($e->starts_on->toDateString(), $e->ends_on?->toDateString(), $today));

        $currentAssignment = $currentEmployment === null ? null : $assignments->first(
            fn (EmployeeAssignment $a) => $a->employment_record_id === $currentEmployment->id
                && $a->is_primary
                && $this->isCurrentInterval($a->starts_on->toDateString(), $a->ends_on?->toDateString(), $today),
        );

        $currentDepartment = $currentAssignment?->department_id ? $departmentsById->get($currentAssignment->department_id) : null;
        $currentPosition = $currentAssignment?->position_id ? $positionsById->get($currentAssignment->position_id) : null;
        $currentCampus = $currentAssignment?->campus_id ? $campusesById->get($currentAssignment->campus_id) : null;

        $manager = $this->resolveManager($school, $currentAssignment);

        $qualifications = EmployeeQualification::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get();

        $experience = EmployeeExperience::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get();

        $certifications = EmployeeCertification::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('issued_on')
            ->orderBy('id')
            ->get();

        $documents = EmployeeDocument::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employee->id)
            ->where('classification_tier', 'restricted')
            ->orderByDesc('uploaded_at')
            ->orderBy('id')
            ->get();

        return new EmployeeProfileWorkspace(
            summary: new EmployeeProfileSummary(
                employeeId: $employee->id,
                employeeNumber: $employee->employee_number,
                displayName: $employee->full_name,
                employeeRecordStatus: $employee->record_status,
                userLinked: $employee->user_id !== null,
                currentEmploymentStatus: $currentEmployment?->status,
                positionId: $currentPosition?->id,
                positionName: $currentPosition?->name,
                departmentId: $currentDepartment?->id,
                departmentName: $currentDepartment?->name,
                campusId: $currentCampus?->id,
                campusName: $currentCampus?->name,
                managerEmployeeId: $manager?->id,
                managerEmployeeNumber: $manager?->employee_number,
                managerDisplayName: $manager?->full_name,
            ),
            personalDetails: $personalDetail === null ? null : new EmployeeProfilePersonalDetails(
                dateOfBirth: $personalDetail->date_of_birth?->toDateString(),
                nationality: $personalDetail->nationality,
                maritalStatus: $personalDetail->marital_status,
                preferredLanguage: $personalDetail->preferred_language,
            ),
            contact: $personalDetail === null ? null : new EmployeeProfileContact(
                personalEmail: $personalDetail->personal_email,
                personalPhone: $personalDetail->personal_phone,
                alternatePhone: $personalDetail->alternate_phone,
            ),
            addresses: $addresses->map(fn (EmployeeAddress $a) => new EmployeeProfileAddressEntry(
                id: $a->id,
                addressType: $a->address_type,
                addressLine1: $a->address_line1,
                addressLine2: $a->address_line2,
                city: $a->city,
                stateRegion: $a->state_region,
                postalCode: $a->postal_code,
                countryCode: $a->country_code,
            ))->all(),
            emergencyContacts: $emergencyContacts->map(fn (EmployeeEmergencyContact $c) => new EmployeeProfileEmergencyContactEntry(
                id: $c->id,
                name: $c->name,
                relationship: $c->relationship,
                phone: $c->phone,
                alternatePhone: $c->alternate_phone,
                email: $c->email,
                isPrimary: $c->is_primary,
            ))->all(),
            employmentHistory: $employments->map(fn (EmploymentRecord $e) => new EmployeeProfileEmploymentEntry(
                id: $e->id,
                employmentType: $e->employment_type,
                startsOn: $e->starts_on->toDateString(),
                endsOn: $e->ends_on?->toDateString(),
                probationEndsOn: $e->probation_ends_on?->toDateString(),
                status: $e->status,
                isCurrent: $currentEmployment !== null && $e->id === $currentEmployment->id,
            ))->all(),
            assignments: $assignments->map(function (EmployeeAssignment $a) use ($departmentsById, $positionsById, $campusesById, $currentAssignment) {
                $department = $a->department_id ? $departmentsById->get($a->department_id) : null;
                $position = $a->position_id ? $positionsById->get($a->position_id) : null;
                $campus = $a->campus_id ? $campusesById->get($a->campus_id) : null;

                return new EmployeeProfileAssignmentEntry(
                    id: $a->id,
                    employmentRecordId: $a->employment_record_id,
                    isPrimary: $a->is_primary,
                    startsOn: $a->starts_on->toDateString(),
                    endsOn: $a->ends_on?->toDateString(),
                    isCurrent: $currentAssignment !== null && $a->id === $currentAssignment->id,
                    positionId: $position?->id,
                    positionName: $position?->name,
                    departmentId: $department?->id,
                    departmentName: $department?->name,
                    campusId: $campus?->id,
                    campusName: $campus?->name,
                );
            })->all(),
            qualifications: $qualifications->map(fn (EmployeeQualification $q) => new EmployeeProfileQualificationEntry(
                id: $q->id,
                qualificationType: $q->qualification_type,
                qualificationName: $q->qualification_name,
                specialization: $q->specialization,
                institution: $q->institution,
                awardingBody: $q->awarding_body,
                countryCode: $q->country_code,
                startsOn: $q->starts_on?->toDateString(),
                completedOn: $q->completed_on?->toDateString(),
                gradeOrResult: $q->grade_or_result,
                verificationStatus: $q->verification_status,
                verifiedAt: $q->verified_at?->toIso8601String(),
            ))->all(),
            experience: $experience->map(fn (EmployeeExperience $x) => new EmployeeProfileExperienceEntry(
                id: $x->id,
                organization: $x->organization,
                jobTitle: $x->job_title,
                startsOn: $x->starts_on->toDateString(),
                endsOn: $x->ends_on?->toDateString(),
                description: $x->description,
                location: $x->location,
                countryCode: $x->country_code,
            ))->all(),
            certifications: $certifications->map(fn (EmployeeCertification $c) => new EmployeeProfileCertificationEntry(
                id: $c->id,
                name: $c->name,
                issuer: $c->issuer,
                credentialNumber: $c->credential_number,
                issuedOn: $c->issued_on?->toDateString(),
                expiresOn: $c->expires_on?->toDateString(),
                verificationStatus: $c->verification_status,
                verifiedAt: $c->verified_at?->toIso8601String(),
            ))->all(),
            documents: $documents->map(fn (EmployeeDocument $d) => new EmployeeProfileDocumentEntry(
                id: $d->id,
                category: $d->category,
                classificationTier: $d->classification_tier,
                issuedOn: $d->issued_on?->toDateString(),
                expiresOn: $d->expires_on?->toDateString(),
                status: $d->status,
            ))->all(),
        );
    }

    private function isCurrentInterval(?string $startsOn, ?string $endsOn, string $today): bool
    {
        if ($startsOn === null || $startsOn > $today) {
            return false;
        }

        return $endsOn === null || $endsOn >= $today;
    }

    private function resolveManager(School $school, ?EmployeeAssignment $currentAssignment): ?Employee
    {
        if ($currentAssignment === null || $currentAssignment->manager_assignment_id === null) {
            return null;
        }

        $managerAssignment = EmployeeAssignment::query()
            ->where('school_id', $school->id)
            ->find($currentAssignment->manager_assignment_id);

        if ($managerAssignment === null) {
            return null;
        }

        $managerEmployment = EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->find($managerAssignment->employment_record_id);

        if ($managerEmployment === null) {
            return null;
        }

        return Employee::query()->where('school_id', $school->id)->find($managerEmployment->employee_id);
    }
}
