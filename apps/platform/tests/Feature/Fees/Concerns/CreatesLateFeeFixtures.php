<?php

namespace Tests\Feature\Fees\Concerns;

use App\Domain\Fees\Application\LateFeeRuleService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeLateFeeRule;
use App\Domain\Payments\Application\LateFeeRunService;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

/**
 * FEE.5 fixtures on top of the FEE.4 world: a LATE fee head (receivable =
 * the fees AR account, revenue = a dedicated income account), and one
 * enrolled Student assessed for T1 (5000.00, due 2026-06-10) -- the source
 * charge. The actor holds fee setup and run capabilities.
 */
trait CreatesLateFeeFixtures
{
    use CreatesReceiptFixtures;

    protected function lateFeeWorld(): array
    {
        $w = $this->concessionWorld();
        $w['lateIncome'] = $this->createLedgerAccount($w['school'], ['code' => 'INC-LATE', 'type' => 'income']);
        $w['lateHead'] = $this->makeFeeHead($w, ['code' => 'LATE', 'name' => 'Late fee', 'revenue_ledger_account_id' => $w['lateIncome']->id]);
        $w['sourceStudent'] = $this->enroll($w)->student_id;
        $this->executedRun($w);
        $w['source'] = $this->inSchool($w['school'], fn () => Charge::query()->findOrFail(FeeAssessment::query()->sole()->charge_id));

        return $w;
    }

    protected function lateRules(): LateFeeRuleService
    {
        return app(LateFeeRuleService::class);
    }

    protected function lateRuns(): LateFeeRunService
    {
        return app(LateFeeRunService::class);
    }

    protected function activeRule(array $w, array $attributes = []): FeeLateFeeRule
    {
        $rule = $this->lateRules()->create($w['school'], array_merge([
            'name' => 'Late payment',
            'fee_structure_id' => $w['structure']->id,
            'late_fee_head_id' => $w['lateHead']->id,
            'grace_days' => 0,
            'kind' => 'fixed',
            'fixed_amount' => '100.00',
        ], $attributes), $w['actor']);

        return $this->lateRules()->activate($w['school'], $rule->id, $w['actor']);
    }

    protected function previewedLateRun(array $w, FeeLateFeeRule $rule, string $on = '2026-06-11'): LateFeeRun
    {
        $run = $this->lateRuns()->create($w['school'], $rule->id, $on, $w['actor']);

        return $this->lateRuns()->preview($w['school'], $run->id, $w['actor']);
    }

    /** Previews and executes (the sync queue runs the job inline). */
    protected function executedLateRun(array $w, FeeLateFeeRule $rule, string $on = '2026-06-11'): LateFeeRun
    {
        $run = $this->previewedLateRun($w, $rule, $on);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);

        return $this->inSchool($w['school'], fn () => $run->refresh());
    }

    /** @return Collection<int, LateFeeRunItem> */
    protected function lateItems(array $w, LateFeeRun $run): Collection
    {
        return $this->inSchool($w['school'], fn () => LateFeeRunItem::query()->where('late_fee_run_id', $run->id)->orderBy('due_date')->get());
    }

    /** @return Collection<int, LateFeeAssessment> */
    protected function lateFees(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => LateFeeAssessment::query()->orderBy('created_at')->orderBy('id')->get());
    }

    protected function chargeOf(array $w, string $id): Charge
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($id));
    }

    protected function quietQueue(): void
    {
        Queue::fake();
    }
}
