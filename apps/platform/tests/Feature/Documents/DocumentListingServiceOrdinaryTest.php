<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentListingQuery;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentMetadata;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.4 -- DocumentListingService::list() (ordinary tiers only).
 */
class DocumentListingServiceOrdinaryTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function writeService(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function listingService(): DocumentListingService
    {
        return app(DocumentListingService::class);
    }

    private function createDocument($school, $employee, $actor, string $tier = 'internal', ?string $filename = null): void
    {
        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create($filename ?? 'doc.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function an_authorized_actor_sees_ordinary_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $this->createDocument($school, $employee, $actor, 'internal');
        $this->createDocument($school, $employee, $actor, 'public');

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->assertCount(2, $page->items());
        $this->assertSame(2, $page->total());
    }

    #[Test]
    public function unauthorized_listing_is_denied_and_touches_no_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $unauthorized = $this->createUserWithCapabilities($school, []);
        $this->createDocument($school, $employee, $owner);

        Storage::shouldReceive('disk')->never();

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $unauthorized, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function student_capability_does_not_authorize_employee_listing(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $studentManager = $this->createUserWithCapabilities($school, ['students.manage', 'students.view']);
        $this->createDocument($school, $employee, $owner);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $studentManager, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function a_cross_school_owner_fails_safely(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $actorA = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.view']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => $this->listingService()->list(
            $schoolA, DocumentOwner::employee($employeeB->id), $actorA, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function same_user_multiple_schools_capability_does_not_bleed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $ownerB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);
        $this->createDocument($schoolB, $employeeB, $ownerB);

        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.view']);
        $this->createMembership($actor, $schoolB);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => $this->listingService()->list(
            $schoolB, DocumentOwner::employee($employeeB->id), $actor, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function rls_and_authorization_are_independent_layers(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $this->createDocument($school, $employee, $owner);

        $noCapability = $this->createUserWithCapabilities($school, []);
        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
                $school, DocumentOwner::employee($employee->id), $noCapability, new DocumentListingQuery,
            ));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $capableInA = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employeeB->id), $capableInA, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function highly_sensitive_documents_are_completely_hidden_from_the_ordinary_list(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.sensitive.manage']);
        $ordinaryReader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        $this->createDocument($school, $employee, $writer, 'internal', 'ordinary-1.pdf');
        $this->createDocument($school, $employee, $writer, 'public', 'ordinary-2.pdf');
        $this->createDocument($school, $employee, $writer, 'highly_sensitive', 'SENTINEL-SECRET-FILE.pdf');
        $this->createDocument($school, $employee, $writer, 'highly_sensitive', 'SENTINEL-SECRET-FILE-2.pdf');
        $this->createDocument($school, $employee, $writer, 'highly_sensitive', 'SENTINEL-SECRET-FILE-3.pdf');

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $ordinaryReader, new DocumentListingQuery,
        ));

        $this->assertCount(2, $page->items());
        $this->assertSame(2, $page->total());
        $this->assertSame(1, $page->lastPage());

        foreach ($page->items() as $item) {
            $this->assertNotSame('highly_sensitive', $item->classificationTier);
            $this->assertStringNotContainsString('SENTINEL-SECRET', $item->originalFilename);
        }
    }

    #[Test]
    public function highly_sensitive_documents_do_not_create_extra_pages(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.sensitive.manage']);
        $ordinaryReader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        for ($i = 0; $i < 20; $i++) {
            $this->createDocument($school, $employee, $writer, 'internal', "ordinary-{$i}.pdf");
        }
        for ($i = 0; $i < 30; $i++) {
            $this->createDocument($school, $employee, $writer, 'highly_sensitive', "sensitive-{$i}.pdf");
        }

        $page1 = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $ordinaryReader, new DocumentListingQuery(page: 1, perPage: 10),
        ));

        $this->assertSame(20, $page1->total());
        $this->assertSame(2, $page1->lastPage());
        $this->assertCount(10, $page1->items());

        $page3 = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $ordinaryReader, new DocumentListingQuery(page: 3, perPage: 10),
        ));

        // Page 3 must be empty (only 2 real pages exist), never a sparse
        // page padded out by hidden Highly Sensitive rows.
        $this->assertCount(0, $page3->items());
    }

    #[Test]
    public function archived_documents_remain_included_in_the_ordinary_list(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $this->createDocument($school, $employee, $actor);

        $document = app(TenantContext::class)->withSchool($school, fn () => Document::query()->first());
        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->archive($school, $document, $actor));

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->assertSame(1, $page->total());
        $this->assertSame('archived', $page->items()[0]->status);
    }

    #[Test]
    public function ordinary_listing_touches_no_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $this->createDocument($school, $employee, $actor);

        Storage::shouldReceive('disk')->never();

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function results_are_returned_as_document_metadata_dtos_with_no_storage_fields(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $this->createDocument($school, $employee, $actor);

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $item = $page->items()[0];
        $this->assertInstanceOf(DocumentMetadata::class, $item);
        $this->assertFalse(property_exists($item, 'storagePath'));
        $this->assertFalse(property_exists($item, 'storageDisk'));
    }

    #[Test]
    public function ordering_is_deterministic_newest_first(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);

        $this->createDocument($school, $employee, $actor, 'internal', 'first.pdf');
        $this->createDocument($school, $employee, $actor, 'internal', 'second.pdf');

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->assertSame('second.pdf', $page->items()[0]->originalFilename);
        $this->assertSame('first.pdf', $page->items()[1]->originalFilename);
    }

    #[Test]
    public function pagination_input_is_bounded(): void
    {
        $query = new DocumentListingQuery(page: -5, perPage: 5000);

        $this->assertSame(1, $query->page);
        $this->assertSame(DocumentListingQuery::MAX_PER_PAGE, $query->perPage);
    }

    #[Test]
    public function student_owner_listing_is_rejected_before_any_document_query(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $actor = $this->createUserWithCapabilities($school, ['students.manage', 'hr.employees.documents.view']);

        Storage::shouldReceive('disk')->never();

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
                $school, DocumentOwner::student($student->id), $actor, new DocumentListingQuery,
            ));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('student', $e->ownerType);
        }
    }

    #[Test]
    public function guardian_owner_listing_is_rejected_before_any_document_query(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $actor = $this->createUserWithCapabilities($school, ['guardians.manage', 'hr.employees.documents.view']);

        Storage::shouldReceive('disk')->never();

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
                $school, DocumentOwner::guardian($guardian->id), $actor, new DocumentListingQuery,
            ));
            $this->fail('Expected DocumentOwnerTypeNotSupportedException.');
        } catch (DocumentOwnerTypeNotSupportedException $e) {
            $this->assertSame('guardian', $e->ownerType);
        }
    }
}
