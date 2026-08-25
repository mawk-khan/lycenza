<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentListingQuery;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.4 -- DocumentListingService::listSensitive() (highly_sensitive
 * only).
 */
class DocumentListingServiceSensitiveTest extends TestCase
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

    private function createDocument($school, $employee, $actor, string $tier = 'internal'): void
    {
        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function a_sensitive_authorized_actor_sees_only_highly_sensitive_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.sensitive.manage']);
        $reader = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        $this->createDocument($school, $employee, $writer, 'internal');
        $this->createDocument($school, $employee, $writer, 'highly_sensitive');
        $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $reader, new DocumentListingQuery,
        ));

        $this->assertCount(2, $page->items());
        $this->assertSame(2, $page->total());
        foreach ($page->items() as $item) {
            $this->assertSame('highly_sensitive', $item->classificationTier);
        }
    }

    #[Test]
    public function ordinary_view_alone_cannot_call_sensitive_listing(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $ordinaryOnly = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);
        $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $ordinaryOnly, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function neither_capability_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapability = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $noCapability, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function both_capabilities_still_keep_the_two_operations_separate(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, [
            'hr.employees.documents.manage', 'hr.employees.documents.view',
            'hr.employees.sensitive.manage', 'hr.employees.sensitive.view',
        ]);

        $this->createDocument($school, $employee, $actor, 'internal');
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        $ordinaryPage = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));
        $sensitivePage = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->assertSame(1, $ordinaryPage->total());
        $this->assertSame('internal', $ordinaryPage->items()[0]->classificationTier);
        $this->assertSame(1, $sensitivePage->total());
        $this->assertSame('highly_sensitive', $sensitivePage->items()[0]->classificationTier);
    }

    #[Test]
    public function a_cross_school_owner_fails_safely(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $actorA = $this->createUserWithCapabilities($schoolA, ['hr.employees.sensitive.view']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => $this->listingService()->listSensitive(
            $schoolA, DocumentOwner::employee($employeeB->id), $actorA, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function same_user_multiple_schools_capability_does_not_bleed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $writerB = $this->createUserWithCapabilities($schoolB, ['hr.employees.sensitive.manage']);
        $this->createDocument($schoolB, $employeeB, $writerB, 'highly_sensitive');

        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.sensitive.view']);
        $this->createMembership($actor, $schoolB);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => $this->listingService()->listSensitive(
            $schoolB, DocumentOwner::employee($employeeB->id), $actor, new DocumentListingQuery,
        ));
    }

    #[Test]
    public function archived_highly_sensitive_documents_remain_included(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        $document = app(TenantContext::class)->withSchool($school, fn () => Document::query()->first());
        app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->archive($school, $document, $actor));

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->assertSame(1, $page->total());
        $this->assertSame('archived', $page->items()[0]->status);
    }

    #[Test]
    public function sensitive_listing_touches_no_storage(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        Storage::shouldReceive('disk')->never();

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_successful_sensitive_listing_is_audited_exactly_once_regardless_of_row_count(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function an_empty_sensitive_listing_produces_no_audit_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.view']);

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function denied_sensitive_listing_produces_no_audit_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $denied = $this->createUserWithCapabilities($school, []);
        $this->createDocument($school, $employee, $writer, 'highly_sensitive');

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
                $school, DocumentOwner::employee($employee->id), $denied, new DocumentListingQuery,
            ));
        } catch (AuthorizationException) {
            // expected
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count(),
        );
        $this->assertSame(0, $count);
    }

    #[Test]
    public function audit_metadata_contains_no_filenames_or_storage_paths(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);
        $this->createDocument($school, $employee, $actor, 'highly_sensitive');

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->first(),
        );

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('storagePath', $event->metadata);
        $this->assertArrayNotHasKey('originalFilename', $event->metadata);
        $this->assertArrayNotHasKey('documentIds', $event->metadata);
    }
}
