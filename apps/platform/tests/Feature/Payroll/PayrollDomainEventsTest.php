<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\DomainEventOutbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.10 -- proves every shipped Payroll domain event is actually
 * dispatched, at the correct call site, through the existing
 * transactional outbox (App\Support\Events\ShouldBeOutboxed), with a
 * payload containing only the expected identifiers/metadata and no
 * amount-shaped/sensitive field, mirroring
 * `Tests\Feature\HR\HrEmployeeDomainEventsTest`'s established shape.
 */
class PayrollDomainEventsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const AMOUNT_SHAPED_KEYS = [
        'amount', 'grossAmount', 'gross_amount', 'netAmount', 'net_amount',
        'totalDeductions', 'total_deductions', 'deductionAmount', 'componentAmount',
        'compensationAmount', 'rate', 'value',
    ];

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * The MOST RECENT event of this type -- ordered by `id` (a UUIDv7,
     * time-ordered by construction, see DomainEventOutbox's own
     * docblock), never `created_at`, since two events dispatched
     * within the same wall-clock second would otherwise tie and make
     * this non-deterministic.
     */
    private function outboxRow(string $schoolId, string $eventType): ?DomainEventOutbox
    {
        return DomainEventOutbox::query()->where('school_id', $schoolId)->where('event_type', $eventType)->orderByDesc('id')->first();
    }

    /**
     * @return array{school: School, structureManager: User, runManager: User, salaryComponentId: string, structureId: string, employmentRecordId: string, assignmentId: string}
     */
    private function arrangeCompensatedEmploymentRecord(): array
    {
        $school = $this->createSchool();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        $ctx = $this->context()->withSchool($school, function () use ($school, $structureManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-EVT', 'Grade Events', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            $assignment = app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            return ['structureId' => $structure->id, 'employmentRecordId' => $employmentRecord->id, 'assignmentId' => $assignment->id];
        });

        return $ctx + ['school' => $school, 'structureManager' => $structureManager, 'runManager' => $runManager];
    }

    /**
     * @return array{school: School, runId: string, structureManager: User, runManager: User}
     */
    private function arrangeCalculatedRun(): array
    {
        $f = $this->arrangeCompensatedEmploymentRecord();

        $runId = $this->context()->withSchool($f['school'], function () use ($f) {
            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $f['runManager']), $f['runManager']);

            $runService = app(PayrollRunAdministrationService::class);
            $run = $runService->createRun($period, $f['runManager']);
            $runService->calculate($run, $f['runManager']);

            return $run->id;
        });

        return $f + ['runId' => $runId];
    }

    /**
     * @return array{school: School, runId: string, structureManager: User, runManager: User, approver: User}
     */
    private function arrangeApprovedRun(): array
    {
        $f = $this->arrangeCalculatedRun();
        $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);

        $this->context()->withSchool($f['school'], function () use ($f, $approver) {
            app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($f['runId']), $approver);
        });

        return $f + ['approver' => $approver];
    }

    /**
     * @return array{school: School, runId: string, structureManager: User, runManager: User, approver: User, poster: User}
     */
    private function arrangePostedRun(): array
    {
        $f = $this->arrangeApprovedRun();
        $poster = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post', 'payroll.runs.reverse']);

        $this->context()->withSchool($f['school'], function () use ($f, $poster) {
            app(PayrollPostingAdministrationService::class)->post(PayrollRun::query()->findOrFail($f['runId']), $poster);
        });

        return $f + ['poster' => $poster];
    }

    #[Test]
    public function payroll_run_created_regular_is_dispatched_with_a_minimal_payload(): void
    {
        $f = $this->arrangeCompensatedEmploymentRecord();

        $runId = $this->context()->withSchool($f['school'], function () use ($f) {
            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $f['runManager']), $f['runManager']);

            return app(PayrollRunAdministrationService::class)->createRun($period, $f['runManager'])->id;
        });

        $row = $this->outboxRow($f['school']->id, 'payroll_run.created.v1');
        $this->assertNotNull($row);
        $this->assertSame($runId, $row->payload['payrollRunId']);
        $this->assertSame('regular', $row->payload['runKind']);
        $this->assertNull($row->payload['correctsPayrollRunId']);
        $this->assertPayloadKeys(['payrollRunId', 'payrollPeriodId', 'runKind', 'correctsPayrollRunId'], $row->payload);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function payroll_run_created_correction_is_dispatched_referencing_the_original(): void
    {
        $f = $this->arrangePostedRun();
        $periodService = app(PayrollPeriodAdministrationService::class);

        $correctionId = $this->context()->withSchool($f['school'], function () use ($f, $periodService) {
            $period = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-10-01'), null, $f['runManager']), $f['runManager']);

            return app(PayrollRunAdministrationService::class)->createCorrectionRun(PayrollRun::query()->findOrFail($f['runId']), $period, $f['runManager'])->id;
        });

        $row = $this->outboxRow($f['school']->id, 'payroll_run.created.v1');
        $this->assertNotNull($row);
        $this->assertSame($correctionId, $row->payload['payrollRunId']);
        $this->assertSame('correction', $row->payload['runKind']);
        $this->assertSame($f['runId'], $row->payload['correctsPayrollRunId']);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function payroll_run_calculated_is_dispatched_with_expected_counts(): void
    {
        $f = $this->arrangeCalculatedRun();

        $row = $this->outboxRow($f['school']->id, 'payroll_run.calculated.v1');
        $this->assertNotNull($row);
        $this->assertSame($f['runId'], $row->payload['payrollRunId']);
        $this->assertSame(1, $row->payload['resolvedCount']);
        $this->assertSame(0, $row->payload['unresolvedCount']);
        $this->assertTrue($row->payload['transitionedToCalculated']);
        $this->assertPayloadKeys(['payrollRunId', 'resolvedCount', 'unresolvedCount', 'transitionedToCalculated'], $row->payload);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function payroll_run_approved_is_dispatched(): void
    {
        $f = $this->arrangeApprovedRun();

        $row = $this->outboxRow($f['school']->id, 'payroll_run.approved.v1');
        $this->assertNotNull($row);
        $this->assertSame($f['runId'], $row->payload['payrollRunId']);
        $this->assertSame($f['approver']->id, $row->payload['approvedByUserId']);
        $this->assertSame($f['runManager']->id, $row->payload['preparedByUserId']);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function a_denied_self_approval_emits_no_event(): void
    {
        $f = $this->arrangeCompensatedEmploymentRecord();
        // Holds BOTH .prepare and .approve -- the actor-level SoD rule
        // (never approve your own prepared run) is what must block this,
        // independent of capability grants.
        $bothCapabilities = $this->createUserWithCapabilities($f['school'], ['payroll.runs.prepare', 'payroll.periods.manage', 'payroll.runs.approve']);

        $runId = $this->context()->withSchool($f['school'], function () use ($f, $bothCapabilities) {
            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $bothCapabilities), $bothCapabilities);
            $run = app(PayrollRunAdministrationService::class)->createRun($period, $bothCapabilities);
            app(PayrollRunAdministrationService::class)->calculate($run, $bothCapabilities);

            return $run->id;
        });

        $this->context()->withSchool($f['school'], function () use ($runId, $bothCapabilities) {
            $run = PayrollRun::query()->findOrFail($runId);
            try {
                app(PayrollRunAdministrationService::class)->approve($run, $bothCapabilities);
                $this->fail('Expected SelfApprovalNotAllowedException.');
            } catch (SelfApprovalNotAllowedException) {
                // expected
            }
        });

        $this->assertNull($this->outboxRow($f['school']->id, 'payroll_run.approved.v1'));
    }

    #[Test]
    public function an_authorization_denied_approve_attempt_emits_no_event(): void
    {
        $f = $this->arrangeCalculatedRun();
        $unauthorizedActor = $this->createUserWithCapabilities($f['school'], []);

        $this->context()->withSchool($f['school'], function () use ($f, $unauthorizedActor) {
            $run = PayrollRun::query()->findOrFail($f['runId']);
            try {
                app(PayrollRunAdministrationService::class)->approve($run, $unauthorizedActor);
                $this->fail('Expected AuthorizationException.');
            } catch (AuthorizationException) {
                // expected
            }
        });

        $this->assertNull($this->outboxRow($f['school']->id, 'payroll_run.approved.v1'));
    }

    #[Test]
    public function payroll_run_posted_is_dispatched_with_a_structural_line_count_never_an_amount(): void
    {
        $f = $this->arrangePostedRun();

        $row = $this->outboxRow($f['school']->id, 'payroll_run.posted.v1');
        $this->assertNotNull($row);
        $this->assertSame($f['runId'], $row->payload['payrollRunId']);
        $this->assertIsString($row->payload['journalEntryId']);
        $this->assertIsInt($row->payload['lineCount']);
        $this->assertSame($f['poster']->id, $row->payload['postedByUserId']);
        $this->assertPayloadKeys(['payrollRunId', 'journalEntryId', 'lineCount', 'postedByUserId'], $row->payload);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function payroll_run_reversed_is_dispatched(): void
    {
        $f = $this->arrangePostedRun();

        $this->context()->withSchool($f['school'], function () use ($f) {
            app(PayrollPostingAdministrationService::class)->reverse(PayrollRun::query()->findOrFail($f['runId']), $f['poster'], 'test reversal');
        });

        $row = $this->outboxRow($f['school']->id, 'payroll_run.reversed.v1');
        $this->assertNotNull($row);
        $this->assertSame($f['runId'], $row->payload['payrollRunId']);
        $this->assertSame($f['poster']->id, $row->payload['reversedByUserId']);
        $this->assertIsString($row->payload['originalPostingId']);
        $this->assertIsString($row->payload['reversalJournalEntryId']);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function employee_compensation_assigned_is_dispatched_for_a_first_assignment(): void
    {
        $f = $this->arrangeCompensatedEmploymentRecord();

        $row = $this->outboxRow($f['school']->id, 'employee_compensation.assigned.v1');
        $this->assertNotNull($row);
        $this->assertSame($f['employmentRecordId'], $row->payload['employmentRecordId']);
        $this->assertSame($f['assignmentId'], $row->payload['compensationAssignmentId']);
        $this->assertSame($f['structureId'], $row->payload['salaryStructureId']);
        $this->assertNull($row->payload['previousAssignmentId']);
        $this->assertSame('2025-01-01', $row->payload['effectiveFrom']);
        $this->assertPayloadKeys(['employmentRecordId', 'compensationAssignmentId', 'salaryStructureId', 'previousAssignmentId', 'effectiveFrom'], $row->payload);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function employee_compensation_assigned_is_dispatched_for_a_supersession_referencing_the_previous_assignment(): void
    {
        $f = $this->arrangeCompensatedEmploymentRecord();

        $newAssignmentId = $this->context()->withSchool($f['school'], function () use ($f) {
            $employmentRecord = EmploymentRecord::query()->findOrFail($f['employmentRecordId']);
            $structure = SalaryStructure::query()->findOrFail($f['structureId']);
            $component = SalaryStructureComponent::query()->where('salary_structure_id', $structure->id)->firstOrFail();

            return app(PayrollCompensationAdministrationService::class)->assign(
                $f['school'], $employmentRecord, $structure, Carbon::parse('2026-01-01'),
                [new FixedComponentValueInput($component->id, '60000.00')], $f['structureManager'],
            )->id;
        });

        $row = $this->outboxRow($f['school']->id, 'employee_compensation.assigned.v1');
        $this->assertNotNull($row);
        $this->assertSame($newAssignmentId, $row->payload['compensationAssignmentId']);
        $this->assertSame($f['assignmentId'], $row->payload['previousAssignmentId']);
        $this->assertNoAmountShapedKeys($row->payload);
    }

    #[Test]
    public function no_payroll_event_payload_ever_carries_an_amount_shaped_key(): void
    {
        $f = $this->arrangePostedRun();

        $rows = $this->context()->withSchool(
            $f['school'],
            fn () => DomainEventOutbox::query()->where('school_id', $f['school']->id)->get(),
        );

        $this->assertGreaterThanOrEqual(4, $rows->count());
        foreach ($rows as $row) {
            $this->assertNoAmountShapedKeys($row->payload, $row->event_type);
        }
    }

    private function assertNoAmountShapedKeys(array $payload, string $context = ''): void
    {
        $offending = array_intersect(self::AMOUNT_SHAPED_KEYS, array_keys($payload));
        $this->assertSame([], $offending, "Payload {$context} must never carry an amount-shaped key: ".implode(', ', $offending));
    }

    /**
     * jsonb does not preserve original key insertion order (Postgres
     * normalizes it), so a payload's key SET is asserted, never key
     * order.
     */
    private function assertPayloadKeys(array $expectedKeys, array $payload): void
    {
        $actual = array_keys($payload);
        sort($expectedKeys);
        sort($actual);
        $this->assertSame($expectedKeys, $actual);
    }
}
