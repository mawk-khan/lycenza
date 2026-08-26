<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\SchoolAuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.2 -- the critical acceptance gate (checklist §56): forces a
 * failure AFTER the object is already written to storage but BEFORE
 * the metadata/audit database transaction commits, and proves real
 * compensation (the object is deleted), not merely a database
 * rollback. `AuditRecorder::school()` runs INSIDE
 * DocumentService::create()'s DB::transaction() closure, after the
 * `documents` row insert but before the transaction returns -- binding
 * a mock that throws there is a faithful simulation of "the DB
 * transaction failed for any reason after the object write succeeded,"
 * without needing to actually sever the database connection mid-test.
 */
class DocumentServiceCompensationTest extends TestCase
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

    #[Test]
    public function a_db_transaction_failure_after_a_successful_object_write_removes_the_orphan_object(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);

        $failingAudit = Mockery::mock(AuditRecorder::class);
        $failingAudit->shouldReceive('school')->once()->andThrow(new RuntimeException('simulated DB transaction failure'));
        $this->app->instance(AuditRecorder::class, $failingAudit);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $this->service()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')),
                $actor,
            ));
            $this->fail('Expected the simulated transaction failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated DB transaction failure', $e->getMessage());
        }

        // Remove the mocked binding so the assertions below (which run
        // under normal TenantContext, no audit writes involved) never
        // accidentally touch the exhausted mock expectation.
        $this->app->forgetInstance(AuditRecorder::class);

        $documentCount = app(TenantContext::class)->withSchool($school, fn () => Document::query()->count());
        $this->assertSame(0, $documentCount, 'No Document row may survive a failed metadata transaction.');

        $auditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.created')->count(),
        );
        $this->assertSame(0, $auditCount, 'No committed audit event may exist for a failed create.');

        // The proof this test exists for: the object written to
        // storage BEFORE the transaction ran must have been deleted by
        // DocumentService::create()'s compensation, not left orphaned.
        Storage::disk('local')->assertDirectoryEmpty("schools/{$school->id}/documents/employee/{$employee->id}");
    }
}
