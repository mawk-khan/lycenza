<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4: the /api/v1 receipt, statement and receipt-numbering surface --
 * allow AND deny (route middleware plus the Application service), both
 * capabilities for the statement, Principal denied by default, cross-School
 * and legacy 404s, the receipt shape (no tax field), and the numbering
 * lock as a 409.
 */
class PaymentReceiptApiTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function as(array $w, User $actor): static
    {
        return $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    #[Test]
    public function the_receipt_needs_payments_view_and_is_a_payment_acknowledgement(): void
    {
        $w = $this->concessionWorld();
        $payment = $this->pay($w, '100.00', '2026-04-01')->paymentId;
        $url = $this->base($w)."/payments/{$payment}/receipt";

        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.view']))->getJson($url)->assertForbidden();

        $response = $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.payments.view']))->getJson($url)->assertOk();
        $response->assertJsonPath('data.title', 'Payment receipt')
            ->assertJsonPath('data.receiptNumber', 'RCPT/2026-27/000001')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.studentIds.0', $w['student']->id)
            ->assertJsonPath('data.lines.0.chargeId', $w['charge']->id)
            ->assertJsonMissingPath('data.schoolId');
        $this->assertDoesNotMatchRegularExpression('/gst|hsn|taxable|tax invoice/i', $response->getContent());
        $this->assertSame(1, $this->auditCount($w['school'], 'payment_receipt.viewed'));

        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.payments.view']))
            ->getJson($this->base($w)."/payments/{$payment}")->assertOk()->assertJsonPath('data.receiptNumber', 'RCPT/2026-27/000001');
    }

    #[Test]
    public function a_legacy_or_foreign_payment_has_no_receipt_here(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $legacy = $this->legacyPayment($a, '10.00', '2026-04-02')->paymentId;
        $foreign = $this->pay($b, '10.00', '2026-04-02')->paymentId;
        $viewer = $this->createUserWithCapabilities($a['school'], ['finance.payments.view']);

        foreach ([$legacy, $foreign, 'not-a-uuid'] as $id) {
            $this->as($a, $viewer)->getJson($this->base($a)."/payments/{$id}/receipt")->assertNotFound()->assertJsonPath('error.code', 'PAYMENT_RECEIPT_NOT_FOUND');
        }
    }

    #[Test]
    public function the_statement_needs_both_capabilities(): void
    {
        $w = $this->concessionWorld();
        $this->pay($w, '100.00', '2026-04-01');
        $url = $this->base($w)."/students/{$w['student']->id}/fee-statement";

        foreach ([['finance.charges.view'], ['finance.payments.view']] as $caps) {
            $this->as($w, $this->createUserWithCapabilities($w['school'], $caps))->getJson($url)->assertForbidden();
        }
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');
        $this->as($w, $principal)->getJson($url)->assertForbidden();
        $this->assertSame(0, $this->auditCount($w['school'], 'fee_statement.viewed'));

        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.totals.outstanding', '900.00')
            ->assertJsonPath('data.lines.0.payments.0.receiptNumber', 'RCPT/2026-27/000001');
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_statement.viewed'));

        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $w['school']), 'school_admin');
        $this->as($w, $admin)->getJson($url)->assertOk();
    }

    #[Test]
    public function receipt_numbering_settings_are_fee_setup_authority_and_lock_as_409(): void
    {
        $w = $this->concessionWorld();
        $url = $this->base($w).'/fee-settings/receipt-numbering';

        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']))->putJson($url, ['receipt_prefix' => 'SCH', 'financial_year_start_month' => 4])->assertForbidden();
        $manager = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.manage']);
        $this->as($w, $manager)->putJson($url, ['receipt_prefix' => 'sch', 'financial_year_start_month' => 4])->assertOk()->assertJsonPath('data.receiptPrefix', 'SCH');
        $this->as($w, $manager)->putJson($url, ['receipt_prefix' => 'A/B', 'financial_year_start_month' => 4])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['receipt_prefix']]]);
        $this->as($w, $manager)->putJson($url, ['receipt_prefix' => 'SCH', 'financial_year_start_month' => 13])->assertStatus(422);

        $this->pay($w, '10.00', $this->today($w));
        $this->as($w, $manager)->putJson($url, ['receipt_prefix' => 'OTHER', 'financial_year_start_month' => 4])->assertStatus(409)->assertJsonPath('error.code', 'RECEIPT_NUMBERING_LOCKED');

        $this->as($w, $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']))->getJson($this->base($w).'/fee-settings')
            ->assertOk()->assertJsonPath('data.receiptPrefix', 'SCH')->assertJsonPath('data.financialYearStartMonth', 4);
    }
}
