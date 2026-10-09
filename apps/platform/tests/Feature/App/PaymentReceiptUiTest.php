<?php

namespace Tests\Feature\App;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4: the printable receipt, the Student fee statement pages, the
 * receipt-numbering settings on Fee setup, and the Finance hub links --
 * rendering, capability-aware props, and that a hidden link is never the
 * only protection. The receipt is titled "Payment receipt" and never
 * shows tax fields (J).
 */
class PaymentReceiptUiTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function memberWith(array $capabilities, School $school): User
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'receipt-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role->key);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function the_receipt_page_is_a_payment_acknowledgement_under_payments_view(): void
    {
        $w = $this->concessionWorld();
        $payment = $this->pay($w, '120.00', '2026-04-01')->paymentId;
        $legacy = $this->legacyPayment($w, '10.00', '2026-04-02')->paymentId;

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get("/app/finance/payments/{$payment}/receipt")->assertForbidden();

        $this->memberWith(['finance.payments.view'], $w['school']);
        $response = $this->get("/app/finance/payments/{$payment}/receipt");
        $response->assertInertia(fn ($page) => $page
            ->component('App/Finance/Payments/Receipt')
            ->where('receipt.title', 'Payment receipt')
            ->where('receipt.receiptNumber', 'RCPT/2026-27/000001')
            ->where('receipt.receivedOn', '2026-04-01')
            ->where('receipt.amount', '120.00')
            ->where('receipt.schoolName', $w['school']->name)
            ->has('receipt.students', 1)
            ->has('receipt.lines', 1));
        $this->assertDoesNotMatchRegularExpression('/gstin|hsn|taxable|"Tax invoice"/i', $response->getContent());

        $this->get("/app/finance/payments/{$payment}")->assertInertia(fn ($page) => $page->where('payment.receiptNumber', 'RCPT/2026-27/000001'));
        $this->get("/app/finance/payments/{$legacy}")->assertInertia(fn ($page) => $page->where('payment.receiptNumber', null));
        $this->get("/app/finance/payments/{$legacy}/receipt")->assertNotFound();
    }

    #[Test]
    public function the_statement_pages_need_both_capabilities(): void
    {
        $w = $this->concessionWorld();
        $this->pay($w, '100.00', '2026-04-01');

        foreach ([['finance.charges.view'], ['finance.payments.view']] as $caps) {
            $this->memberWith($caps, $w['school']);
            $this->get('/app/finance/fee-statements')->assertForbidden();
            $this->get("/app/finance/fee-statements/{$w['student']->id}")->assertForbidden();
            $this->get('/app/finance/fee-statements/students/search?q=ab')->assertForbidden();
        }

        $this->memberWith(['finance.charges.view', 'finance.payments.view'], $w['school']);
        $this->get('/app/finance')->assertInertia(fn ($page) => $page->where('can.viewStatements', true));
        $this->get('/app/finance/fee-statements')->assertInertia(fn ($page) => $page->component('App/Finance/Statements/Index'));
        $this->get("/app/finance/fee-statements/{$w['student']->id}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Statements/Show')
            ->where('statement.totals.outstanding', '900.00')
            ->where('statement.lines.0.payments.0.receiptNumber', 'RCPT/2026-27/000001')
            ->where('statement.lines.0.payments.0.receivedOn', '2026-04-01'));
        $this->get("/app/finance/fee-statements/{$w['student']->id}?academic_year_id={$w['year']->id}")->assertInertia(fn ($page) => $page->where('filters.academic_year_id', $w['year']->id));
        $this->get("/app/finance/charges/{$w['charge']->id}")->assertInertia(fn ($page) => $page->where('canViewStatement', true));

        $other = $this->concessionWorld();
        $this->get("/app/finance/fee-statements/{$other['student']->id}")->assertNotFound();
    }

    #[Test]
    public function receipt_numbering_is_shown_on_fee_setup_and_changed_only_with_manage(): void
    {
        $w = $this->concessionWorld();

        $this->memberWith(['finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance/fee-setup')->assertInertia(fn ($page) => $page->where('receiptNumbering.prefix', 'RCPT')->where('receiptNumbering.financialYearStartMonth', 4));
        $this->post('/app/finance/fee-setup/receipt-numbering', ['receipt_prefix' => 'SCH', 'financial_year_start_month' => 4])->assertForbidden();

        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);
        $this->post('/app/finance/fee-setup/receipt-numbering', ['receipt_prefix' => 'sch', 'financial_year_start_month' => 4])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/finance/fee-setup')->assertInertia(fn ($page) => $page->where('receiptNumbering.prefix', 'SCH')->where('receiptNumbering.financialYearStartMonth', 4));
        // E21.3A (ADR 0064): the start month defines the financial periods, so
        // it is frozen once anything is posted (the world assessed a charge).
        $this->post('/app/finance/fee-setup/receipt-numbering', ['receipt_prefix' => 'SCH', 'financial_year_start_month' => 7])->assertSessionHasErrors('action');

        $this->pay($w, '10.00', $this->today($w));
        $this->post('/app/finance/fee-setup/receipt-numbering', ['receipt_prefix' => 'OTHER', 'financial_year_start_month' => 4])->assertSessionHasErrors('action');
    }
}
