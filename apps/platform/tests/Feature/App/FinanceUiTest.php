<?php

namespace Tests\Feature\App;

use App\Models\Role;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.7: the administrative Finance UI
 * (App\Http\Controllers\App\Finance\*). Backend authorization/tenant-
 * safety/domain invariants are already proven by 0G.2-0G.6's own test
 * suites (re-run unmodified alongside this file) -- these tests cover
 * the Inertia-specific integration: page rendering, capability-aware
 * props, redirect/validation/conflict behavior, exact Money-string
 * round-tripping, and that a hidden button is never the only
 * protection (mirrors Tests\Feature\App\StudentAdminUiTest's exact
 * shape).
 */
class FinanceUiTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    private function activate(User $user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function roleWith(array $capabilities): Role
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'finance-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));

        return $role;
    }

    private function memberWith(array $capabilities, $school): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        // SR.1 (ADR 0071 §6.3): an empty role is never grantable, so "no Finance
        // capability" is a plain member with no role at all.
        if ($capabilities !== []) {
            $this->assignSchoolRole($membership, $this->roleWith($capabilities)->key);
        }
        $this->activate($user, $school);

        return $user;
    }

    // --- Ledger accounts -------------------------------------------------

    #[Test]
    public function ledger_accounts_index_requires_finance_ledger_view(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([], $school);

        $this->get('/app/finance/ledger-accounts')->assertForbidden();
    }

    #[Test]
    public function ledger_accounts_index_lists_accounts_for_an_authorized_viewer(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school, ['code' => 'CASH', 'name' => 'Cash']);
        $this->memberWith(['finance.ledger.view'], $school);

        $this->get('/app/finance/ledger-accounts')->assertInertia(fn ($page) => $page
            ->component('App/Finance/Ledger/Accounts')
            ->has('accounts', 1)
            ->where('accounts.0.code', 'CASH')
        );
    }

    // --- Journal entries: list / capability-aware props -------------------

    #[Test]
    public function journal_list_shows_canpost_false_for_a_view_only_member(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['finance.ledger.view'], $school);

        $this->get('/app/finance/journal-entries')->assertInertia(fn ($page) => $page
            ->component('App/Finance/Ledger/Journals/Index')
            ->where('canPost', false)
        );

        // The button is UX-only -- the create PAGE and the store ACTION
        // must both independently deny this actor server-side, never
        // relying on a hidden button.
        $this->get('/app/finance/journal-entries/create')->assertForbidden();
        $this->post('/app/finance/journal-entries', [])->assertForbidden();
    }

    #[Test]
    public function journal_list_filters_are_echoed_back_as_props(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['finance.ledger.view'], $school);

        $this->get('/app/finance/journal-entries?search=rent')->assertInertia(fn ($page) => $page
            ->where('filters.search', 'rent')
        );
    }

    // --- Post journal entry: exact Money-string round-trip ----------------

    #[Test]
    public function finance_ledger_post_can_post_a_balanced_entry_with_exact_decimal_amounts(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->memberWith(['finance.ledger.view', 'finance.ledger.post'], $school);

        $response = $this->post('/app/finance/journal-entries', [
            'currency' => 'INR',
            'description' => 'Rent received',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '1000.00'],
                ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '1000.00'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/app/finance/journal-entries/', $response->headers->get('Location'));

        $journalEntryId = str($response->headers->get('Location'))->afterLast('/')->toString();

        $this->get("/app/finance/journal-entries/{$journalEntryId}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Ledger/Journals/Show')
            // Exact decimal STRING preserved end to end -- never "1000",
            // never "1000.0", never a JS-float-derived reformatting.
            ->where('journalEntry.lines.0.amount', '1000.00')
            ->where('journalEntry.lines.1.amount', '1000.00')
        );
    }

    #[Test]
    public function finance_ledger_post_rejects_an_unbalanced_entry_with_a_field_error(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->memberWith(['finance.ledger.post'], $school);

        $response = $this->post('/app/finance/journal-entries', [
            'currency' => 'INR',
            'description' => 'Unbalanced',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '1000.00'],
                ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '999.00'],
            ],
        ]);

        $response->assertSessionHasErrors('lines');
    }

    #[Test]
    public function finance_ledger_post_rejects_a_cross_school_ledger_account(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $foreignAccount = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $this->memberWith(['finance.ledger.post'], $school);

        $response = $this->post('/app/finance/journal-entries', [
            'currency' => 'INR',
            'description' => 'Cross-School attempt',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '10.00'],
                ['ledger_account_id' => $foreignAccount->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ]);

        $response->assertSessionHasErrors('lines');
    }

    // --- Journal entry detail / reversal -----------------------------------

    #[Test]
    public function journal_entry_detail_is_a_uniform_404_for_a_cross_school_id(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $cash = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $income = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $foreignEntry = $this->postBalancedJournalEntry($otherSchool, $cash, $income, '50.00');
        $this->memberWith(['finance.ledger.view'], $school);

        $this->get("/app/finance/journal-entries/{$foreignEntry->id}")->assertNotFound();
    }

    #[Test]
    public function reverse_requires_finance_ledger_reverse_and_hides_reverse_button_without_it(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '25.00');
        $this->memberWith(['finance.ledger.view'], $school);

        $this->get("/app/finance/journal-entries/{$entry->id}")->assertInertia(fn ($page) => $page
            ->where('canReverse', false)
        );

        $this->post("/app/finance/journal-entries/{$entry->id}/reverse")->assertForbidden();
    }

    #[Test]
    public function reversing_an_already_reversed_entry_shows_a_stable_conflict_message(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '25.00');
        $this->memberWith(['finance.ledger.view', 'finance.ledger.reverse'], $school);

        $this->post("/app/finance/journal-entries/{$entry->id}/reverse")->assertRedirect();

        $second = $this->post("/app/finance/journal-entries/{$entry->id}/reverse");
        $second->assertSessionHasErrors('reversal');
    }

    // --- Charges: list / capability-aware props ---------------------------

    #[Test]
    public function charges_index_requires_finance_charges_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/finance/charges')->assertForbidden();
    }

    #[Test]
    public function charges_list_shows_canmanage_false_for_a_view_only_member_and_hides_mutation_routes(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['finance.charges.view'], $school);

        $this->get('/app/finance/charges')->assertInertia(fn ($page) => $page
            ->component('App/Finance/Charges/Index')
            ->where('canManage', false)
        );

        $this->get('/app/finance/charges/create')->assertForbidden();
        $this->post('/app/finance/charges', [])->assertForbidden();
    }

    #[Test]
    public function charges_list_includes_a_resolved_student_display_name(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');
        $this->memberWith(['finance.charges.view'], $school);

        $this->get('/app/finance/charges')->assertInertia(fn ($page) => $page
            ->where('charges.data.0.studentName', 'Asha Verma')
            ->where('charges.data.0.amount', '500.00')
        );
    }

    // --- Assess charge: exact Money-string round-trip ----------------------

    #[Test]
    public function finance_charges_manage_can_assess_a_charge_with_an_exact_decimal_amount(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->memberWith(['finance.charges.view', 'finance.charges.manage'], $school);

        $response = $this->post('/app/finance/charges', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'description' => 'Term 1 Tuition Fee',
            'amount' => '12345.67',
            'currency' => 'INR',
            'receivable_ledger_account_id' => $receivable->id,
            'revenue_ledger_account_id' => $revenue->id,
        ]);

        $response->assertRedirect();
        $chargeId = str($response->headers->get('Location'))->afterLast('/')->toString();

        $this->get("/app/finance/charges/{$chargeId}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Charges/Show')
            ->where('charge.amount', '12345.67')
        );
    }

    #[Test]
    public function finance_charges_manage_rejects_a_cross_school_student(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->memberWith(['finance.charges.manage'], $school);

        $response = $this->post('/app/finance/charges', [
            'student_id' => $foreignStudent->id,
            'academic_year_id' => $year->id,
            'description' => 'x',
            'amount' => '10.00',
            'currency' => 'INR',
            'receivable_ledger_account_id' => $receivable->id,
            'revenue_ledger_account_id' => $revenue->id,
        ]);

        $response->assertSessionHasErrors('student_id');
    }

    // --- Student search endpoint (Charge assessment picker) ---------------

    #[Test]
    public function student_search_requires_finance_charges_manage(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['first_name' => 'Rahul']);
        $this->memberWith(['finance.charges.view'], $school);

        $this->getJson('/app/finance/charges/students/search?q=Rahul')->assertForbidden();
    }

    #[Test]
    public function student_search_is_school_scoped(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $this->createStudent($school, ['first_name' => 'Priyanka']);
        $this->createStudent($otherSchool, ['first_name' => 'Priyanka']);
        $this->memberWith(['finance.charges.manage'], $school);

        $this->getJson('/app/finance/charges/students/search?q=Priyanka')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // --- Charge detail / cancellation --------------------------------------

    #[Test]
    public function charge_detail_is_a_uniform_404_for_a_cross_school_id(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($otherSchool);
        $receivable = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $foreignCharge = $this->assessCharge($otherSchool, $student, $year, $receivable, $revenue, '10.00');
        $this->memberWith(['finance.charges.view'], $school);

        $this->get("/app/finance/charges/{$foreignCharge->id}")->assertNotFound();
    }

    #[Test]
    public function cancelling_an_already_cancelled_charge_shows_a_stable_conflict_message(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '10.00');
        $this->memberWith(['finance.charges.manage'], $school);

        $this->post("/app/finance/charges/{$charge->id}/cancel")->assertRedirect();
        $second = $this->post("/app/finance/charges/{$charge->id}/cancel");
        $second->assertSessionHasErrors('cancellation');
    }

    #[Test]
    public function a_charge_with_a_payment_allocation_cannot_be_cancelled(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '10.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '10.00']], '10.00');
        $this->memberWith(['finance.charges.manage'], $school);

        $response = $this->post("/app/finance/charges/{$charge->id}/cancel");
        $response->assertSessionHasErrors('cancellation');
    }

    // --- Payments: read-only ------------------------------------------------

    #[Test]
    public function payments_index_requires_finance_payments_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/finance/payments')->assertForbidden();
    }

    #[Test]
    public function payment_detail_shows_allocations(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '10.00');
        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '10.00']], '10.00');
        $this->memberWith(['finance.payments.view'], $school);

        $this->get("/app/finance/payments/{$result->paymentId}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Payments/Show')
            ->where('payment.amount', '10.00')
            ->where('payment.allocations.0.amount', '10.00')
        );
    }

    #[Test]
    public function payment_detail_is_a_uniform_404_for_a_cross_school_id(): void
    {
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($otherSchool);
        $receivable = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $charge = $this->assessCharge($otherSchool, $student, $year, $receivable, $revenue, '10.00');
        $foreignResult = $this->recordSettlement($otherSchool, $settlement->id, [[$charge, '10.00']], '10.00');

        $school = $this->createSchool();
        $this->memberWith(['finance.payments.view'], $school);

        $this->get("/app/finance/payments/{$foreignResult->paymentId}")->assertNotFound();
    }

    #[Test]
    public function no_payment_edit_delete_or_generic_create_route_exists(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        // No generic POST and no PATCH/DELETE route is registered for
        // /app/finance/payments -- the router returns 405 (the route
        // exists, the verb does not). There is no finance.payments.manage
        // capability. Phase 0O.11A: the one Payment write is recording an
        // offline payment (POST /app/finance/payments/record, covered by
        // Tests\Feature\App\ManualPaymentUiTest); a recorded Payment
        // still has no edit, delete or refund route.
        $this->post('/app/finance/payments', [])->assertStatus(405);
        $this->patch('/app/finance/payments/anything', [])->assertStatus(405);
        $this->delete('/app/finance/payments/anything')->assertStatus(405);
        $this->put('/app/finance/payments/record', [])->assertStatus(405);
    }

    // --- Navigation ----------------------------------------------------------

    #[Test]
    public function dashboard_nav_shows_finance_only_when_any_finance_view_capability_is_held(): void
    {
        $school = $this->createSchool();
        $viewer = $this->memberWith(['finance.payments.view'], $school);

        $this->actingAs($viewer)->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewFinance', true)
        );

        $withoutFinance = $this->memberWith([], $school);
        $this->actingAs($withoutFinance)->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewFinance', false)
        );
    }

    #[Test]
    public function finance_hub_hides_areas_the_user_cannot_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['finance.payments.view'], $school);

        $this->get('/app/finance')->assertInertia(fn ($page) => $page
            ->component('App/Finance/Index')
            ->where('can.viewPayments', true)
            ->where('can.viewLedger', false)
            ->where('can.viewCharges', false)
        );
    }
}
