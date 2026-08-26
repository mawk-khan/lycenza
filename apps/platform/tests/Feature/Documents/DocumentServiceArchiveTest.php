<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
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
 * Phase 0E.2 -- DocumentService::archive(). Status-only lifecycle
 * transition (0E.1's `documents.status` never hard-deletes) --
 * matches App\Domain\HR\Application\EmployeeDocumentService::archive()'s
 * exact semantics for the same-shaped Restricted/Highly-Sensitive
 * authorization split.
 */
class DocumentServiceArchiveTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function createDocument($school, $employee, $actor, string $tier = 'internal'): Document
    {
        return app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
            $actor,
        ));
    }

    #[Test]
    public function archive_sets_status_to_archived_and_leaves_the_object_in_place(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $actor);

        $archived = app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $actor));

        $this->assertSame('archived', $archived->status);
        Storage::disk('local')->assertExists($archived->storage_path);
    }

    #[Test]
    public function repeated_archive_is_deterministic(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $actor);

        $first = app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $actor));
        $second = app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $first, $actor));

        $this->assertSame('archived', $first->status);
        $this->assertSame('archived', $second->status);
    }

    #[Test]
    public function two_concurrent_archive_calls_do_not_corrupt_state(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $actor);

        // Sequential simulation of "concurrent" calls is sufficient here
        // because archive() is a plain idempotent status update, not a
        // race-prone allocator/uniqueness invariant (docs/modules/DOCUMENTS.md
        // "no concurrency test included... no one-current-row invariant").
        $documentA = app(TenantContext::class)->withSchool($school, fn () => Document::query()->find($document->id));
        $documentB = app(TenantContext::class)->withSchool($school, fn () => Document::query()->find($document->id));

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $documentA, $actor));
        $result = app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $documentB, $actor));

        $this->assertSame('archived', $result->status);
    }

    #[Test]
    public function unauthorized_archive_is_denied(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $unauthorized = $this->createUserWithCapabilities($school, []);
        $document = $this->createDocument($school, $employee, $owner);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $unauthorized));
    }

    #[Test]
    public function archiving_a_highly_sensitive_document_requires_the_sensitive_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $sensitiveActor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage']);
        $ordinaryActor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $sensitiveActor, 'highly_sensitive');

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $ordinaryActor));
    }

    #[Test]
    public function cross_school_archive_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $actorA = $this->createUserWithCapabilities($schoolA, ['hr.employees.documents.manage']);
        $document = $this->createDocument($schoolA, $employeeA, $actorA);

        $actorB = $this->createUserWithCapabilities($schoolB, ['hr.employees.documents.manage']);

        $this->expectException(DocumentOwnerNotFoundException::class);

        app(TenantContext::class)->withSchool($schoolB, fn () => $this->service()->archive($schoolB, $document, $actorB));
    }

    #[Test]
    public function archive_produces_exactly_one_audit_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $document = $this->createDocument($school, $employee, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $actor));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.archived')->where('subject_id', $document->id)->count(),
        );

        $this->assertSame(1, $count);
    }

    #[Test]
    public function denied_archive_produces_no_audit_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $owner = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $unauthorized = $this->createUserWithCapabilities($school, []);
        $document = $this->createDocument($school, $employee, $owner);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->archive($school, $document, $unauthorized));
        } catch (AuthorizationException) {
            // expected
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.archived')->where('subject_id', $document->id)->count(),
        );

        $this->assertSame(0, $count);
        $stillActive = app(TenantContext::class)->withSchool($school, fn () => $document->fresh()->status);
        $this->assertSame('active', $stillActive);
    }
}
