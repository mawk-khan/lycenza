<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Models\User;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * UAT-C6 (UAT-FIN-20, ADR 0075 section 19): the fee-run page and API for a
 * "decision required" Student -- the existing charge(s) are shown next to
 * the charge the run would add, Include anyway / Exclude from this run go
 * through `decide` (finance.fee_assessments.run), execution is refused with
 * a message while a decision is open, and nothing leaks across Schools.
 */
class FeeRunOverlapWebTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    /** @return array{0: array<string, mixed>, 1: string, 2: string} world, student id, existing charge id */
    private function reviewWorld(): array
    {
        $w = $this->assessmentWorld();
        $studentId = $this->enroll($w)->student_id;
        $result = app(ChargeAdministrationService::class)->assess($w['school'], new AssessChargeData(
            studentId: $studentId,
            academicYearId: $w['year']->id,
            description: 'Term 1 tuition fee 2026-27',
            amount: Money::of('25000.00', 'INR'),
            receivableLedgerAccountId: $w['receivable']->id,
            revenueLedgerAccountId: $w['revenue']->id,
            dueDate: '2026-07-30',
        ), $w['actor']);

        return [$w, $studentId, $result->chargeId];
    }

    private function signIn(array $w, User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$w['school']->id}/activate");
    }

    #[Test]
    public function the_page_shows_the_existing_charge_beside_the_proposed_one_and_takes_a_decision(): void
    {
        [$w, $studentId] = $this->reviewWorld();
        $run = $this->previewedRun($w);
        $this->signIn($w, $w['actor']);

        $page = $this->get("/app/finance/fee-runs/{$run->id}?preview_result=review")->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $page['run']['preview']['decisionRequiredCount']);
        $row = $page['items']['data'][0];
        $this->assertSame(['review', 'potential_overlap', null], [$row['previewResult'], $row['reason'], $row['overlapDecision']]);
        $this->assertSame([[
            'description' => 'Term 1 tuition fee 2026-27', 'amount' => '25000.00', 'currency' => 'INR', 'dueDate' => '2026-07-30', 'cancelled' => false, 'outstanding' => '25000.00',
        ]], $row['overlapCharges']);
        $this->assertSame(['Tuition · Term 1', '2026-06-10'], [$row['proposedDescription'], $row['proposedDueDate']]);

        // Execute is refused with a message (never a 500, nothing charged).
        $this->post("/app/finance/fee-runs/{$run->id}/execute")->assertRedirect("/app/finance/fee-runs/{$run->id}")
            ->assertSessionHasErrors(['action' => '1 Student already has a charge that may cover this period. Choose "Include anyway" or "Exclude from this run" for them, preview again, then execute.']);

        $this->post("/app/finance/fee-runs/{$run->id}/items/{$row['id']}/decide", ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->post("/app/finance/fee-runs/{$run->id}/items/{$row['id']}/decide", ['decision' => 'exclude'])->assertSessionHasNoErrors();
        $this->post("/app/finance/fee-runs/{$run->id}/preview")->assertSessionHasNoErrors();
        $this->post("/app/finance/fee-runs/{$run->id}/execute")->assertSessionHasNoErrors();

        $this->assertSame(1, $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $studentId)->count()), 'excluded: only the existing charge');
    }

    #[Test]
    public function deciding_needs_the_run_capability_and_another_schools_run_is_not_found(): void
    {
        [$w] = $this->reviewWorld();
        $run = $this->previewedRun($w);
        $item = $this->items($w, $run)->sole();

        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.charges.view']);
        $this->signIn($w, $viewer);
        $this->post("/app/finance/fee-runs/{$run->id}/items/{$item->id}/decide", ['decision' => 'include'])->assertForbidden();
        $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $this->inSchool($w['school'], fn () => $item->fresh())->preview_result);

        // An item of another run of the same School is never decided through this run.
        $otherRun = $this->runs()->create($w['school'], $w['structure']->id, 'T2', $w['actor']);
        $this->signIn($w, $w['actor']);
        $this->post("/app/finance/fee-runs/{$otherRun->id}/items/{$item->id}/decide", ['decision' => 'include'])->assertNotFound();
        $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $this->inSchool($w['school'], fn () => $item->fresh())->preview_result);

        $other = $this->assessmentWorld();
        $this->signIn($other, $other['actor']);
        $this->post("/app/finance/fee-runs/{$run->id}/items/{$item->id}/decide", ['decision' => 'include'])->assertNotFound();
        $this->get("/app/finance/fee-runs/{$run->id}")->assertNotFound();
        $this->assertSame(FeeAssessmentRunItem::RESULT_REVIEW, $this->inSchool($w['school'], fn () => $item->fresh())->preview_result);
    }

    #[Test]
    public function the_api_shows_the_review_and_takes_the_same_decision(): void
    {
        [$w, , $chargeId] = $this->reviewWorld();
        $run = $this->previewedRun($w);
        $base = "/api/v1/schools/{$w['school']->id}/fee-assessment-runs/{$run->id}";
        $api = fn () => $this->actingAs($w['actor'])->withHeader('X-School-Id', $w['school']->id);

        $api()->getJson($base)->assertOk()->assertJsonPath('data.preview.decisionRequiredCount', 1);
        $item = $api()->getJson("{$base}/items?preview_result=review")->assertOk()
            ->assertJsonPath('data.0.previewResult', 'review')
            ->assertJsonPath('data.0.overlapChargeIds', [$chargeId])
            ->assertJsonPath('data.0.overlapDecision', null)
            ->json('data.0.id');

        $api()->postJson("{$base}/execute")->assertStatus(409)->assertJsonPath('error.code', 'FEE_ASSESSMENT_RUN_OVERLAP_UNRESOLVED');
        $api()->postJson("{$base}/items/{$item}/decide", ['decision' => 'other'])->assertUnprocessable();

        $runner = $this->createUserWithCapabilities($w['school'], ['finance.charges.view']);
        $this->actingAs($runner)->withHeader('X-School-Id', $w['school']->id)->postJson("{$base}/items/{$item}/decide", ['decision' => 'include'])->assertForbidden();

        $api()->postJson("{$base}/items/{$item}/decide", ['decision' => 'include'])->assertOk()->assertJsonPath('data.status', 'draft');
        $api()->postJson("{$base}/items/{$item}/decide", ['decision' => 'include'])->assertStatus(409)->assertJsonPath('error.code', 'FEE_ASSESSMENT_RUN_ITEM_NOT_DECIDABLE');
    }
}
