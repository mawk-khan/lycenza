<?php

namespace Tests\Feature\App;

use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Fees\Infrastructure\Charge;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C4 (UAT-FIN-11, UAT-FIN-20, UAT-ADM-15/17): the ad-hoc charge form
 * offers only the accounts a fee charge may use (the fee-head rule), the
 * current year first and preselected, and warns -- never refuses -- when the
 * Student already has a similar charge that year.
 */
class ChargeAssessmentFormTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $school = $this->createSchool();
        $w = [
            'school' => $school,
            'closed' => $this->createAcademicYear($school, ['name' => '2025-26', 'code' => 'AY25', 'status' => 'closed', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']),
            'active' => $this->createAcademicYear($school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']),
            'draft' => $this->createAcademicYear($school, ['name' => '2027-28', 'code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']),
            'receivable' => $this->createLedgerAccount($school, ['type' => 'asset', 'code' => '1100', 'name' => 'Fees Receivable', 'status' => 'active']),
            'revenue' => $this->createLedgerAccount($school, ['type' => 'income', 'code' => '4000', 'name' => 'Tuition Income', 'status' => 'active']),
            'payable' => $this->createLedgerAccount($school, ['type' => 'liability', 'code' => '2100', 'name' => 'Salaries Payable', 'status' => 'active']),
            'student' => $this->createStudent($school),
        ];
        $w['user'] = $this->enter($school, ['finance.charges.view', 'finance.charges.manage']);

        return $w;
    }

    private function within(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function enter(School $school, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function assessPayload(array $w, array $overrides = []): array
    {
        return [
            'student_id' => $w['student']->id,
            'academic_year_id' => $w['active']->id,
            'description' => 'Term 2 Tuition Fee',
            'amount' => '12500.00',
            'currency' => 'INR',
            'receivable_ledger_account_id' => $w['receivable']->id,
            'revenue_ledger_account_id' => $w['revenue']->id,
            'due_date' => '2026-11-30',
            ...$overrides,
        ];
    }

    private function chargeCount(array $w): int
    {
        return $this->within($w['school'], fn () => Charge::query()->where('student_id', $w['student']->id)->count());
    }

    #[Test]
    public function the_form_offers_only_valid_accounts_and_preselects_the_current_year(): void
    {
        $w = $this->world();
        $other = $this->createSchool();
        $this->createLedgerAccount($other, ['type' => 'asset', 'code' => '1999', 'status' => 'active']);

        $props = $this->get('/app/finance/charges/create')->assertOk()->viewData('page')['props'];

        $this->assertSame([$w['receivable']->id], array_column($props['receivableAccounts'], 'id'), 'Asset accounts only, this School only.');
        $this->assertSame([$w['revenue']->id], array_column($props['revenueAccounts'], 'id'), 'Income accounts only -- never Salaries Payable.');
        $this->assertSame($w['receivable']->id, $props['defaultReceivableAccountId'], 'The only choice is preselected.');
        $this->assertSame($w['revenue']->id, $props['defaultRevenueAccountId']);
        $this->assertSame($w['active']->id, $props['defaultAcademicYearId']);
        $this->assertSame(['AY26', 'AY27', 'AY25'], array_column($props['academicYears'], 'code'), 'Current, then upcoming, then closed.');
        $this->assertSame(['2026-27 — current year', '2027-28 — upcoming (draft)', '2025-26 — closed'], array_column($props['academicYears'], 'label'));

        // A second valid account: nothing is guessed.
        $this->createLedgerAccount($w['school'], ['type' => 'asset', 'code' => '1010', 'status' => 'active']);
        $this->assertNull($this->get('/app/finance/charges/create')->viewData('page')['props']['defaultReceivableAccountId']);
    }

    #[Test]
    public function a_charge_on_an_account_of_the_wrong_type_or_school_is_refused_whatever_the_form_sent(): void
    {
        $w = $this->world();
        $foreign = $this->createLedgerAccount($this->createSchool(), ['type' => 'income', 'status' => 'active']);

        $this->post('/app/finance/charges', $this->assessPayload($w, ['revenue_ledger_account_id' => $w['payable']->id]))
            ->assertSessionHasErrors(['revenue_ledger_account_id' => 'The revenue account must be an active income account of this School.']);
        $this->post('/app/finance/charges', $this->assessPayload($w, ['receivable_ledger_account_id' => $w['revenue']->id]))
            ->assertSessionHasErrors('receivable_ledger_account_id');
        $this->post('/app/finance/charges', $this->assessPayload($w, ['revenue_ledger_account_id' => $foreign->id]))
            ->assertSessionHasErrors('revenue_ledger_account_id');
        $this->assertSame(0, $this->chargeCount($w));
    }

    #[Test]
    public function a_similar_charge_warns_and_the_user_may_assess_anyway(): void
    {
        $w = $this->world();
        $this->assessCharge($w['school'], $w['student'], $w['active'], $w['receivable'], $w['revenue'], '24000.00', description: 'Term 2 tuition fee 2026-27');

        // UAT-FIN-20: the same term billed again under a slightly different name.
        $this->from('/app/finance/charges/create')->post('/app/finance/charges', $this->assessPayload($w))
            ->assertRedirect('/app/finance/charges/create')
            ->assertSessionHasErrors('confirm_similar');
        $this->assertSame(1, $this->chargeCount($w), 'A warning writes nothing.');
        $similar = $this->get('/app/finance/charges/create')->viewData('page')['props']['similarCharges'];
        $this->assertSame(['Term 2 tuition fee 2026-27'], array_column($similar, 'description'));
        $this->assertSame([], $this->get('/app/finance/charges/create')->viewData('page')['props']['similarCharges'], 'Shown once.');

        $this->post('/app/finance/charges', $this->assessPayload($w, ['confirm_similar' => true]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $this->chargeCount($w), 'Never a prohibition.');
    }

    #[Test]
    public function different_charges_and_other_students_years_or_schools_do_not_warn(): void
    {
        $w = $this->world();
        $this->assessCharge($w['school'], $w['student'], $w['active'], $w['receivable'], $w['revenue'], '1500.00', description: 'Library books');
        $this->assessCharge($w['school'], $w['student'], $w['closed'], $w['receivable'], $w['revenue'], '12500.00', description: 'Term 2 Tuition Fee');
        $this->assessCharge($w['school'], $this->createStudent($w['school']), $w['active'], $w['receivable'], $w['revenue'], '12500.00', description: 'Term 2 Tuition Fee');
        $cancelled = $this->assessCharge($w['school'], $w['student'], $w['active'], $w['receivable'], $w['revenue'], '12500.00', description: 'Term 2 Tuition Fee');
        $this->within($w['school'], fn () => $this->cancelChargeAs($w['school'], $cancelled->id));

        $this->post('/app/finance/charges', $this->assessPayload($w))->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_same_amount_on_the_same_due_date_warns(): void
    {
        $w = $this->world();
        $this->assessCharge($w['school'], $w['student'], $w['active'], $w['receivable'], $w['revenue'], '12500.00', description: 'Bus fee', dueDate: '2026-11-30');

        $this->post('/app/finance/charges', $this->assessPayload($w))->assertSessionHasErrors('confirm_similar');
    }

    #[Test]
    public function admissions_and_concession_forms_put_the_current_year_first(): void
    {
        $w = $this->world();
        $this->enter($w['school'], ['admissions.manage', 'finance.fee_concessions.view', 'finance.fee_concessions.request']);
        $applicant = $this->within($w['school'], fn () => Applicant::factory()->for($w['school'], 'school')->create());

        $admission = $this->get("/app/admissions/applicants/{$applicant->id}/applications/create")->assertOk()->viewData('page')['props'];
        $this->assertSame($w['active']->id, $admission['defaultAcademicYearId']);
        $this->assertSame(['AY26', 'AY27', 'AY25'], array_column($admission['academicYears'], 'code'));

        $concession = $this->get('/app/finance/concessions/create')->assertOk()->viewData('page')['props'];
        $this->assertSame($w['active']->id, $concession['academicYears'][0]['id'], 'The form preselects the first: the current year.');

        // A report filter is unchanged: newest first, nothing preselected.
        $this->enter($w['school'], ['finance.charges.view']);
        $this->assertSame(['2027-28', '2026-27', '2025-26'], array_column($this->get('/app/finance/charges')->viewData('page')['props']['academicYears'], 'name'));
    }
}
