<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\StructureComponentNotFixedAmountException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.2 functional tests for `CompensationService`
 * (ADR 0032 "Compensation assignment").
 */
class CompensationServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): CompensationService
    {
        return app(CompensationService::class);
    }

    /**
     * @return array{0: SalaryStructure, 1: string, 2: string} [structure, basicComponentId (fixed), hraComponentId (percentage)]
     */
    private function makeActiveStructureWithBasicAndHra(School $school): array
    {
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        return $context->withSchool($school, function () use ($school, $actor, $structureService, $componentService) {
            $structure = $structureService->createDraft($school, 'GRADE1', 'Grade I', $actor);
            $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $actor);
            $hra = $componentService->create($school, 'HRA', 'HRA', 'earning', null, $actor);

            $basicStructureComponent = $structureService->addComponent(
                $structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $actor,
            );
            $structureService->addComponent(
                $structure, new AddStructureComponentData($hra->id, 'percentage_of_base', $basicStructureComponent->id, '0.400000', 2), $actor,
            );

            $activated = $structureService->activate($structure, $actor);

            return [$activated, $basicStructureComponent->id, $activated->components()->where('salary_component_id', $hra->id)->first()->id];
        });
    }

    #[Test]
    public function it_creates_a_compensation_assignment_with_a_fixed_value(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);

        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $assignment = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')],
            $actor,
        ));

        $this->assertSame($employmentRecord->id, $assignment->employment_record_id);
        $this->assertTrue($assignment->isOpenEnded());
    }

    #[Test]
    public function fixed_component_values_are_persisted(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $assignment = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')],
            $actor,
        ));

        $value = $context->withSchool($school, fn () => CompensationAssignmentValue::query()->where('assignment_id', $assignment->id)->first());

        $this->assertNotNull($value);
        $this->assertSame('50000.00', $value->amount);
    }

    #[Test]
    public function a_percentage_component_rejects_an_employee_specific_value(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, , $hraStructureComponentId] = $this->makeActiveStructureWithBasicAndHra($school);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $this->expectException(StructureComponentNotFixedAmountException::class);

        $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($hraStructureComponentId, '1000.00')],
            $actor,
        ));
    }

    #[Test]
    public function it_resolves_the_assignment_effective_on_a_given_date(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')],
            $actor,
        ));

        $resolved = $context->withSchool($school, fn () => $this->service()->resolveEffective($employmentRecord, Carbon::parse('2026-06-15')));
        $notYetEffective = $context->withSchool($school, fn () => $this->service()->resolveEffective($employmentRecord, Carbon::parse('2025-12-31')));

        $this->assertNotNull($resolved);
        $this->assertNull($notYetEffective);
    }

    #[Test]
    public function replacing_compensation_closes_the_prior_range_and_leaves_history_unchanged(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $first = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')],
            $actor,
        ));

        $second = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-07-01'),
            [new FixedComponentValueInput($basicComponentId, '60000.00')],
            $actor,
        ));

        $firstFresh = $context->withSchool($school, fn () => $first->fresh());
        $this->assertSame('2026-06-30', $firstFresh->effective_to->toDateString());
        // Historical value row is untouched.
        $firstValue = $context->withSchool($school, fn () => CompensationAssignmentValue::query()->where('assignment_id', $first->id)->first());
        $this->assertSame('50000.00', $firstValue->amount);

        $this->assertTrue($second->isOpenEnded());
        $secondValue = $context->withSchool($school, fn () => CompensationAssignmentValue::query()->where('assignment_id', $second->id)->first());
        $this->assertSame('60000.00', $secondValue->amount);
    }

    #[Test]
    public function a_rehired_employee_gets_independent_compensation_history_per_employment_record(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);

        $employee = $context->withSchool($school, fn () => $this->createEmployee($school));
        $firstEmployment = $context->withSchool($school, fn () => $this->createEmploymentRecord($employee, ['ends_on' => '2026-01-31', 'status' => 'terminated']));
        $secondEmployment = $context->withSchool($school, fn () => $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01']));

        $context->withSchool($school, fn () => $this->service()->assign(
            $school, $firstEmployment, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '40000.00')],
            $actor,
        ));

        // No overlap error -- a DIFFERENT EmploymentRecord for the SAME
        // Employee has entirely independent compensation history.
        $second = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $secondEmployment, $structure, Carbon::parse('2026-06-01'),
            [new FixedComponentValueInput($basicComponentId, '55000.00')],
            $actor,
        ));

        $this->assertSame($secondEmployment->id, $second->employment_record_id);
    }

    #[Test]
    public function a_cross_school_employment_record_reference_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structureA] = $this->makeActiveStructureWithBasicAndHra($schoolA);

        $employmentInB = $context->withSchool($schoolB, fn () => $this->createEmploymentRecord($this->createEmployee($schoolB)));

        $this->expectException(ModelNotFoundException::class);

        // Attempting to assign School A's structure against School B's
        // EmploymentRecord under School A's tenant context -- the
        // EmploymentRecord is invisible under RLS (no oracle), so the
        // lock-then-check step's firstOrFail() throws.
        $context->withSchool($schoolA, fn () => $this->service()->assign(
            $schoolA, $employmentInB, $structureA, Carbon::parse('2026-01-01'), [], $actor,
        ));
    }

    #[Test]
    public function assignment_is_audited_without_the_raw_compensation_amount(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);
        [$structure, $basicComponentId] = $this->makeActiveStructureWithBasicAndHra($school);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school)));

        $assignment = $context->withSchool($school, fn () => $this->service()->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')],
            $actor,
        ));

        $event = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.compensation.assigned')->where('subject_id', $assignment->id)->first(),
        );

        $this->assertNotNull($event);
        $metadataJson = json_encode($event->metadata);
        $this->assertStringNotContainsString('50000', $metadataJson);
        $this->assertSame($employmentRecord->id, $event->metadata['employmentRecordId']);
    }
}
