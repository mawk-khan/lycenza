<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- EmployeeProfileWorkspaceService (8A.9) section-level
 * authorization. `hr.employees.personal.view` is the entry gate for the
 * whole workspace (summary + personal details/contact/addresses/
 * emergency contacts); `hr.employees.assignments.view`,
 * `.qualifications.view`, and `.documents.view` independently gate
 * their own sections, which are structurally ABSENT (not merely
 * hidden) without the matching capability. Complements
 * EmployeeProfileWorkspaceServiceTest (8A.9's own shape/content proof,
 * built with an actor holding every capability, unaffected by this
 * checkpoint).
 */
class HrProfileAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function personal_view_alone_grants_summary_and_personal_sections_but_not_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => 'asha@example.com']);
        $this->createEmployeeAddress($employee);
        $this->createEmployeeEmergencyContact($employee);
        $this->createEmployeeQualification($employee);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => now()->subYear()->toDateString(), 'ends_on' => null]);
        $position = $this->createPosition($school);
        $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => null]);

        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertNotNull($profile);
        $this->assertSame($employee->id, $profile->summary->employeeId);
        $this->assertNotNull($profile->personalDetails);
        $this->assertNotNull($profile->contact);
        $this->assertSame('asha@example.com', $profile->contact->personalEmail);
        $this->assertCount(1, $profile->addresses);
        $this->assertCount(1, $profile->emergencyContacts);

        // Gated sections are structurally absent without their own capability.
        $this->assertSame([], $profile->employmentHistory);
        $this->assertSame([], $profile->assignments);
        $this->assertSame([], $profile->qualifications);
        $this->assertSame([], $profile->documents);
    }

    #[Test]
    public function without_personal_view_the_whole_workspace_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, [
            'hr.employees.assignments.view', 'hr.employees.qualifications.view', 'hr.employees.documents.view',
        ]);

        $this->expectException(AuthorizationException::class);

        app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);
    }

    #[Test]
    public function assignments_view_additionally_reveals_employment_and_assignment_history(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => now()->subYear()->toDateString(), 'ends_on' => null]);
        $position = $this->createPosition($school);
        $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => null]);

        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.assignments.view']);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertCount(1, $profile->employmentHistory);
        $this->assertCount(1, $profile->assignments);
    }

    #[Test]
    public function qualifications_view_additionally_reveals_professional_records(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeQualification($employee);
        $this->createEmployeeExperience($employee);
        $this->createEmployeeCertification($employee);

        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.qualifications.view']);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertCount(1, $profile->qualifications);
        $this->assertCount(1, $profile->experience);
        $this->assertCount(1, $profile->certifications);
    }

    #[Test]
    public function documents_view_additionally_reveals_restricted_document_metadata_only(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'category' => 'id_proof']);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive', 'category' => 'background_check']);

        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertCount(1, $profile->documents, 'Only the Restricted document must appear -- Highly Sensitive stays excluded regardless of documents.view.');
        $this->assertSame('restricted', $profile->documents[0]->classificationTier);
    }

    #[Test]
    public function no_sensitive_document_side_channel_leaks_through_the_general_profile(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted', 'category' => 'id_proof']);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'category' => 'background_check',
            'original_filename' => 'sentinel-secret-file.pdf',
        ]);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'category' => 'background_check',
            'original_filename' => 'sentinel-secret-file-2.pdf',
        ]);

        // Full profile access EXCEPT sensitive.view.
        $actor = $this->createUserWithCapabilities($school, [
            'hr.employees.personal.view', 'hr.employees.assignments.view',
            'hr.employees.qualifications.view', 'hr.employees.documents.view',
        ]);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);
        $serialized = json_encode($profile->toArray());

        $this->assertCount(1, $profile->documents, 'Exactly the one Restricted document, never a count that includes the two Highly Sensitive ones.');
        $this->assertStringNotContainsString('sentinel-secret-file', $serialized);
        $this->assertStringNotContainsString('background_check', $serialized, 'The Highly Sensitive document\'s category must not leak even though a different category value is a legitimate Restricted-tier value elsewhere.');
    }
}
