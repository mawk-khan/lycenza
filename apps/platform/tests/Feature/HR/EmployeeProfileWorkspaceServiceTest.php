<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeProfileWorkspace;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.9: proves the Employee Profile Workspace read model is a
 * deliberate, section-by-section Restricted-tier disclosure boundary
 * -- exact allow-listed shapes per section, Highly Sensitive
 * EmployeeDocument rows excluded at the query level, correct current-
 * vs-history temporal selection, rehire/multiple-assignment history
 * preservation (unlike Directory, which collapses to current-only),
 * one-hop manager projection, and fail-closed tenant isolation with no
 * cross-tenant existence oracle.
 */
class EmployeeProfileWorkspaceServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Profile root -------------------------------------------------

    #[Test]
    public function employee_identity_is_correct_in_the_summary(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['full_name' => 'Priya Nair', 'employee_number' => 'EMP-000077']);

        $workspace = $this->build($school, $employee->id);

        $this->assertSame($employee->id, $workspace->summary->employeeId);
        $this->assertSame('EMP-000077', $workspace->summary->employeeNumber);
        $this->assertSame('Priya Nair', $workspace->summary->displayName);
        $this->assertSame('active', $workspace->summary->employeeRecordStatus);
    }

    #[Test]
    public function an_employee_with_no_linked_user_account_is_supported(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['user_id' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertFalse($workspace->summary->userLinked);
    }

    #[Test]
    public function an_archived_employee_profile_is_still_readable(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['record_status' => 'archived']);

        $workspace = $this->build($school, $employee->id);

        $this->assertNotNull($workspace);
        $this->assertSame('archived', $workspace->summary->employeeRecordStatus);
    }

    #[Test]
    public function missing_optional_sections_do_not_prevent_the_profile_from_building(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $workspace = $this->build($school, $employee->id);

        $this->assertNull($workspace->personalDetails);
        $this->assertNull($workspace->contact);
        $this->assertSame([], $workspace->addresses);
        $this->assertSame([], $workspace->emergencyContacts);
        $this->assertSame([], $workspace->employmentHistory);
        $this->assertSame([], $workspace->assignments);
        $this->assertSame([], $workspace->qualifications);
        $this->assertSame([], $workspace->experience);
        $this->assertSame([], $workspace->certifications);
        $this->assertSame([], $workspace->documents);
    }

    #[Test]
    public function the_summary_section_has_exactly_the_approved_keys(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $workspace = $this->build($school, $employee->id);

        $this->assertSame([
            'employee_id', 'employee_number', 'display_name', 'employee_record_status',
            'user_linked', 'current_employment_status', 'position_id', 'position_name',
            'department_id', 'department_name', 'campus_id', 'campus_name',
            'manager_employee_id', 'manager_employee_number', 'manager_display_name',
        ], array_keys($workspace->summary->toArray()));
    }

    // --- Personal / contact / addresses / emergency contacts -----------

    #[Test]
    public function personal_details_are_projected_explicitly(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeePersonalDetail($employee, [
                'date_of_birth' => '1990-03-15',
                'nationality' => 'Indian',
                'marital_status' => 'single',
                'preferred_language' => 'English',
            ]);
        });

        $workspace = $this->build($school, $employee->id);

        $this->assertSame(['date_of_birth', 'nationality', 'marital_status', 'preferred_language'], array_keys($workspace->personalDetails->toArray()));
        $this->assertSame('1990-03-15', $workspace->personalDetails->dateOfBirth);
        $this->assertSame('Indian', $workspace->personalDetails->nationality);
    }

    #[Test]
    public function contact_is_projected_explicitly_and_separately_from_personal_details(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeePersonalDetail($employee, [
                'personal_email' => 'priya@example.com',
                'personal_phone' => '9999999999',
                'alternate_phone' => '8888888888',
            ]);
        });

        $workspace = $this->build($school, $employee->id);

        $this->assertSame(['personal_email', 'personal_phone', 'alternate_phone'], array_keys($workspace->contact->toArray()));
        $this->assertSame('priya@example.com', $workspace->contact->personalEmail);
        $this->assertArrayNotHasKey('personal_email', $workspace->personalDetails->toArray(), 'Contact fields must never appear inside the Personal Details section.');
    }

    #[Test]
    public function addresses_are_projected_explicitly_and_ordered_deterministically(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeeAddress($employee, ['address_type' => 'permanent', 'address_line1' => 'Permanent Line']);
            $this->createEmployeeAddress($employee, ['address_type' => 'current', 'address_line1' => 'Current Line']);
        });

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->addresses);
        $this->assertSame(['id', 'address_type', 'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code'], array_keys($workspace->addresses[0]->toArray()));
        $this->assertSame('current', $workspace->addresses[0]->addressType, 'Addresses must be ordered by address_type then id.');
    }

    #[Test]
    public function emergency_contacts_are_projected_explicitly_with_primary_first(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        app(TenantContext::class)->withSchool($school, function () use ($employee) {
            $this->createEmployeeEmergencyContact($employee, ['name' => 'Zed Contact', 'is_primary' => false]);
            $this->createEmployeeEmergencyContact($employee, ['name' => 'Alice Primary', 'is_primary' => true]);
        });

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->emergencyContacts);
        $this->assertSame(['id', 'name', 'relationship', 'phone', 'alternate_phone', 'email', 'is_primary'], array_keys($workspace->emergencyContacts[0]->toArray()));
        $this->assertTrue($workspace->emergencyContacts[0]->isPrimary, 'The primary emergency contact must be ordered first.');
    }

    // --- Employment history --------------------------------------------

    #[Test]
    public function all_employment_records_are_represented_in_deterministic_order(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['starts_on' => '2015-01-01', 'ends_on' => '2018-01-01']);
        $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->employmentHistory);
        $this->assertSame('2020-01-01', $workspace->employmentHistory[0]->startsOn, 'Employment history must be ordered starts_on DESC.');
        $this->assertSame(['id', 'employment_type', 'starts_on', 'ends_on', 'probation_ends_on', 'status', 'is_current'], array_keys($workspace->employmentHistory[0]->toArray()));
    }

    #[Test]
    public function the_currently_effective_employment_drives_the_summary_and_is_flagged_current(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['starts_on' => '2015-01-01', 'ends_on' => '2018-01-01', 'status' => 'terminated']);
        $current = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null, 'status' => 'active']);

        $workspace = $this->build($school, $employee->id);

        $this->assertSame('active', $workspace->summary->currentEmploymentStatus);

        $currentEntry = collect($workspace->employmentHistory)->firstWhere('id', $current->id);
        $this->assertTrue($currentEntry->isCurrent);
        $historicalEntry = collect($workspace->employmentHistory)->first(fn ($e) => $e->id !== $current->id);
        $this->assertFalse($historicalEntry->isCurrent);
    }

    #[Test]
    public function a_future_employment_record_is_not_treated_as_current(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['starts_on' => now()->addYear()->toDateString(), 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertNull($workspace->summary->currentEmploymentStatus);
        $this->assertFalse($workspace->employmentHistory[0]->isCurrent);
    }

    // --- Assignment history ----------------------------------------------

    #[Test]
    public function all_assignments_including_secondary_are_represented_with_correct_placement(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $department = $this->createDepartment($school);
        $primaryPosition = $this->createPosition($school, ['code' => 'PRIM']);
        $secondaryPosition = $this->createPosition($school, ['code' => 'SEC']);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $primaryPosition, ['campus_id' => $campus->id, 'department_id' => $department->id, 'is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $secondaryPosition, ['is_primary' => false, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->assignments, 'Profile Workspace must show ALL Assignments, including secondary ones -- unlike Directory.');
        $this->assertSame(['id', 'employment_record_id', 'is_primary', 'starts_on', 'ends_on', 'is_current', 'position_id', 'position_name', 'department_id', 'department_name', 'campus_id', 'campus_name'], array_keys($workspace->assignments[0]->toArray()));

        $primaryEntry = collect($workspace->assignments)->first(fn ($a) => $a->isPrimary);
        $this->assertSame($primaryPosition->id, $primaryEntry->positionId);
        $this->assertSame($primaryPosition->id, $workspace->summary->positionId, 'The primary Assignment must drive the summary, never a secondary one.');
        $this->assertSame($campus->id, $primaryEntry->campusId);
        $this->assertSame($department->id, $primaryEntry->departmentId);
    }

    #[Test]
    public function historical_assignments_remain_in_the_profile_and_are_not_flagged_current(): void
    {
        $school = $this->createSchool();
        $positionOld = $this->createPosition($school, ['code' => 'OLD']);
        $positionNew = $this->createPosition($school, ['code' => 'NEW']);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2018-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $positionOld, ['is_primary' => true, 'starts_on' => '2018-01-01', 'ends_on' => '2020-01-01']);
        $this->createEmployeeAssignment($employment, $positionNew, ['is_primary' => true, 'starts_on' => '2020-01-02', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->assignments, 'Historical Assignments must remain visible in the Profile Workspace.');
        $this->assertSame($positionNew->id, $workspace->summary->positionId);
    }

    // --- Rehire ---------------------------------------------------------

    #[Test]
    public function rehire_produces_two_employment_entries_under_one_employee_profile(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmploymentRecord($employee, ['starts_on' => '2015-01-01', 'ends_on' => '2018-01-01']);
        $second = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertSame($employee->id, $workspace->summary->employeeId);
        $this->assertCount(2, $workspace->employmentHistory);
        $this->assertTrue(collect($workspace->employmentHistory)->firstWhere('id', $second->id)->isCurrent);
    }

    // --- Reporting manager ------------------------------------------------

    #[Test]
    public function the_current_managers_directory_safe_identity_is_shown_one_hop(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);

        $managerEmployee = $this->createEmployee($school, ['full_name' => 'Manager Person']);
        $managerEmployment = $this->createEmploymentRecord($managerEmployee, ['starts_on' => '2019-01-01', 'ends_on' => null]);
        $managerAssignment = $this->createEmployeeAssignment($managerEmployment, $position, ['is_primary' => true, 'starts_on' => '2019-01-01', 'ends_on' => null]);

        $subordinateEmployee = $this->createEmployee($school, ['full_name' => 'Subordinate Person']);
        $subordinateEmployment = $this->createEmploymentRecord($subordinateEmployee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $subordinateAssignment = $this->createEmployeeAssignment($subordinateEmployment, $position, ['is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $actor = $this->fullHrActor($school);
        app(TenantContext::class)->withSchool($school, function () use ($subordinateAssignment, $managerAssignment, $actor) {
            app(ReportingHierarchyService::class)->setManager($subordinateAssignment->fresh(), $managerAssignment->fresh(), $actor);
        });

        $workspace = $this->build($school, $subordinateEmployee->id);

        $this->assertSame($managerEmployee->id, $workspace->summary->managerEmployeeId);
        $this->assertSame('Manager Person', $workspace->summary->managerDisplayName);
    }

    #[Test]
    public function assignment_entries_never_carry_manager_information(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $keys = array_keys($workspace->assignments[0]->toArray());
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('manager', $key, 'Assignment history entries must never carry manager fields -- manager disclosure lives only on the summary.');
        }
    }

    // --- Professional records --------------------------------------------

    #[Test]
    public function qualifications_are_projected_explicitly(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeQualification($employee, ['qualification_name' => 'Bachelor of Education', 'verification_status' => 'verified']);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->qualifications);
        $this->assertSame([
            'id', 'qualification_type', 'qualification_name', 'specialization', 'institution',
            'awarding_body', 'country_code', 'starts_on', 'completed_on', 'grade_or_result',
            'verification_status', 'verified_at',
        ], array_keys($workspace->qualifications[0]->toArray()));
        $this->assertSame('Bachelor of Education', $workspace->qualifications[0]->qualificationName);
        $this->assertSame('verified', $workspace->qualifications[0]->verificationStatus);
    }

    #[Test]
    public function experience_is_projected_separately_from_employment_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeExperience($employee, ['organization' => 'External Corp', 'job_title' => 'Consultant']);
        $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->experience);
        $this->assertSame(['id', 'organization', 'job_title', 'starts_on', 'ends_on', 'description', 'location', 'country_code'], array_keys($workspace->experience[0]->toArray()));
        $this->assertSame('External Corp', $workspace->experience[0]->organization);
        $this->assertCount(1, $workspace->employmentHistory, 'External Experience must never be merged into Employment history.');
    }

    #[Test]
    public function certifications_are_projected_explicitly_with_actual_verification_state(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeCertification($employee, ['name' => 'First Aid', 'credential_number' => 'CERT-123', 'verification_status' => 'rejected']);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->certifications);
        $this->assertSame(['id', 'name', 'issuer', 'credential_number', 'issued_on', 'expires_on', 'verification_status', 'verified_at'], array_keys($workspace->certifications[0]->toArray()));
        $this->assertSame('CERT-123', $workspace->certifications[0]->credentialNumber);
        $this->assertSame('rejected', $workspace->certifications[0]->verificationStatus);
    }

    // --- Documents --------------------------------------------------------

    #[Test]
    public function restricted_document_metadata_is_projected_with_only_the_approved_safe_shape(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['category' => 'id_proof', 'classification_tier' => 'restricted', 'status' => 'active']);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->documents);
        $this->assertSame(['id', 'category', 'classification_tier', 'issued_on', 'expires_on', 'status'], array_keys($workspace->documents[0]->toArray()));
        $this->assertSame('restricted', $workspace->documents[0]->classificationTier);
    }

    #[Test]
    public function highly_sensitive_documents_are_excluded_entirely_from_the_profile_workspace(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['category' => 'id_proof', 'classification_tier' => 'restricted']);
        $this->createEmployeeDocument($employee, ['category' => 'background_check', 'classification_tier' => 'highly_sensitive']);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->documents, 'Highly Sensitive documents must be excluded at the query level, never merely filtered.');
        $this->assertSame('restricted', $workspace->documents[0]->classificationTier);
        foreach ($workspace->documents as $document) {
            $this->assertNotSame('highly_sensitive', $document->classificationTier);
        }
    }

    #[Test]
    public function document_entries_never_leak_storage_or_provenance_metadata(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'restricted',
            'original_filename' => 'SENTINEL-FILENAME.pdf',
            'storage_path' => 'SENTINEL-STORAGE-PATH',
            'storage_disk' => 'SENTINEL-DISK',
        ]);

        $workspace = $this->build($school, $employee->id);

        $keys = array_keys($workspace->documents[0]->toArray());
        foreach (['original_filename', 'mime_type', 'size_bytes', 'storage_disk', 'storage_path', 'uploaded_by_user_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Document entries must never expose {$forbidden}.");
        }

        $serialized = json_encode($workspace->documents[0]->toArray());
        $this->assertStringNotContainsString('SENTINEL-FILENAME', $serialized);
        $this->assertStringNotContainsString('SENTINEL-STORAGE-PATH', $serialized);
        $this->assertStringNotContainsString('SENTINEL-DISK', $serialized);
    }

    #[Test]
    public function both_active_and_archived_documents_appear_in_profile_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'status' => 'active']);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'status' => 'archived']);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(2, $workspace->documents, 'Document history is not silently hidden in the internal HR Profile Workspace.');
    }

    // --- Notes (Phase 8A closure correction) -----------------------------

    #[Test]
    public function notes_are_projected_with_only_the_approved_safe_shape_and_a_resolved_author_name(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $author = $this->createUser(['name' => 'Priya Nair']);
        $this->createEmployeeNote($employee, ['body' => 'Discussed probation review.', 'author_user_id' => $author->id]);

        $workspace = $this->build($school, $employee->id);

        $this->assertCount(1, $workspace->notes);
        $this->assertSame(['id', 'body', 'classification_tier', 'author_display_name', 'created_at'], array_keys($workspace->notes[0]->toArray()));
        $this->assertSame('Priya Nair', $workspace->notes[0]->authorDisplayName);
    }

    #[Test]
    public function notes_are_absent_without_notes_view_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeNote($employee);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $workspace = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertSame([], $workspace->notes, 'Notes must be entirely absent, not merely empty-looking, without hr.employees.notes.view.');
    }

    // --- Tenant isolation -----------------------------------------------

    #[Test]
    public function a_school_b_employee_id_cannot_be_read_from_school_a_context(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        app(TenantContext::class)->withSchool($schoolB, function () use ($employeeB) {
            $this->createEmployeePersonalDetail($employeeB, ['personal_email' => 'SENTINEL-CROSS-TENANT@example.com']);
        });

        $workspace = $this->build($schoolA, $employeeB->id);

        $this->assertNull($workspace, 'A different School\'s Employee must never be readable, and deep-populated data must never leak.');
    }

    #[Test]
    public function a_nonexistent_employee_id_and_a_cross_school_employee_id_are_indistinguishable(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $otherEmployee = $this->createEmployee($otherSchool);

        $resultForNonexistent = $this->build($school, (string) Str::orderedUuid());
        $resultForCrossSchool = $this->build($school, $otherEmployee->id);

        $this->assertNull($resultForNonexistent);
        $this->assertNull($resultForCrossSchool, 'A valid but cross-School Employee id must return the exact same null result as a nonexistent id -- no existence oracle.');
    }

    #[Test]
    public function tenant_context_is_restored_after_the_service_runs(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);

        app(TenantContext::class)->set($schoolB);

        app(EmployeeProfileWorkspaceService::class)->build($schoolA, $employeeA->id, $this->fullHrActor($schoolA));

        $this->assertSame($schoolB->id, app(TenantContext::class)->school()?->id, 'Ambient TenantContext must be restored to what it was before the service call.');
    }

    // --- Helpers ----------------------------------------------------------

    private function build(School $school, string $employeeId): ?EmployeeProfileWorkspace
    {
        return app(EmployeeProfileWorkspaceService::class)->build($school, $employeeId, $this->fullHrActor($school));
    }
}
