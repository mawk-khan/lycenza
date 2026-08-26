<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentListingQuery;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.4 -- proves DocumentListingService's query count stays flat
 * as the result set grows (no N+1 owner hydration, no per-row
 * authorization, no per-row audit write). Uses `DB::flushQueryLog()`
 * before AND after each measurement (the exact self-caused bug 8A.15
 * discovered and fixed in its own differential-performance helper --
 * `DB::enableQueryLog()` alone accumulates across calls within one
 * test, producing a false-positive "N+1" signal).
 */
class DocumentListingServicePerformanceTest extends TestCase
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

    private function queryCountFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function ordinary_listing_query_count_does_not_grow_with_result_size(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $writer = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage']);
        $reader = $this->createUserWithCapabilities($school, ['hr.employees.documents.view']);

        for ($i = 0; $i < 3; $i++) {
            app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create("doc-{$i}.pdf", 10, 'application/pdf')),
                $writer,
            ));
        }

        // Warm CapabilityResolver's cache for ($reader, $school) with an
        // untimed call first -- its first-ever resolution for a given
        // actor+School runs several one-time queries (membership, role
        // assignment, role, capability join) that a cache hit skips on
        // every subsequent call. Comparing a cold-cache call against a
        // warm-cache call would produce a false-positive "queries
        // shrank/grew" signal unrelated to result-set size -- the exact
        // same class of self-caused measurement bug 8A.15's own
        // differential-performance helper ran into (there: an unflushed
        // query log; here: an unwarmed cache), not a real defect in the
        // service under test.
        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $reader, new DocumentListingQuery,
        ));

        $smallCount = $this->queryCountFor(fn () => app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $reader, new DocumentListingQuery,
        )));

        for ($i = 3; $i < 60; $i++) {
            app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->create("doc-{$i}.pdf", 10, 'application/pdf')),
                $writer,
            ));
        }

        $largeCount = $this->queryCountFor(fn () => app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->list(
            $school, DocumentOwner::employee($employee->id), $reader, new DocumentListingQuery(perPage: 25),
        )));

        $this->assertSame($smallCount, $largeCount, 'Query count must not grow with result set size (no N+1).');
        $this->assertLessThanOrEqual(7, $smallCount, 'Listing should be a small, bounded number of queries (tenant context + auth resolve + count + select).');
    }

    #[Test]
    public function sensitive_listing_writes_at_most_one_audit_row_regardless_of_result_size(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view']);

        for ($i = 0; $i < 15; $i++) {
            app(TenantContext::class)->withSchool($school, fn () => $this->writeService()->create(
                $school,
                new CreateDocumentData(DocumentOwner::employee($employee->id), 'highly_sensitive', UploadedFile::fake()->create("s-{$i}.pdf", 10, 'application/pdf')),
                $actor,
            ));
        }

        app(TenantContext::class)->withSchool($school, fn () => $this->listingService()->listSensitive(
            $school, DocumentOwner::employee($employee->id), $actor, new DocumentListingQuery,
        ));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'document.sensitive_list_viewed')->count(),
        );
        $this->assertSame(1, $count, 'Exactly one audit row for 15 returned Documents, never one per row.');
    }
}
