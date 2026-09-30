<?php

namespace Tests\Feature\App;

use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * FEE.5: the Finance -> Late fees pages (rules, runs, run detail), the
 * charge-page link between a source charge and its late fee, the
 * statement's late-fee links, and that a hidden button is never the only
 * protection.
 */
class LateFeeUiTest extends TestCase
{
    use CreatesLateFeeFixtures;

    private function memberWith(array $capabilities, School $school): User
    {
        $role = Role::query()->create(['key' => 'late-fee-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role->key);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function rules_are_managed_through_the_page_with_fee_setup_authority(): void
    {
        $w = $this->lateFeeWorld();

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance/late-fees')->assertForbidden();

        $this->memberWith(['finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance/late-fees')->assertInertia(fn ($page) => $page->component('App/Finance/FeeSetup/LateFees')->where('canManage', false));
        $this->post('/app/finance/late-fees', [])->assertForbidden();

        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);
        $this->post('/app/finance/late-fees', ['fee_structure_id' => $w['structure']->id, 'name' => 'Late', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 5, 'kind' => 'fixed', 'fixed_amount' => '100', 'max_amount' => null])
            ->assertRedirect('/app/finance/late-fees')->assertSessionHasNoErrors();
        $rule = $this->lateRules()->list($w['school'], $w['actor'])->sole();
        $this->post("/app/finance/late-fees/{$rule->id}/status", ['status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/app/finance/late-fees/{$rule->id}", ['name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 1, 'kind' => 'fixed', 'fixed_amount' => '1'])->assertSessionHasErrors('action');
        $this->get('/app/finance/late-fees')->assertInertia(fn ($page) => $page->where('rules.0.status', 'active')->where('rules.0.graceDays', 5)->where('canManage', true));
    }

    #[Test]
    public function a_run_goes_through_the_pages_and_the_charges_link_both_ways(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w, ['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00', 'max_amount' => '300.00']);

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance/late-fee-runs')->assertInertia(fn ($page) => $page->where('canRun', false)->where('activeRules', []));
        $this->post('/app/finance/late-fee-runs', ['late_fee_rule_id' => $rule->id])->assertForbidden();

        $this->memberWith(['finance.charges.view', 'finance.charges.manage', 'finance.fee_assessments.run', 'finance.fee_structures.view', 'finance.payments.view'], $w['school']);
        $this->get('/app/finance/late-fee-runs')->assertInertia(fn ($page) => $page->where('canRun', true)->has('activeRules', 1));
        $this->post('/app/finance/late-fee-runs', ['late_fee_rule_id' => $rule->id, 'evaluation_date' => '2026-06-11'])->assertRedirect();
        $run = $this->inSchool($w['school'], fn () => LateFeeRun::query()->sole());
        $base = "/app/finance/late-fee-runs/{$run->id}";

        $this->post("{$base}/preview")->assertRedirect($base);
        $this->get($base)->assertInertia(fn ($page) => $page
            ->component('App/Finance/FeeSetup/LateFeeRun')
            ->where('items.data.0.previewResult', 'ready')
            ->where('items.data.0.calculatedAmount', '500.00')
            ->where('items.data.0.finalAmount', '300.00')
            ->where('items.data.0.capApplied', true));
        $this->post("{$base}/execute")->assertRedirect($base)->assertSessionHasNoErrors();

        $lateFee = $this->lateFees($w)->sole();
        $this->get($base)->assertInertia(fn ($page) => $page->where('run.status', 'completed')->where('items.data.0.lateFeeChargeId', $lateFee->charge_id));
        $this->get("/app/finance/charges/{$w['source']->id}")->assertInertia(fn ($page) => $page->where('lateFees.raised.0.chargeId', $lateFee->charge_id)->where('lateFees.sourceChargeId', null));
        $this->get("/app/finance/charges/{$lateFee->charge_id}")->assertInertia(fn ($page) => $page->where('lateFees.sourceChargeId', $w['source']->id));

        $statement = app(StudentFeeStatementReadService::class)->statementFor($w['school'], $w['source']->student_id, null, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']));
        $lines = collect($statement->lines)->keyBy('chargeId');
        $this->assertSame([$lateFee->charge_id], $lines[$w['source']->id]['lateFeeChargeIds']);
        $this->assertSame($w['source']->id, $lines[$lateFee->charge_id]['lateFeeSourceChargeId']);
        $this->assertSame('300.00', $lines[$lateFee->charge_id]['outstanding'], 'A late fee is an ordinary statement line.');
        $this->assertSame('5300.00', $statement->totals['outstanding']);

        $this->post("/app/finance/late-fee-assessments/{$lateFee->id}/void")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->lateFees($w)->sole()->voided_at);
    }

    #[Test]
    public function another_schools_run_page_is_a_404(): void
    {
        $a = $this->lateFeeWorld();
        $b = $this->lateFeeWorld();
        $foreign = $this->lateRuns()->create($b['school'], $this->activeRule($b)->id, '2026-06-11', $b['actor']);
        $this->memberWith(['finance.charges.view', 'finance.fee_assessments.run'], $a['school']);

        $this->get("/app/finance/late-fee-runs/{$foreign->id}")->assertNotFound();
        $this->post("/app/finance/late-fee-runs/{$foreign->id}/preview")->assertNotFound();
    }
}
