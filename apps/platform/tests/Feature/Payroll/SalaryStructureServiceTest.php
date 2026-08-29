<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\Exceptions\InvalidStructureTransitionException;
use App\Domain\Payroll\Application\Exceptions\StructureNotDraftException;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.2 functional tests for `SalaryStructureService`
 * (ADR 0032 "Salary structure revision model").
 */
class SalaryStructureServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): SalaryStructureService
    {
        return app(SalaryStructureService::class);
    }

    private function componentService(): SalaryComponentService
    {
        return app(SalaryComponentService::class);
    }

    #[Test]
    public function it_creates_a_draft_structure(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();

        $structure = app(TenantContext::class)->withSchool(
            $school,
            fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor),
        );

        $this->assertSame('GRADE1', $structure->code);
        $this->assertSame(1, $structure->version);
        $this->assertTrue($structure->isDraft());
    }

    #[Test]
    public function a_new_draft_for_an_existing_code_increments_the_version(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $v1 = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor));
        $v2 = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I (revised)', $actor));

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
    }

    #[Test]
    public function a_draft_structure_can_have_components_added(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $component] = $context->withSchool($school, function () use ($school, $actor) {
            $structure = $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor);
            $component = $this->componentService()->create($school, 'BASIC', 'Basic', 'earning', null, $actor);

            return [$structure, $component];
        });

        $structureComponent = $context->withSchool($school, fn () => $this->service()->addComponent(
            $structure,
            new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1),
            $actor,
        ));

        $this->assertTrue($structureComponent->isFixedAmount());
        $this->assertSame($structure->id, $structureComponent->salary_structure_id);
    }

    #[Test]
    public function activating_a_structure_makes_it_immutable(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $structure = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor));
        $activated = $context->withSchool($school, fn () => $this->service()->activate($structure, $actor));

        $this->assertTrue($activated->isActive());
    }

    #[Test]
    public function a_component_cannot_be_added_to_an_active_structure(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $component] = $context->withSchool($school, function () use ($school, $actor) {
            $structure = $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor);
            $component = $this->componentService()->create($school, 'BASIC', 'Basic', 'earning', null, $actor);

            return [$structure, $component];
        });

        $context->withSchool($school, fn () => $this->service()->activate($structure, $actor));

        $this->expectException(StructureNotDraftException::class);

        $context->withSchool($school, fn () => $this->service()->addComponent(
            $structure->fresh(),
            new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1),
            $actor,
        ));
    }

    #[Test]
    public function activating_a_new_revision_supersedes_the_previous_active_one(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $v1 = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor));
        $context->withSchool($school, fn () => $this->service()->activate($v1, $actor));

        $v2 = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I v2', $actor));
        $context->withSchool($school, fn () => $this->service()->activate($v2, $actor));

        $this->assertSame('superseded', $context->withSchool($school, fn () => $v1->fresh())->status);
        $this->assertSame('active', $context->withSchool($school, fn () => $v2->fresh())->status);
    }

    #[Test]
    public function only_a_draft_structure_may_be_activated(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $structure = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor));
        $context->withSchool($school, fn () => $this->service()->activate($structure, $actor));

        $this->expectException(InvalidStructureTransitionException::class);

        $context->withSchool($school, fn () => $this->service()->activate($structure->fresh(), $actor));
    }

    #[Test]
    public function activation_is_audited_and_records_the_previous_active_structure(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $structure = $context->withSchool($school, fn () => $this->service()->createDraft($school, 'GRADE1', 'Grade I', $actor));
        $context->withSchool($school, fn () => $this->service()->activate($structure, $actor));

        $event = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.structure.activated')->where('subject_id', $structure->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertNull($event->metadata['previousActiveStructureId']);
    }
}
