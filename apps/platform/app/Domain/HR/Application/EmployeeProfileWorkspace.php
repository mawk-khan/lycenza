<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- the complete Employee Profile Workspace read model:
 * the Restricted-tier HR-internal view of one Employee, organized into
 * explicit sections (docs/modules/HR.md "Employee Profile Workspace
 * (8A.9, implemented)"). Never constructed from
 * `Employee::with([...everything...])->toArray()` -- every section is
 * an explicit DTO (or list of them) built by
 * `EmployeeProfileWorkspaceService`, and this class's own `toArray()`
 * is the complete, exhaustive top-level contract.
 *
 * Optional singleton sections (`personalDetails`/`contact`) are `null`
 * when the Employee has no corresponding row -- never an error.
 * Repeatable sections are always an array, `[]` when empty.
 *
 * Deliberately excludes anything Highly Sensitive (no government IDs/
 * bank details/health data exist in the underlying schema to expose in
 * the first place) and any `highly_sensitive`-classified
 * `EmployeeDocument` row (excluded at the query level inside the
 * service, never merely filtered out here).
 */
final class EmployeeProfileWorkspace
{
    /**
     * @param  array<int, EmployeeProfileAddressEntry>  $addresses
     * @param  array<int, EmployeeProfileEmergencyContactEntry>  $emergencyContacts
     * @param  array<int, EmployeeProfileEmploymentEntry>  $employmentHistory
     * @param  array<int, EmployeeProfileAssignmentEntry>  $assignments
     * @param  array<int, EmployeeProfileQualificationEntry>  $qualifications
     * @param  array<int, EmployeeProfileExperienceEntry>  $experience
     * @param  array<int, EmployeeProfileCertificationEntry>  $certifications
     * @param  array<int, EmployeeProfileDocumentEntry>  $documents
     */
    public function __construct(
        public readonly EmployeeProfileSummary $summary,
        public readonly ?EmployeeProfilePersonalDetails $personalDetails,
        public readonly ?EmployeeProfileContact $contact,
        public readonly array $addresses,
        public readonly array $emergencyContacts,
        public readonly array $employmentHistory,
        public readonly array $assignments,
        public readonly array $qualifications,
        public readonly array $experience,
        public readonly array $certifications,
        public readonly array $documents,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary->toArray(),
            'personal_details' => $this->personalDetails?->toArray(),
            'contact' => $this->contact?->toArray(),
            'addresses' => array_map(fn (EmployeeProfileAddressEntry $e) => $e->toArray(), $this->addresses),
            'emergency_contacts' => array_map(fn (EmployeeProfileEmergencyContactEntry $e) => $e->toArray(), $this->emergencyContacts),
            'employment_history' => array_map(fn (EmployeeProfileEmploymentEntry $e) => $e->toArray(), $this->employmentHistory),
            'assignments' => array_map(fn (EmployeeProfileAssignmentEntry $e) => $e->toArray(), $this->assignments),
            'qualifications' => array_map(fn (EmployeeProfileQualificationEntry $e) => $e->toArray(), $this->qualifications),
            'experience' => array_map(fn (EmployeeProfileExperienceEntry $e) => $e->toArray(), $this->experience),
            'certifications' => array_map(fn (EmployeeProfileCertificationEntry $e) => $e->toArray(), $this->certifications),
            'documents' => array_map(fn (EmployeeProfileDocumentEntry $e) => $e->toArray(), $this->documents),
        ];
    }
}
