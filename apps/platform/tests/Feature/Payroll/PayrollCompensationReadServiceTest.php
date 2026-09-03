<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\Exceptions\CompensationAssignmentNotFoundException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationReadService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.7 (corrected at its own authorization review) -- proves
 * `PayrollCompensationReadService`'s two sensitivity-split read paths:
 * `payroll.compensation.view` (non-sensitive identity/history) and
 * `payroll.compensation.sensitive.view` (Highly Sensitive amounts),
 * mirroring `PayrollRunResultReadServiceTest`'s authorization/
 * disclosure/audit discipline. Proves specifically that the
 * non-sensitive path never even queries
 * `compensation_assignment_values` -- not merely omits amounts from
 * its DTO.
 */
class PayrollCompensationReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * @return array{school: School, employmentRecordId: string, assignmentId: string}
     */
    private function makeAssignment(): array
    {
        $school = $this->createSchool();
        $context = $this->context();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.compensation.sensitive.manage',
        ]);

        [$employmentRecordId, $assignmentId] = $context->withSchool($school, function () use ($school, $structureManager) {
            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-COMP-READ', 'Grade Comp Read', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            $assignment = app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            return [$employmentRecord->id, $assignment->id];
        });

        return ['school' => $school, 'employmentRecordId' => $employmentRecordId, 'assignmentId' => $assignmentId];
    }

    #[Test]
    public function an_actor_with_the_non_sensitive_capability_can_list_assignment_metadata(): void
    {
        $f = $this->makeAssignment();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.view']);
        $employmentRecord = $this->context()->withSchool($f['school'], fn () => EmploymentRecord::query()->findOrFail($f['employmentRecordId']));

        $summaries = $this->context()->withSchool(
            $f['school'],
            fn () => app(PayrollCompensationReadService::class)->listAssignments($f['school'], $employmentRecord, $actor),
        );

        $this->assertCount(1, $summaries);
        $this->assertSame($f['assignmentId'], $summaries[0]->id);
        $this->assertSame($f['employmentRecordId'], $summaries[0]->employmentRecordId);
    }

    #[Test]
    public function the_non_sensitive_summary_carries_no_amount_field_and_never_queries_compensation_values(): void
    {
        $f = $this->makeAssignment();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.view']);
        $employmentRecord = $this->context()->withSchool($f['school'], fn () => EmploymentRecord::query()->findOrFail($f['employmentRecordId']));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $summaries = $this->context()->withSchool(
            $f['school'],
            fn () => app(PayrollCompensationReadService::class)->listAssignments($f['school'], $employmentRecord, $actor),
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries, 'sanity: the non-sensitive read must run at least one query');
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'compensation_assignment_values',
                $query['query'],
                'the non-sensitive read must never query compensation_assignment_values at all.',
            );
        }

        $properties = array_keys(get_object_vars($summaries[0]));
        foreach (['amount', 'grossAmount', 'netAmount', 'totalDeductions'] as $sensitiveField) {
            $this->assertNotContains($sensitiveField, $properties, "CompensationAssignmentSummary must never carry a '{$sensitiveField}' field.");
        }
    }

    #[Test]
    public function listing_assignments_is_denied_without_the_non_sensitive_capability(): void
    {
        $f = $this->makeAssignment();
        $actor = $this->createUserWithCapabilities($f['school'], []);
        $employmentRecord = $this->context()->withSchool($f['school'], fn () => EmploymentRecord::query()->findOrFail($f['employmentRecordId']));

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool(
            $f['school'],
            fn () => app(PayrollCompensationReadService::class)->listAssignments($f['school'], $employmentRecord, $actor),
        );
    }

    #[Test]
    public function an_actor_with_the_sensitive_capability_can_view_assignment_values_and_it_is_audited_exactly_once(): void
    {
        $f = $this->makeAssignment();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.sensitive.view']);

        $values = app(PayrollCompensationReadService::class)->getAssignmentValues($f['school'], $f['assignmentId'], $actor);

        $this->assertCount(1, $values);
        $this->assertSame('50000.00', $values[0]->amount);

        $auditCount = $this->context()->withSchool(
            $f['school'],
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.compensation.values_viewed')->where('subject_id', $f['assignmentId'])->count(),
        );
        $this->assertSame(1, $auditCount, 'a successful sensitive read must be audited exactly once.');

        $event = $this->context()->withSchool(
            $f['school'],
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.compensation.values_viewed')->where('subject_id', $f['assignmentId'])->first(),
        );
        $this->assertSame($actor->id, $event->actor_user_id);
        $metadata = (array) $event->metadata;
        $this->assertArrayNotHasKey('amount', $metadata);
        $this->assertArrayHasKey('valueCount', $metadata);
        $this->assertSame(1, $metadata['valueCount']);
    }

    #[Test]
    public function viewing_assignment_values_is_denied_without_the_sensitive_capability_and_leaves_no_audit_trail(): void
    {
        $f = $this->makeAssignment();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.compensation.view']);

        try {
            app(PayrollCompensationReadService::class)->getAssignmentValues($f['school'], $f['assignmentId'], $actor);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            // expected
        }

        $auditCount = $this->context()->withSchool(
            $f['school'],
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.compensation.values_viewed')->where('subject_id', $f['assignmentId'])->count(),
        );
        $this->assertSame(0, $auditCount, 'a denied sensitive-read attempt must leave zero audit trail and zero result leakage.');
    }

    #[Test]
    public function a_cross_school_assignment_id_raises_the_same_not_found_error_as_a_nonexistent_one(): void
    {
        $f = $this->makeAssignment();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['payroll.compensation.sensitive.view']);

        $this->expectException(CompensationAssignmentNotFoundException::class);

        app(PayrollCompensationReadService::class)->getAssignmentValues($otherSchool, $f['assignmentId'], $actor);
    }
}
