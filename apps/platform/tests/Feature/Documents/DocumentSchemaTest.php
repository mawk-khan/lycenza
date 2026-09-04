<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * Phase 0E.1 -- schema/model/constraint proof for the Documents
 * foundation table. No service/HTTP layer exists yet (deliberately,
 * see docs/modules/DOCUMENTS.md) -- every row here is created directly
 * through the factory/fixture helpers, exactly proving the schema
 * itself (UUIDv7, tenant scoping, exclusive-arc owner constraint,
 * classification/status CHECKs, composite foreign keys) is correct
 * independent of any not-yet-built write service.
 */
class DocumentSchemaTest extends TestCase
{
    // CreatesLearningContentFixtures already includes CreatesTenancyFixtures
    // internally (the same "used read-only" precedent every module fixture
    // trait follows) -- using both directly here would flatten the same
    // trait in twice, so only the LMS-aware one is used.
    use CreatesLearningContentFixtures;

    #[Test]
    public function document_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createDocumentForEmployee($employee);

        $this->assertTrue(UuidV7::fromString($document->id) instanceof UuidV7);
    }

    #[Test]
    public function a_document_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createDocumentForEmployee($employee);

        $this->assertSame($school->id, $document->school_id);
        $this->assertSame($school->id, $document->school->id);
    }

    #[Test]
    public function a_document_may_belong_to_an_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createDocumentForEmployee($employee);

        $this->assertSame($employee->id, $document->employee_id);
        $this->assertNull($document->student_id);
        $this->assertNull($document->guardian_id);
        $this->assertSame('employee', $document->owner_type);
    }

    #[Test]
    public function a_document_may_belong_to_a_student(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $document = $this->createDocumentForStudent($student);

        $this->assertSame($student->id, $document->student_id);
        $this->assertNull($document->employee_id);
        $this->assertNull($document->guardian_id);
        $this->assertSame('student', $document->owner_type);
    }

    #[Test]
    public function a_document_may_belong_to_a_guardian(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $document = $this->createDocumentForGuardian($guardian);

        $this->assertSame($guardian->id, $document->guardian_id);
        $this->assertNull($document->employee_id);
        $this->assertNull($document->student_id);
        $this->assertSame('guardian', $document->owner_type);
    }

    #[Test]
    public function a_document_with_zero_owners_is_rejected(): void
    {
        $school = $this->createSchool();

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $school->id,
        ]);
    }

    #[Test]
    public function a_document_with_two_owners_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $student = $this->createStudent($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $school->id,
            'employee_id' => $employee->id,
            'student_id' => $student->id,
        ]);
    }

    #[Test]
    public function a_document_with_all_three_owners_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $school->id,
            'employee_id' => $employee->id,
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
        ]);
    }

    #[Test]
    public function an_invalid_classification_tier_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->expectException(QueryException::class);

        $this->createDocumentForEmployee($employee, ['classification_tier' => 'top_secret']);
    }

    #[Test]
    public function every_canonical_classification_tier_is_accepted(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        foreach (['public', 'internal', 'sensitive', 'highly_sensitive'] as $tier) {
            $document = $this->createDocumentForEmployee($employee, ['classification_tier' => $tier]);
            $this->assertSame($tier, $document->classification_tier);
        }
    }

    #[Test]
    public function an_invalid_status_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->expectException(QueryException::class);

        $this->createDocumentForEmployee($employee, ['status' => 'deleted']);
    }

    #[Test]
    public function archived_factory_state_produces_an_archived_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $document = app(TenantContext::class)->withSchool(
            $school,
            fn () => Document::factory()->archived()->create([
                'school_id' => $school->id,
                'employee_id' => $employee->id,
            ]),
        );

        $this->assertSame('archived', $document->status);
        $this->assertFalse($document->isActive());
    }

    #[Test]
    public function a_cross_school_employee_owner_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $schoolA->id,
            'employee_id' => $employeeB->id,
        ]);
    }

    #[Test]
    public function a_cross_school_student_owner_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $schoolA->id,
            'student_id' => $studentB->id,
        ]);
    }

    #[Test]
    public function a_cross_school_guardian_owner_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $schoolA->id,
            'guardian_id' => $guardianB->id,
        ]);
    }

    #[Test]
    public function storage_path_is_always_tenant_prefixed_via_tenant_storage_path(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $path = TenantStoragePath::for($school, 'documents/'.$employee->id.'/id-card.pdf');
        $document = $this->createDocumentForEmployee($employee, ['storage_path' => $path]);

        $this->assertStringStartsWith("schools/{$school->id}/", $document->storage_path);
    }

    #[Test]
    public function ordinary_eloquent_queries_are_scoped_to_the_active_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $this->createDocumentForEmployee($employeeA);
        $this->createDocumentForEmployee($employeeB);

        $visible = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Document::query()->count(),
        );

        $this->assertSame(1, $visible);
    }

    // --- Phase 0I.2: the `learning_content` owner arm -------------------

    #[Test]
    public function a_document_may_belong_to_a_learning_content_resource(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $document = $this->createDocumentForLearningContent($content);

        $this->assertSame($content->id, $document->learning_content_id);
        $this->assertNull($document->employee_id);
        $this->assertNull($document->student_id);
        $this->assertNull($document->guardian_id);
        $this->assertSame('learning_content', $document->owner_type);
    }

    #[Test]
    public function a_document_with_learning_content_and_employee_owners_is_rejected(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $employee = $this->createEmployee($w['school']);

        app(TenantContext::class)->set($w['school']);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $w['school']->id,
            'learning_content_id' => $content->id,
            'employee_id' => $employee->id,
        ]);
    }

    #[Test]
    public function a_document_with_all_four_owners_is_rejected(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $employee = $this->createEmployee($w['school']);
        $student = $this->createStudent($w['school']);
        $guardian = $this->createGuardian($w['school']);

        app(TenantContext::class)->set($w['school']);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $w['school']->id,
            'employee_id' => $employee->id,
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'learning_content_id' => $content->id,
        ]);
    }

    #[Test]
    public function a_cross_school_learning_content_owner_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $worldB = $this->learningContentWorld();
        $contentB = $this->createLearningContent($worldB['offering']);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        Document::factory()->create([
            'school_id' => $schoolA->id,
            'learning_content_id' => $contentB->id,
        ]);
    }

    #[Test]
    public function the_existing_three_owner_arms_still_function_unchanged(): void
    {
        // Regression proof that widening the CHECK constraint (Phase
        // 0I.2) did not disturb the pre-existing employee/student/
        // guardian arms in any way.
        $school = $this->createSchool();

        $employeeDoc = $this->createDocumentForEmployee($this->createEmployee($school));
        $this->assertSame('employee', $employeeDoc->owner_type);

        $studentDoc = $this->createDocumentForStudent($this->createStudent($school));
        $this->assertSame('student', $studentDoc->owner_type);

        $guardianDoc = $this->createDocumentForGuardian($this->createGuardian($school));
        $this->assertSame('guardian', $guardianDoc->owner_type);
    }
}
