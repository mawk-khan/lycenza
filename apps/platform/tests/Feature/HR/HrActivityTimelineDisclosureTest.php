<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\Identity\Application\Minimization\UserMinimizationService;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.11 -- REQUIRED disclosure proof (checkpoint brief section
 * 59): even an authorized category viewer must receive only the
 * explicit safe allow-list (`EmployeeActivityTimelineEntry`), never
 * raw audit metadata values -- and the Timeline must tolerate
 * malformed/legacy metadata and unknown event names without ever
 * passing anything unexpected through (sections 55/56).
 */
class HrActivityTimelineDisclosureTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function raw_metadata_values_never_appear_in_a_serialized_timeline_even_for_a_fully_authorized_actor(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeePersonalDetailService::class)->setDetails($employee, [
            'personal_email' => 'sentinel-email@example.com',
            'personal_phone' => 'sentinel-phone-0000000000',
        ], $actor);
        app(EmployeeAddressService::class)->add($employee, [
            'address_type' => 'current', 'address_line1' => 'sentinel-address-line-1',
        ], $actor);
        app(EmployeeCertificationService::class)->add($employee, [
            'name' => 'Sentinel Certificate', 'issuer' => 'Sentinel Issuer', 'credential_number' => 'SENTINEL-CRED-999',
        ], $actor);
        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
            'storage_path' => 'employee-documents/sentinel-passport-scan.pdf',
            'original_filename' => 'sentinel-passport-scan.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2048,
        ], $actor);

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 100));
        $serialized = json_encode(array_map(fn ($e) => $e->toArray(), $result->items()));

        foreach ([
            'sentinel-email@example.com', 'sentinel-phone-0000000000', 'sentinel-address-line-1',
            'Sentinel Certificate', 'Sentinel Issuer', 'SENTINEL-CRED-999',
            'sentinel-passport-scan.pdf', 'employee-documents/sentinel-passport-scan.pdf',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serialized, "Raw value '{$sentinel}' must never appear in a serialized Timeline entry.");
        }
    }

    #[Test]
    public function document_events_never_expose_storage_or_provenance_metadata(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 's3',
            'storage_path' => 'employee-documents/x.pdf', 'original_filename' => 'x.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 999999,
        ], $actor);

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery);
        $serialized = json_encode(array_map(fn ($e) => $e->toArray(), $result->items()));

        foreach (['storage_path', 'storage_disk', 'original_filename', 'mime_type', 'uploaded_by_user_id', 999999] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $serialized);
        }
    }

    #[Test]
    public function an_audit_event_with_null_metadata_does_not_error_and_produces_an_empty_changed_fields_list(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        // Audit events are append-only at the DB privilege level (no
        // UPDATE is even possible) -- a genuinely null-metadata row can
        // only be produced via direct INSERT, not by mutating an
        // existing one. This has no Employee linkage at all and must
        // simply not resolve, not throw.
        app(TenantContext::class)->withSchool($school, function () use ($school) {
            SchoolAuditEvent::query()->create([
                'school_id' => $school->id,
                'occurred_at' => now(),
                'event_type' => 'employee.personal_details.updated',
                'metadata' => null,
            ]);
        });

        // Prove the service tolerates that malformed row's presence
        // alongside a second, well-formed, resolvable event.
        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'ok@example.com'], $actor);

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery);

        $this->assertNotNull($result);
        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'));
    }

    #[Test]
    public function malformed_fields_metadata_is_tolerated_and_normalized_to_an_empty_array(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->withSchool($school, function () use ($school, $employee) {
            $event = app(AuditRecorder::class)->school($school, 'employee.personal_details.updated', subject: null, metadata: [
                'employeeId' => $employee->id,
                'fields' => 'not-an-array',
            ]);
            $this->assertNotNull($event);
        });

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery);

        $entry = collect($result->items())->firstWhere('eventType', 'employee.personal_details.updated');
        $this->assertNotNull($entry);
        $this->assertSame([], $entry->changedFields);
    }

    #[Test]
    public function a_minimized_actor_reads_as_a_former_user_never_a_stale_name(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'a@example.com'], $actor);
        $staleName = $actor->name;
        // E21.4: a User is never deleted (F1); an erased actor becomes a minimized tombstone.
        DB::transaction(fn () => app(UserMinimizationService::class)->minimizeLocked(User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail()));

        $viewer = $this->fullHrActor($school);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $viewer, new EmployeeActivityTimelineQuery);

        $entry = collect($result->items())->firstWhere('eventType', 'employee.personal_details.updated');
        $this->assertNotNull($entry);
        // The entry still resolves, to the neutral name, never the person's.
        $this->assertSame(UserMinimizationService::FORMER_USER_NAME, $entry->actorDisplayName);
        $this->assertNotSame($staleName, $entry->actorDisplayName);
    }

    #[Test]
    public function unmapped_event_names_never_pass_through_even_when_metadata_carries_a_valid_employee_link(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->withSchool($school, function () use ($school, $employee) {
            app(AuditRecorder::class)->school($school, 'hr.something_not_yet_categorized', metadata: [
                'employeeId' => $employee->id,
                'sentinel' => 'must-not-leak',
            ]);
        });

        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 100));
        $serialized = json_encode(array_map(fn ($e) => $e->toArray(), $result->items()));

        $this->assertFalse(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.something_not_yet_categorized'));
        $this->assertStringNotContainsString('must-not-leak', $serialized);
    }
}
