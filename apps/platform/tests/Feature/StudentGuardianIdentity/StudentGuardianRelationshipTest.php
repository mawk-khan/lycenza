<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.2: the first-class Student<->Guardian relationship -- never
 * father_id/mother_id columns on Student, never a duplicate Guardian
 * row per child. See docs/modules/STUDENT-GUARDIAN-IDENTITY.md.
 */
class StudentGuardianRelationshipTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_relationship_can_be_created_with_type_and_authority_flags(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian = $this->createGuardian($school);

        $relationship = $this->createStudentGuardianRelationship($student, $guardian, [
            'relationship_type' => RelationshipType::Mother,
            'is_primary' => true,
            'is_legal_guardian' => true,
            'is_emergency_contact' => true,
            'is_authorized_pickup' => true,
        ]);

        $this->assertSame(RelationshipType::Mother, $relationship->relationship_type);
        $this->assertTrue($relationship->is_primary);
        $this->assertTrue($relationship->is_legal_guardian);
        $this->assertTrue($relationship->is_emergency_contact);
        $this->assertTrue($relationship->is_authorized_pickup);
        $this->assertSame($school->id, $relationship->school_id);
    }

    #[Test]
    public function a_student_can_have_multiple_guardians_without_conflict(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian1 = $this->createGuardian($school);
        $guardian2 = $this->createGuardian($school);
        $guardian3 = $this->createGuardian($school);

        $this->createStudentGuardianRelationship($student, $guardian1, ['relationship_type' => RelationshipType::Mother]);
        $this->createStudentGuardianRelationship($student, $guardian2, ['relationship_type' => RelationshipType::Father]);
        $this->createStudentGuardianRelationship($student, $guardian3, ['relationship_type' => RelationshipType::Grandparent]);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => $student->guardianRelationships()->count(),
        );

        $this->assertSame(3, $count);
    }

    #[Test]
    public function a_guardian_is_reused_not_duplicated_across_sibling_students(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['student_number' => 'S-0001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $guardianX = $this->createGuardian($school, ['first_name' => 'Shared']);

        $this->createStudentGuardianRelationship($studentA, $guardianX, ['relationship_type' => RelationshipType::Mother]);
        $this->createStudentGuardianRelationship($studentB, $guardianX, ['relationship_type' => RelationshipType::Mother]);

        $guardianCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => Guardian::query()->where('first_name', 'Shared')->count(),
        );

        $this->assertSame(1, $guardianCount, 'The shared Guardian must be one row, not duplicated per child.');

        $studentIds = app(TenantContext::class)->withSchool(
            $school,
            fn () => $guardianX->students()->pluck('students.id')->sort()->values()->all(),
        );

        $this->assertSame([$studentA->id, $studentB->id], collect($studentIds)->sort()->values()->all());
    }

    #[Test]
    public function a_duplicate_relationship_for_the_same_student_and_guardian_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, ['relationship_type' => RelationshipType::Mother]);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected unique-constraint failure rolls back cleanly instead
        // of leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for withSchool()'s own
        // RESET-on-exit statement -- see
        // StudentIdentityTest::the_same_student_number_is_rejected_within_the_same_school
        // for the identical pattern.
        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => StudentGuardianRelationship::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'relationship_type' => RelationshipType::LegalGuardian,
        ])));
    }

    #[Test]
    public function a_second_primary_guardian_for_the_same_student_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian1 = $this->createGuardian($school);
        $guardian2 = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian1, [
            'relationship_type' => RelationshipType::Mother, 'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => StudentGuardianRelationship::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'guardian_id' => $guardian2->id,
            'relationship_type' => RelationshipType::Father,
            'is_primary' => true,
        ])));
    }

    #[Test]
    public function different_students_may_each_have_their_own_primary_guardian(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['student_number' => 'S-0001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);

        $relA = $this->createStudentGuardianRelationship($studentA, $guardianA, [
            'relationship_type' => RelationshipType::Mother, 'is_primary' => true,
        ]);
        $relB = $this->createStudentGuardianRelationship($studentB, $guardianB, [
            'relationship_type' => RelationshipType::Father, 'is_primary' => true,
        ]);

        $this->assertTrue($relA->is_primary);
        $this->assertTrue($relB->is_primary);
    }

    #[Test]
    public function authority_flags_can_differ_per_student_for_the_same_guardian(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['student_number' => 'S-0001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $guardianX = $this->createGuardian($school);

        $relA = $this->createStudentGuardianRelationship($studentA, $guardianX, [
            'relationship_type' => RelationshipType::Relative, 'is_authorized_pickup' => true,
        ]);
        $relB = $this->createStudentGuardianRelationship($studentB, $guardianX, [
            'relationship_type' => RelationshipType::Relative, 'is_authorized_pickup' => false,
        ]);

        $this->assertTrue($relA->is_authorized_pickup);
        $this->assertFalse($relB->is_authorized_pickup);
    }

    #[Test]
    public function deleting_a_relationship_does_not_delete_the_student_or_guardian(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, [
            'relationship_type' => RelationshipType::Mother,
        ]);

        app(TenantContext::class)->withSchool($school, function () use ($relationship, $student, $guardian): void {
            $relationship->delete();

            $this->assertNotNull($student->fresh());
            $this->assertNotNull($guardian->fresh());
        });
    }

    #[Test]
    public function removing_one_students_relationship_does_not_affect_a_shared_guardians_other_relationship(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school, ['student_number' => 'S-0001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $guardianX = $this->createGuardian($school);

        $relA = $this->createStudentGuardianRelationship($studentA, $guardianX, ['relationship_type' => RelationshipType::Mother]);
        $relB = $this->createStudentGuardianRelationship($studentB, $guardianX, ['relationship_type' => RelationshipType::Mother]);

        app(TenantContext::class)->withSchool($school, function () use ($relA, $guardianX, $relB): void {
            $relA->delete();

            $this->assertNotNull($guardianX->fresh(), 'The shared Guardian must survive.');
            $this->assertNotNull($relB->fresh(), "Student B's relationship with the shared Guardian must survive.");
        });
    }

    #[Test]
    public function attach_is_not_the_supported_mutation_api_and_fails_closed(): void
    {
        // Phase 1A.2 P3 finding, resolved in Phase 1A.3 (see
        // Student::guardians()'s docblock): attach()/sync() bypass
        // BelongsToSchool's school_id auto-fill because Laravel's
        // BelongsToMany::attach() always writes via a raw query
        // builder insert, never through a pivot model's Eloquent
        // events -- even a correctly configured `->using()` custom
        // pivot would not change this. The resolution is NOT to make
        // attach() safe; it is to prove it fails loudly (a NOT NULL
        // school_id violation) rather than silently creating a
        // School-less or wrongly-scoped row, and to document
        // StudentGuardianRelationship::create() as the only supported
        // mutation path.
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $guardian = $this->createGuardian($school);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => DB::transaction(
            fn () => $student->guardians()->attach($guardian->id, ['relationship_type' => 'mother'])
        ));
    }
}
