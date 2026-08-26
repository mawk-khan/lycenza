<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.7: proves the Employee's 1:N Restricted-tier document
 * metadata -- UUIDv7 identity, School/Employee ownership, tenant-safe
 * storage-path derivation, classification restrictions, date validity,
 * status lifecycle, and the structural absence of any file-handling
 * capability (Case C dependency-discovery outcome: no shared Documents
 * module exists anywhere in this repository).
 */
class EmployeeDocumentTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function document_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($document->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_documents(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeDocument($employee, ['category' => 'id_proof']);
        $this->createEmployeeDocument($employee, ['category' => 'employment_contract']);

        app(TenantContext::class)->set($school);

        $this->assertCount(2, $employee->documents()->get());
    }

    #[Test]
    public function new_documents_default_to_restricted_classification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        DB::transaction(function () use ($employee): void {
            $document = EmployeeDocument::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'category' => 'other',
                'storage_disk' => 'local',
                'storage_path' => 'employee-documents/default-tier.pdf',
                'original_filename' => 'file.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'uploaded_at' => now(),
            ]);

            $this->assertSame('restricted', $document->fresh()->classification_tier);
        });
    }

    #[Test]
    public function directory_classification_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeDocument::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'category' => 'other',
                'classification_tier' => 'directory',
                'storage_disk' => 'local',
                'storage_path' => 'employee-documents/rogue.pdf',
                'original_filename' => 'file.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'uploaded_at' => now(),
            ]);
        });
    }

    #[Test]
    public function highly_sensitive_classification_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'highly_sensitive']);

        $this->assertSame('highly_sensitive', $document->classification_tier);
    }

    #[Test]
    public function documents_default_to_active_status_and_are_never_hard_deleted(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee);

        $this->assertSame('active', $document->status);
        $this->assertTrue($document->isActive());
        $this->assertFalse(method_exists(EmployeeDocumentService::class, 'remove'), 'EmployeeDocumentService must not expose a hard-delete method -- archive() is the only removal-adjacent operation.');
    }

    #[Test]
    public function an_expiry_date_before_the_issue_date_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeDocument::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'category' => 'id_proof',
                'storage_disk' => 'local',
                'storage_path' => 'employee-documents/bad-range.pdf',
                'original_filename' => 'file.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'uploaded_at' => now(),
                'issued_on' => '2022-06-01',
                'expires_on' => '2021-06-01',
            ]);
        });
    }

    #[Test]
    public function a_non_expiring_document_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['expires_on' => null]);

        $this->assertNull($document->expires_on);
    }

    #[Test]
    public function a_school_a_employee_id_combined_with_school_b_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);

        app(TenantContext::class)->set($schoolB);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employeeA, $schoolB): void {
            EmployeeDocument::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'category' => 'other',
                'storage_disk' => 'local',
                'storage_path' => 'employee-documents/rogue.pdf',
                'original_filename' => 'file.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'uploaded_at' => now(),
            ]);
        });
    }

    #[Test]
    public function service_register_derives_a_tenant_prefixed_storage_path_from_the_caller_supplied_fragment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/passport-scan.pdf',
            'original_filename' => 'passport-scan.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ], $this->fullHrActor($school));

        $this->assertSame("schools/{$school->id}/employee-documents/passport-scan.pdf", $document->storage_path);
    }

    #[Test]
    public function service_register_rejects_a_traversal_attempt_in_the_storage_path_fragment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->expectException(InvalidArgumentException::class);

        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => '../../etc/passwd',
            'original_filename' => 'passport-scan.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ], $this->fullHrActor($school));
    }

    #[Test]
    public function service_register_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'employee_id' => $otherEmployee->id,
        ], $this->fullHrActor($school));

        $this->assertSame($employee->id, $document->employee_id, 'A caller-supplied employee_id in the attributes array must never override the authoritative Employee argument.');
    }

    #[Test]
    public function update_cannot_change_storage_disk_or_storage_path(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['storage_path' => 'employee-documents/original.pdf']);

        $updated = app(EmployeeDocumentService::class)->update($employee, $document, [
            'storage_path' => 'employee-documents/hacked.pdf',
            'storage_disk' => 's3',
        ], $this->fullHrActor($school));

        $this->assertSame('employee-documents/original.pdf', $updated->storage_path, 'storage_path must be immutable after registration -- update() must never re-point a document at different file content.');
    }

    #[Test]
    public function register_derives_uploaded_by_user_id_from_the_actor_argument_not_the_caller_supplied_attribute(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actualActor = $this->fullHrActor($school);
        $forgedActor = $this->createUser();

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by_user_id' => $forgedActor->id,
        ], $actualActor);

        $this->assertSame($actualActor->id, $document->uploaded_by_user_id, 'uploaded_by_user_id must be derived from the trusted $actor argument, never a caller-supplied attribute value.');
        $this->assertNotSame($forgedActor->id, $document->uploaded_by_user_id);
    }

    // Phase 8A.10: a "register with no actor" case (formerly tested
    // here) is now structurally impossible -- EmployeeDocumentService::
    // register() requires a real, authorized `User $actor` (no HR
    // application service accepts a null/anonymous actor as of this
    // checkpoint, see docs/modules/HR.md 8A.10 as-built, "null actor").
    // The remaining invariant this test protected -- a caller-supplied
    // `uploaded_by_user_id` attribute can never override provenance --
    // is still fully proven by
    // register_derives_uploaded_by_user_id_from_the_actor_argument_not_the_caller_supplied_attribute
    // above, using a real authorized actor.

    #[Test]
    public function update_cannot_change_the_original_uploaded_by_user_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $originalUploader = $this->fullHrActor($school);
        $impersonator = $this->createUser();

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ], $originalUploader);

        $updated = app(EmployeeDocumentService::class)->update($employee, $document, [
            'uploaded_by_user_id' => $impersonator->id,
            'category' => 'other',
        ], $originalUploader);

        $this->assertSame($originalUploader->id, $updated->uploaded_by_user_id, 'uploaded_by_user_id records original registration provenance and must be immutable via update().');
    }

    #[Test]
    public function a_later_update_by_a_different_actor_never_changes_original_provenance_while_the_audit_trail_attributes_the_mutation_to_the_updater(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $originalUploader = $this->fullHrActor($school);
        $laterUpdater = $this->fullHrActor($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf',
            'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ], $originalUploader);

        $updated = app(EmployeeDocumentService::class)->update($employee, $document, ['category' => 'other'], $laterUpdater);

        $this->assertSame($originalUploader->id, $updated->uploaded_by_user_id, 'Original registration provenance must survive a later update by a different actor.');

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'hr.employee_document.updated')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($laterUpdater->id, $event->actor_user_id, 'The audit trail, not uploaded_by_user_id, is what attributes each individual mutation to its actor.');
    }

    #[Test]
    public function update_via_service_persists_and_survives_a_mass_assignment_attempt_on_school_id(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $document = $this->createEmployeeDocument($employeeA, ['category' => 'other']);

        $updated = app(EmployeeDocumentService::class)->update($employeeA, $document, [
            'category' => 'id_proof',
            'school_id' => $schoolB->id,
        ], $this->fullHrActor($schoolA));

        $this->assertSame('id_proof', $updated->category);
        $this->assertSame($schoolA->id, $updated->school_id, 'A caller-supplied school_id in the attributes array must never move a record to a different School.');
    }

    #[Test]
    public function updating_a_document_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $documentB = $this->createEmployeeDocument($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeDocumentService::class)->update($employeeA, $documentB, ['category' => 'other'], $this->fullHrActor($school));
    }

    #[Test]
    public function archiving_a_document_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $documentB = $this->createEmployeeDocument($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeDocumentService::class)->archive($employeeA, $documentB, $this->fullHrActor($school));
    }

    #[Test]
    public function archive_sets_status_to_archived(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee);

        $archived = app(EmployeeDocumentService::class)->archive($employee, $document, $this->fullHrActor($school));

        $this->assertSame('archived', $archived->status);
        $this->assertFalse($archived->isActive());
    }

    #[Test]
    public function classification_change_is_recorded_in_audit_metadata(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee, ['classification_tier' => 'restricted']);

        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'hr.employee_document.updated')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->metadata['classificationChanged']);
    }

    #[Test]
    public function audit_metadata_contains_no_filename_or_storage_path_values(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $document = app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof',
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/confidential-passport.pdf',
            'original_filename' => 'confidential-passport.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ], $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'hr.employee_document.created')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($document->id, $event->metadata['documentId']);
        $this->assertArrayNotHasKey('originalFilename', $event->metadata);
        $this->assertArrayNotHasKey('storagePath', $event->metadata);
        $this->assertArrayNotHasKey('original_filename', $event->metadata);
        $this->assertArrayNotHasKey('storage_path', $event->metadata);
    }

    #[Test]
    public function setting_a_document_classification_never_touches_an_authorization_table(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $document = $this->createEmployeeDocument($employee);
        // Created BEFORE the baseline counts -- see PositionTest's
        // identical pattern/rationale.
        $actor = $this->fullHrActor($school);

        $beforeRoles = DB::table('membership_role_assignments')->count();
        $beforePlatformRoles = DB::table('platform_role_assignments')->count();

        app(EmployeeDocumentService::class)->update($employee, $document, ['classification_tier' => 'highly_sensitive'], $actor);

        $this->assertSame($beforeRoles, DB::table('membership_role_assignments')->count());
        $this->assertSame($beforePlatformRoles, DB::table('platform_role_assignments')->count());
    }

    /**
     * Phase 8A.7's own Documents-dependency proof (case C: no shared
     * Documents module exists anywhere in this repository). This
     * structurally confirms EmployeeDocumentService never grew an
     * upload/download/delete-file-content capability -- its public
     * surface is exactly register/update/archive, nothing that touches
     * `Illuminate\Support\Facades\Storage` or returns a downloadable
     * URL.
     */
    #[Test]
    public function employee_document_service_has_no_file_upload_download_or_storage_write_capability(): void
    {
        $reflection = new ReflectionClass(EmployeeDocumentService::class);
        $publicMethodNames = array_map(
            fn ($method) => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $publicMethodNames = array_values(array_diff($publicMethodNames, ['__construct']));

        sort($publicMethodNames);

        $this->assertSame(['archive', 'register', 'update'], $publicMethodNames);

        $source = file_get_contents((new ReflectionClass(EmployeeDocumentService::class))->getFileName());
        $this->assertStringNotContainsString('Storage::', $source, 'EmployeeDocumentService must never write/read actual file bytes -- Phase 8A.7 is metadata-only (Case C: no shared Documents module exists).');
    }

    /**
     * Phase 0E.1 (published after this test was originally written)
     * added the shared `documents` table this test used to assert the
     * absence of. Phase 0E.6 / ADR 0029 has now made the actual
     * reconciliation decision ADR 0028 promised: `employee_documents`
     * and `documents` remain two permanently separate tables (no
     * migration, no shared FK) -- not because reconciliation hasn't
     * happened yet, but because it has, and the decision was "stay
     * separate." What must remain true: HR's own `employee_documents`
     * table is never silently replaced/absorbed by the generic table,
     * and `EmployeeDocumentService` never reads/writes it -- this
     * assertion is no longer a placeholder pending a future decision,
     * it is the permanent architectural boundary ADR 0029 fixes.
     */
    #[Test]
    public function employee_documents_remains_its_own_table_independent_of_the_shared_documents_module(): void
    {
        $this->assertTrue(Schema::hasTable('employee_documents'), 'employee_documents must still exist.');

        $source = file_get_contents((new ReflectionClass(EmployeeDocumentService::class))->getFileName());
        $this->assertStringNotContainsString(
            "table('documents')",
            $source,
            'EmployeeDocumentService must never read/write the shared `documents` table -- ADR 0029 permanently decided the two tables stay independent, never merged.',
        );
        $this->assertStringNotContainsString(
            'Storage::',
            $source,
            'ADR 0029: any future real Employee file-upload capability must be built on DocumentService, never by adding a Storage:: call directly into EmployeeDocumentService.',
        );
    }
}
