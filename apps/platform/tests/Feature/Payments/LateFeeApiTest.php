<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\LateFeeItemExecutor;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * FEE.5: the /api/v1 late-fee surface -- allow AND deny for every
 * operation (route middleware plus the Application service), Principal
 * denied by default, cross-School 404s, typed errors.
 */
class LateFeeApiTest extends TestCase
{
    use CreatesLateFeeFixtures;

    private function as(array $w, User $actor): static
    {
        return $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function every_operation_is_denied_without_its_capability_and_allowed_with_it(): void
    {
        $w = $this->lateFeeWorld();
        $b = "/api/v1/schools/{$w['school']->id}";
        Queue::fake();
        $active = $this->activeRule($w);
        $inactive = $this->lateRules()->create($w['school'], ['name' => 'Off', 'fee_structure_id' => $w['structure']->id, 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '5'], $w['actor']);
        $toggle = $this->activeRule($w, ['name' => 'Toggle']);
        $previewed = $this->previewedLateRun($w, $active);
        $cancellable = $this->lateRuns()->create($w['school'], $toggle->id, '2026-06-11', $w['actor']);
        $ruleBody = ['fee_structure_id' => $w['structure']->id, 'name' => 'New', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 3, 'kind' => 'percentage', 'percentage' => '1.5'];

        $unrelated = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view']);
        $operations = [
            ['GET', "{$b}/late-fee-rules", 'finance.fee_structures.view', []],
            ['GET', "{$b}/late-fee-rules/{$active->id}", 'finance.fee_structures.view', []],
            ['POST', "{$b}/late-fee-rules", 'finance.fee_structures.manage', $ruleBody],
            ['PATCH', "{$b}/late-fee-rules/{$inactive->id}", 'finance.fee_structures.manage', ['name' => 'Renamed', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 1, 'kind' => 'fixed', 'fixed_amount' => '7']],
            ['POST', "{$b}/late-fee-rules/{$inactive->id}/activate", 'finance.fee_structures.manage', []],
            ['GET', "{$b}/late-fee-runs", 'finance.charges.view', []],
            ['GET', "{$b}/late-fee-runs/{$previewed->id}", 'finance.charges.view', []],
            ['GET', "{$b}/late-fee-runs/{$previewed->id}/items", 'finance.charges.view', []],
            ['POST', "{$b}/late-fee-runs/{$previewed->id}/preview", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/late-fee-runs/{$cancellable->id}/cancel", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/late-fee-runs/{$previewed->id}/execute", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/late-fee-runs/{$previewed->id}/resume", 'finance.fee_assessments.run', []],
            ['POST', "{$b}/late-fee-rules/{$toggle->id}/deactivate", 'finance.fee_structures.manage', []],
            ['POST', "{$b}/late-fee-runs", 'finance.fee_assessments.run', ['late_fee_rule_id' => $inactive->id, 'evaluation_date' => '2026-06-12']],
        ];

        foreach ($operations as [$method, $url, , $body]) {
            $this->as($w, $unrelated)->json($method, $url, $body)->assertForbidden();
        }
        foreach ($operations as [$method, $url, $capability, $body]) {
            $response = $this->as($w, $this->createUserWithCapabilities($w['school'], [$capability]))->json($method, $url, $body);
            $this->assertContains($response->status(), [200, 201, 202], "{$method} {$url} with {$capability}: {$response->status()} {$response->getContent()}");
        }

        // Void: finance.charges.manage only.
        app(LateFeeItemExecutor::class)->executeBatch($w['school'], $previewed->id);
        $link = $this->lateFees($w)->sole();
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_assessments.run']))->postJson("{$b}/late-fee-assessments/{$link->id}/void")->assertForbidden();
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.manage']))->postJson("{$b}/late-fee-assessments/{$link->id}/void", ['reason' => 'test'])
            ->assertOk()->assertJsonPath('data.chargeId', $link->charge_id);
    }

    #[Test]
    public function principal_is_denied_and_errors_are_typed(): void
    {
        $w = $this->lateFeeWorld();
        $b = "/api/v1/schools/{$w['school']->id}";
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');
        $this->as($w, $principal)->getJson("{$b}/late-fee-rules")->assertForbidden();
        $this->as($w, $principal)->getJson("{$b}/late-fee-runs")->assertForbidden();

        $manager = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.manage']);
        $this->as($w, $manager)->postJson("{$b}/late-fee-rules", ['fee_structure_id' => $w['structure']->id, 'name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '0'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['fixed_amount']]]);
        $this->as($w, $manager)->postJson("{$b}/late-fee-rules", ['fee_structure_id' => $w['structure']->id, 'name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => -1, 'kind' => 'fixed', 'fixed_amount' => '1'])
            ->assertStatus(422);

        $rule = $this->activeRule($w);
        $this->as($w, $manager)->patchJson("{$b}/late-fee-rules/{$rule->id}", ['name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LATE_FEE_RULE_NOT_EDITABLE');

        $runner = $this->createUserWithCapabilities($w['school'], ['finance.fee_assessments.run']);
        $this->as($w, $runner)->postJson("{$b}/late-fee-runs", ['late_fee_rule_id' => $rule->id, 'evaluation_date' => '2026-06-11'])->assertCreated();
        $this->as($w, $runner)->postJson("{$b}/late-fee-runs", ['late_fee_rule_id' => $rule->id, 'evaluation_date' => '2026-06-11'])->assertStatus(409)->assertJsonPath('error.code', 'LATE_FEE_RUN_OPEN_EXISTS');

        $other = $this->lateFeeWorld();
        $foreign = $this->activeRule($other);
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']))->getJson("{$b}/late-fee-rules/{$foreign->id}")->assertNotFound();
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.view']))->getJson("{$b}/late-fee-runs/{$foreign->id}")->assertNotFound();
    }
}
