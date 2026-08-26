<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Http\Controllers\DocumentController;
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
     * ADR 0029's permanent decision: `employee_documents` and
     * `documents` stay separate, with no shared foreign key between
     * them. This binds unless a future ADR explicitly supersedes ADR
     * 0029 -- it is not a placeholder pending a later merge. If ADR
     * 0029 is ever superseded, that supersession is a deliberate,
     * reviewed decision recorded in its own ADR, never something that
     * should already silently exist in the schema.
     */
    #[Test]
    public function employee_documents_has_no_foreign_key_to_generic_documents(): void
    {
        $this->assertFalse(
            Schema::hasColumn('employee_documents', 'document_id'),
            'employee_documents must never gain a document_id FK to documents -- ADR 0029 permanently decided the two tables stay unlinked, and only an ADR that explicitly supersedes ADR 0029 could change that.',
        );
    }
}
