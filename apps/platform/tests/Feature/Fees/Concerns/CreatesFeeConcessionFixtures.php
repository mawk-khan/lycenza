<?php

namespace Tests\Feature\Fees\Concerns;

use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Fees\Application\RequestFeeConcessionData;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;

/**
 * FEE.3 fixtures on top of the FEE.2 world: an active `expense` concession
 * account configured in fee settings (F2), a maker (view + request), a
 * checker (view + approve), one Student with a 1000.00 charge, and an asset
 * settlement account plus a payment recorder for G1 interaction tests.
 */
trait CreatesFeeConcessionFixtures
{
    use CreatesFeeAssessmentFixtures, CreatesFeesFixtures, CreatesPaymentsFixtures;

    protected function concessionWorld(bool $configureAccount = true): array
    {
        $w = $this->assessmentWorld();
        $w['expense'] = $this->createLedgerAccount($w['school'], ['code' => 'EXP-CONC', 'type' => 'expense']);
        if ($configureAccount) {
            app(FeeSettingsService::class)->setConcessionAccount($w['school'], $w['expense']->id, $w['actor']);
        }
        $w['maker'] = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.request']);
        $w['checker'] = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.approve']);
        $w['student'] = $this->createStudent($w['school']);
        $w['charge'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '1000.00');
        $w['settlement'] = $this->createLedgerAccount($w['school'], ['code' => 'CASH', 'type' => 'asset']);
        $w['recorder'] = $this->createPaymentRecorder($w['school']);

        return $w;
    }

    protected function concessions(): FeeConcessionService
    {
        return app(FeeConcessionService::class);
    }

    protected function requestTargeted(array $w, string $amount = '200.00', string $category = 'concession', ?Charge $charge = null, ?User $actor = null, ?string $key = null): FeeConcession
    {
        return $this->concessions()->request($w['school'], new RequestFeeConcessionData(
            idempotencyKey: $key ?? (string) Str::uuid(),
            scope: FeeConcession::SCOPE_TARGETED,
            category: $category,
            kind: FeeConcession::KIND_FIXED,
            fixedAmount: $amount,
            chargeId: ($charge ?? $w['charge'])->id,
        ), $actor ?? $w['maker'])['concession'];
    }

    protected function requestStanding(array $w, string $studentId, string $kind, string $value, ?string $feeHeadId = null, string $from = '2026-06-01', string $to = '2027-05-31', string $category = 'scholarship'): FeeConcession
    {
        return $this->concessions()->request($w['school'], new RequestFeeConcessionData(
            idempotencyKey: (string) Str::uuid(),
            scope: FeeConcession::SCOPE_STANDING,
            category: $category,
            kind: $kind,
            fixedAmount: $kind === FeeConcession::KIND_FIXED ? $value : null,
            percentage: $kind === FeeConcession::KIND_PERCENTAGE ? $value : null,
            studentId: $studentId,
            academicYearId: $w['year']->id,
            feeHeadId: $feeHeadId,
            validFrom: $from,
            validTo: $to,
        ), $w['maker'])['concession'];
    }

    protected function approvedStanding(array $w, string $studentId, string $kind, string $value, ?string $feeHeadId = null, string $from = '2026-06-01', string $to = '2027-05-31'): FeeConcession
    {
        $concession = $this->requestStanding($w, $studentId, $kind, $value, $feeHeadId, $from, $to);

        return $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
    }

    /** @return Collection<int, FeeAdjustment> */
    protected function adjustmentsOf(array $w, ?string $chargeId = null): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeAdjustment::query()
            ->when($chargeId !== null, fn ($q) => $q->where('charge_id', $chargeId))
            ->orderBy('created_at')->orderBy('id')->get());
    }

    /** @return array<string, string> ledger account id => "D:amount" / "C:amount" for one journal entry */
    protected function journalLines(array $w, string $journalEntryId): array
    {
        return $this->inSchool($w['school'], fn () => JournalLine::query()->where('journal_entry_id', $journalEntryId)->get()
            ->mapWithKeys(fn (JournalLine $l) => [$l->ledger_account_id => $l->debit_amount !== null ? "D:{$l->debit_amount}" : "C:{$l->credit_amount}"])->all());
    }
}
