<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\DuplicateFeeHeadCodeException;
use App\Domain\Fees\Application\Exceptions\FeeHeadNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeHeadException;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Models\SchoolAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §5): fee heads map to an active asset receivable and a
 * different active income revenue account; codes are unique
 * case-insensitively; mapping changes are audited with before/after ids;
 * heads are deactivated, never deleted.
 */
class FeeHeadServiceTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private function service(): FeeHeadService
    {
        return app(FeeHeadService::class);
    }

    #[Test]
    public function a_fee_head_is_created_active_with_a_normalized_code_and_audited(): void
    {
        $w = $this->feeWorld();

        $head = $this->makeFeeHead($w, ['code' => ' tuition ']);

        $this->assertSame('TUITION', $head->code);
        $this->assertSame(FeeHead::STATUS_ACTIVE, $head->status);
        $this->assertSame('INR', $head->currency);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_head.created'));
    }

    #[Test]
    public function the_receivable_must_be_an_active_asset_and_the_revenue_an_active_income_account(): void
    {
        $w = $this->feeWorld();
        $expense = $this->createLedgerAccount($w['school'], ['type' => 'expense']);
        $inactiveAsset = $this->createLedgerAccount($w['school'], ['type' => 'asset', 'status' => 'inactive']);
        $foreignAsset = $this->createLedgerAccount($this->createSchool(), ['type' => 'asset']);

        $cases = [
            'receivable is income' => [$w['revenue']->id, $w['revenue']->id, 'revenue_ledger_account_id'],
            'receivable is expense' => [$expense->id, $w['revenue']->id, 'receivable_ledger_account_id'],
            'receivable inactive' => [$inactiveAsset->id, $w['revenue']->id, 'receivable_ledger_account_id'],
            'receivable other School' => [$foreignAsset->id, $w['revenue']->id, 'receivable_ledger_account_id'],
            'revenue is asset' => [$w['receivable']->id, $inactiveAsset->id, 'revenue_ledger_account_id'],
        ];

        foreach ($cases as $label => [$receivable, $revenue, $field]) {
            try {
                $this->makeFeeHead($w, ['code' => 'H'.substr(md5($label), 0, 6), 'receivable_ledger_account_id' => $receivable, 'revenue_ledger_account_id' => $revenue]);
                $this->fail("Expected rejection: {$label}");
            } catch (InvalidFeeHeadException $e) {
                $this->assertSame($field, $e->field(), $label);
            }
        }

        $this->assertSame(0, $this->inSchool($w['school'], fn () => FeeHead::query()->count()));
    }

    #[Test]
    public function receivable_and_revenue_must_differ(): void
    {
        $w = $this->feeWorld();

        $this->expectException(InvalidFeeHeadException::class);
        $this->makeFeeHead($w, ['revenue_ledger_account_id' => $w['receivable']->id]);
    }

    #[Test]
    public function a_case_variant_duplicate_code_is_a_typed_conflict(): void
    {
        $w = $this->feeWorld();
        $this->makeFeeHead($w, ['code' => 'LAB']);

        $this->expectException(DuplicateFeeHeadCodeException::class);
        $this->makeFeeHead($w, ['code' => 'lab']);
    }

    #[Test]
    public function the_same_code_in_two_schools_does_not_collide(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();

        $this->makeFeeHead($a, ['code' => 'TUITION']);
        $this->assertSame('TUITION', $this->makeFeeHead($b, ['code' => 'TUITION'])->code);
    }

    #[Test]
    public function a_mapping_change_is_audited_with_before_and_after_account_ids(): void
    {
        $w = $this->feeWorld();
        $head = $this->makeFeeHead($w);
        $newRevenue = $this->createLedgerAccount($w['school'], ['type' => 'income']);

        $this->service()->update($w['school'], $head->id, ['revenue_ledger_account_id' => $newRevenue->id, 'name' => 'Tuition fee'], $w['actor']);

        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'fee_head.updated')->sole());
        $this->assertSame(['name', 'ledgerAccounts'], $event->metadata['changedFields']);
        $this->assertSame($w['revenue']->id, $event->metadata['before']['revenueLedgerAccountId']);
        $this->assertSame($newRevenue->id, $event->metadata['after']['revenueLedgerAccountId']);
    }

    #[Test]
    public function an_unchanged_update_writes_no_audit(): void
    {
        $w = $this->feeWorld();
        $head = $this->makeFeeHead($w);

        $this->service()->update($w['school'], $head->id, ['name' => 'Tuition'], $w['actor']);

        $this->assertSame(0, $this->auditCount($w['school'], 'fee_head.updated'));
    }

    #[Test]
    public function deactivation_and_reactivation_revalidate_and_audit_once(): void
    {
        $w = $this->feeWorld();
        $head = $this->makeFeeHead($w);

        $this->assertSame('inactive', $this->service()->deactivate($w['school'], $head->id, $w['actor'])->status);
        $this->service()->deactivate($w['school'], $head->id, $w['actor']);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_head.deactivated'));

        // A reactivation re-validates the mapping: an account deactivated in
        // the meantime blocks it.
        $this->inSchool($w['school'], fn () => $w['receivable']->forceFill(['status' => 'inactive'])->save());
        try {
            $this->service()->reactivate($w['school'], $head->id, $w['actor']);
            $this->fail('Reactivation must re-validate the account mapping.');
        } catch (InvalidFeeHeadException) {
            $this->addToAssertionCount(1);
        }

        $this->inSchool($w['school'], fn () => $w['receivable']->forceFill(['status' => 'active'])->save());
        $this->assertSame('active', $this->service()->reactivate($w['school'], $head->id, $w['actor'])->status);
    }

    #[Test]
    public function every_write_requires_fee_structures_manage(): void
    {
        $w = $this->feeWorld();
        $head = $this->makeFeeHead($w);
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']);

        $calls = [
            fn () => $this->service()->create($w['school'], ['code' => 'X', 'name' => 'X', 'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id], $viewer),
            fn () => $this->service()->update($w['school'], $head->id, ['name' => 'Y'], $viewer),
            fn () => $this->service()->deactivate($w['school'], $head->id, $viewer),
            fn () => $this->service()->reactivate($w['school'], $head->id, $viewer),
        ];

        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Call {$i} must be denied.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function another_schools_fee_head_is_not_found(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $foreign = $this->makeFeeHead($b);

        $this->expectException(FeeHeadNotFoundException::class);
        $this->service()->deactivate($a['school'], $foreign->id, $a['actor']);
    }
}
