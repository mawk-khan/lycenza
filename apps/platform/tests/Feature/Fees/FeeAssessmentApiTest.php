<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\FeeAssessmentItemExecutor;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * FEE.2: the /api/v1 assessment-run surface -- allow AND deny for every
 * operation (route middleware plus the Application service), Principal
 * denied by default, cross-School 404s, typed conflicts, and preview
 * creating no charge.
 */
class FeeAssessmentApiTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    private function as(array $w, User $actor): static
    {
        return $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    #[Test]
    public function every_operation_is_denied_without_its_capability_and_allowed_with_it(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->enroll($w);
        $b = $this->base($w);
        Queue::fake();

        $draft = $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['actor']);
        $cancellable = $this->runs()->create($w['school'], $w['structure']->id, 'T2', $w['actor']);
        $previewed = $this->runs()->preview($w['school'], $draft->id, $w['actor']);
        $item = $this->items($w, $previewed)->first();

        $unrelated = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view', 'finance.fee_structures.manage']);
        $operations = [
            ['GET', "{$b}/fee-assessment-runs", 'finance.charges.view', []],
            ['GET', "{$b}/fee-assessment-runs/{$draft->id}", 'finance.charges.view', []],
            ['GET', "{$b}/fee-assessment-runs/{$draft->id}/items", 'finance.charges.view', []],
            ['POST', "{$b}/fee-assessment-runs/{$draft->id}/items/{$item->id}/exclude", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/fee-assessment-runs/{$draft->id}/preview", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/fee-assessment-runs/{$draft->id}/execute", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/fee-assessment-runs/{$draft->id}/resume", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/fee-assessment-runs/{$cancellable->id}/cancel", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/fee-assessment-runs", 'finance.fee_assessments.run', ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T2']],
        ];

        foreach ($operations as [$method, $url]) {
            $this->as($w, $unrelated)->json($method, $url, [])->assertForbidden();
        }

        foreach ($operations as [$method, $url, $capability, $body]) {
            $actor = $this->createUserWithCapabilities($w['school'], [$capability]);
            $status = $this->as($w, $actor)->json($method, $url, $body)->status();
            $this->assertContains($status, [200, 201, 202], "{$method} {$url} with {$capability} must succeed, got {$status}.");
        }

        // Void: finance.charges.manage only.
        Queue::assertPushed(ExecuteFeeAssessmentRunJob::class);
        app(FeeAssessmentItemExecutor::class)->executeBatch($w['school'], $draft->id);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->first());
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_assessments.run']))
            ->postJson("{$b}/fee-assessments/{$assessment->id}/void")->assertForbidden();
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.manage']))
            ->postJson("{$b}/fee-assessments/{$assessment->id}/void", ['reason' => 'test'])->assertOk()->assertJsonPath('data.id', $assessment->id);
    }

    #[Test]
    public function the_principal_is_denied_by_default(): void
    {
        $w = $this->assessmentWorld();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');

        $this->as($w, $principal)->getJson($this->base($w).'/fee-assessment-runs')->assertForbidden();
        $this->as($w, $principal)->postJson($this->base($w).'/fee-assessment-runs', ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->assertForbidden();
    }

    #[Test]
    public function preview_creates_no_charge_and_execute_bills_through_the_queue(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $b = $this->base($w);

        $id = $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 't1'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs/{$id}/preview")
            ->assertOk()->assertJsonPath('data.preview.readyCount', 1)->assertJsonPath('data.preview.readyAmount', '5000.00')->assertJsonPath('data.previewIsCurrent', true);
        $this->as($w, $w['actor'])->getJson("/api/v1/schools/{$w['school']->id}/charges")->assertOk()->assertJsonCount(0, 'data');

        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs/{$id}/execute")->assertStatus(202);
        $this->as($w, $w['actor'])->getJson("{$b}/fee-assessment-runs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.execution.succeededCount', 1)->assertJsonPath('data.execution.assessedAmount', '5000.00');
        $this->as($w, $w['actor'])->getJson("{$b}/fee-assessment-runs/{$id}/items")->assertOk()
            ->assertJsonPath('data.0.executionStatus', 'succeeded')->assertJsonPath('data.0.amount', '5000.00')->assertJsonMissingPath('data.0.schoolId');
    }

    #[Test]
    public function conflicts_and_validation_keep_their_codes(): void
    {
        $w = $this->assessmentWorld();
        $b = $this->base($w);
        $draft = $this->makeDraftStructure($w, ['code' => 'NOT-ACTIVE']);

        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs", ['fee_structure_id' => $draft->id, 'billing_period_key' => 'T1'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['fee_structure_id']]]);
        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T9'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['billing_period_key']]]);

        $id = $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->json('data.id');
        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'FEE_ASSESSMENT_RUN_OPEN_EXISTS');
        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs/{$id}/execute")
            ->assertStatus(409)->assertJsonPath('error.code', 'FEE_ASSESSMENT_RUN_STALE_PREVIEW');
        $this->as($w, $w['actor'])->postJson("{$b}/fee-assessment-runs/{$id}/resume")
            ->assertStatus(409)->assertJsonPath('error.code', 'FEE_ASSESSMENT_RUN_ILLEGAL_STATE');
    }

    #[Test]
    public function another_schools_runs_are_404s(): void
    {
        $a = $this->assessmentWorld();
        $b = $this->assessmentWorld();
        $foreign = $this->runs()->create($b['school'], $b['structure']->id, 'T1', $b['actor']);

        $this->as($a, $a['actor'])->getJson($this->base($a)."/fee-assessment-runs/{$foreign->id}")->assertNotFound();
        $this->as($a, $a['actor'])->postJson($this->base($a)."/fee-assessment-runs/{$foreign->id}/preview")->assertNotFound();
        $this->as($a, $a['actor'])->postJson($this->base($a).'/fee-assessment-runs', ['fee_structure_id' => $b['structure']->id, 'billing_period_key' => 'T1'])->assertStatus(422);
    }
}
