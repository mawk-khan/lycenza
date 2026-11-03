<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 0E.6 / ADR 0029 -- the reconciliation decision (`employee_documents`
 * and `documents` remain two permanently separate tables, never merged)
 * is only durable if it's enforced from both directions. HR's own
 * `EmployeeDocumentTest::employee_documents_remains_its_own_table_independent_of_the_shared_documents_module`
 * already proves `EmployeeDocumentService` never reads/writes `documents`.
 * This is the other half: nothing in the generic Documents module ever
 * reads/writes `employee_documents` either. A future change that starts
 * querying the other side's table would silently reopen exactly the
 * "silently duplicated or ignored" outcome ADR 0028/ADR 0029 both
 * rejected -- this test turns that into a build failure instead of a
 * design-review miss.
 */
class DocumentEmployeeDocumentIndependenceTest extends TestCase
{
    private function source(string $class): string
    {
        return file_get_contents((new ReflectionClass($class))->getFileName());
    }

    #[Test]
    public function document_service_never_references_employee_documents(): void
    {
        $this->assertStringNotContainsString("table('employee_documents')", $this->source(DocumentService::class));
        $this->assertStringNotContainsString('EmployeeDocument::', $this->source(DocumentService::class));
    }

    #[Test]
    public function document_read_service_never_references_employee_documents(): void
    {
        $this->assertStringNotContainsString("table('employee_documents')", $this->source(DocumentReadService::class));
        $this->assertStringNotContainsString('EmployeeDocument::', $this->source(DocumentReadService::class));
    }

    #[Test]
    public function document_listing_service_never_references_employee_documents(): void
    {
        $this->assertStringNotContainsString("table('employee_documents')", $this->source(DocumentListingService::class));
        $this->assertStringNotContainsString('EmployeeDocument::', $this->source(DocumentListingService::class));
    }

    #[Test]
    public function document_controller_never_references_employee_documents(): void
    {
        $this->assertStringNotContainsString("table('employee_documents')", $this->source(DocumentController::class));
        $this->assertStringNotContainsString('EmployeeDocument::', $this->source(DocumentController::class));
    }

    /**
     * ADR 0029 HR.2A amendment (the explicit, reviewed supersession this test
     * always required): the two tables stay separate -- HR keeps its record
     * (closed category, tier, dates, archive), Documents keeps the bytes --
     * and a NEW HR record references its governed Document through a
     * composite same-School key. The tables are never merged.
     */
    #[Test]
    public function employee_documents_references_a_governed_document_by_a_same_school_key(): void
    {
        $this->assertTrue(Schema::hasTable('employee_documents') && Schema::hasTable('documents'), 'two tables, never merged');
        $this->assertTrue(Schema::hasColumn('employee_documents', 'document_id'));
        $definition = DB::connection('pgsql_admin')->selectOne(
            "select pg_get_constraintdef(oid) as d from pg_constraint where conrelid = 'employee_documents'::regclass and contype = 'f' and pg_get_constraintdef(oid) like '%REFERENCES documents%'",
        );
        $this->assertNotNull($definition);
        $this->assertStringContainsString('FOREIGN KEY (document_id, school_id) REFERENCES documents(id, school_id)', $definition->d);
    }
}
