<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Application\Exceptions\DuplicateRelationshipException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.4: the ONLY sanctioned mutation path for
 * StudentGuardianRelationship -- never Student::guardians()->attach()/
 * Guardian::students()->attach()/sync() (Phase 1A.3's regression test,
 * StudentGuardianRelationshipTest::attach_is_not_the_supported_mutation_api_and_fails_closed,
 * remains the proof that path is intentionally unsafe). See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Relationship service").
 */
class StudentGuardianRelationshipServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function link_creates_a_relationship_without_making_it_primary(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);

        $relationship = app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::Mother);

        $this->assertSame($student->id, $relationship->student_id);
        $this->assertSame($guardian->id, $relationship->guardian_id);
        $this->assertSame(RelationshipType::Mother, $relationship->relationship_type);
        $this->assertFalse($relationship->is_primary);
    }

    #[Test]
    public function link_accepts_authority_flags(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);

        $relationship = app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::Grandparent, [
            'is_legal_guardian' => true,
            'is_emergency_contact' => true,
        ]);

        $this->assertTrue($relationship->is_legal_guardian);
        $this->assertTrue($relationship->is_emergency_contact);
        $this->assertFalse($relationship->is_authorized_pickup);
    }

    #[Test]
    public function a_guardian_can_be_linked_to_multiple_sibling_students(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $service = app(StudentGuardianRelationshipService::class);
        $service->link($studentA, $guardian, RelationshipType::Mother);
        $service->link($studentB, $guardian, RelationshipType::Mother);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentGuardianRelationship::query()->where('guardian_id', $guardian->id)->count());
        $this->assertSame(2, $count);
    }

    #[Test]
    public function a_duplicate_link_for_the_same_pair_is_rejected_with_a_domain_exception_not_a_raw_sql_error(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $service = app(StudentGuardianRelationshipService::class);
        $service->link($student, $guardian, RelationshipType::Mother);

        $this->expectException(DuplicateRelationshipException::class);

        $service->link($student, $guardian, RelationshipType::Other);
    }

    #[Test]
    public function a_cross_school_link_is_rejected_before_any_database_write(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($schoolB);

        $this->expectException(CrossSchoolRelationshipException::class);

        app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::Mother);
    }

    #[Test]
    public function set_primary_promotes_the_target_and_demotes_the_previous_primary_atomically(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $service = app(StudentGuardianRelationshipService::class);
        $relA = $service->link($student, $guardianA, RelationshipType::Mother);
        $relB = $service->link($student, $guardianB, RelationshipType::Father);

        $service->setPrimary($relA);
        $promoted = $service->setPrimary($relB);

        $this->assertTrue($promoted->is_primary);
        app(TenantContext::class)->withSchool($school, function () use ($relA): void {
            $this->assertFalse($relA->fresh()->is_primary);
        });
    }

    #[Test]
    public function different_students_may_each_independently_have_their_own_primary_guardian(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $guardian = $this->createGuardian($school);
        $service = app(StudentGuardianRelationshipService::class);
        $relA = $service->link($studentA, $guardian, RelationshipType::Mother);
        $relB = $service->link($studentB, $guardian, RelationshipType::Mother);

        $service->setPrimary($relA);
        $service->setPrimary($relB);

        app(TenantContext::class)->withSchool($school, function () use ($relA, $relB): void {
            $this->assertTrue($relA->fresh()->is_primary);
            $this->assertTrue($relB->fresh()->is_primary);
        });
    }

    #[Test]
    public function update_changes_relationship_type_and_flags_but_never_primary(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $relationship = app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::Other);

        $updated = app(StudentGuardianRelationshipService::class)->update($relationship, [
            'relationship_type' => RelationshipType::LegalGuardian,
            'is_legal_guardian' => true,
        ]);

        $this->assertSame(RelationshipType::LegalGuardian, $updated->relationship_type);
        $this->assertTrue($updated->is_legal_guardian);
        $this->assertFalse($updated->is_primary);
    }

    #[Test]
    public function unlink_removes_the_relationship_row(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $service = app(StudentGuardianRelationshipService::class);
        $relationship = $service->link($student, $guardian, RelationshipType::Mother);

        $service->unlink($relationship);

        $exists = app(TenantContext::class)->withSchool($school, fn () => StudentGuardianRelationship::query()->find($relationship->id));
        $this->assertNull($exists);
    }

    #[Test]
    public function unlink_preserves_the_student_and_guardian_identity_rows(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $service = app(StudentGuardianRelationshipService::class);
        $relationship = $service->link($student, $guardian, RelationshipType::Mother);

        $service->unlink($relationship);

        app(TenantContext::class)->withSchool($school, function () use ($student, $guardian): void {
            $this->assertNotNull($student->fresh());
            $this->assertNotNull($guardian->fresh());
        });
    }

    #[Test]
    public function unlink_preserves_a_shared_guardians_relationship_with_a_sibling(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $service = app(StudentGuardianRelationshipService::class);
        $relA = $service->link($studentA, $guardian, RelationshipType::Mother);
        $relB = $service->link($studentB, $guardian, RelationshipType::Mother);

        $service->unlink($relA);

        app(TenantContext::class)->withSchool($school, function () use ($relB): void {
            $this->assertNotNull($relB->fresh(), "Student B's relationship with the shared Guardian must survive.");
        });
    }
}
