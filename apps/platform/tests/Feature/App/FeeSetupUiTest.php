<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1: the Finance -> Fee setup and Ledger accounts browser pages
 * (App\Http\Controllers\App\Finance\FeeSetupController /
 * LedgerAccountController). Domain invariants are proven by the service,
 * API and RLS suites; this covers page rendering, capability-aware props,
 * redirects, form and `action` errors, and that a hidden button is never
 * the only protection.
 */
class FeeSetupUiTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private function memberWith(array $capabilities, School $school): User
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'fee-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role->key);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function the_finance_index_offers_fee_setup_only_to_viewers(): void
    {
        $w = $this->feeWorld();

        $this->memberWith(['finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance')->assertInertia(fn ($page) => $page->where('can.viewFeeSetup', true));

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance')->assertInertia(fn ($page) => $page->where('can.viewFeeSetup', false));
    }

    #[Test]
    public function fee_setup_needs_view_and_only_managers_get_account_pickers(): void
    {
        $w = $this->feeWorld();
        $this->makeFeeHead($w);

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance/fee-setup')->assertForbidden();

        $this->memberWith(['finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance/fee-setup')->assertInertia(fn ($page) => $page
            ->component('App/Finance/FeeSetup/Index')
            ->has('feeHeads', 1)
            ->where('canManage', false)
            ->where('receivableAccounts', [])
        );

        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);
        $this->get('/app/finance/fee-setup')->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->has('receivableAccounts', 1)
            ->has('revenueAccounts', 1)
        );
    }

    #[Test]
    public function a_view_only_member_cannot_post_any_fee_setup_write(): void
    {
        $w = $this->feeWorld();
        $head = $this->makeFeeHead($w);
        $structure = $this->makeCompleteDraft($w);
        $this->memberWith(['finance.fee_structures.view'], $w['school']);

        foreach ([
            '/app/finance/fee-setup/fee-heads',
            "/app/finance/fee-setup/fee-heads/{$head->id}",
            '/app/finance/fee-setup/structures',
            "/app/finance/fee-setup/structures/{$structure->id}/activate",
            "/app/finance/fee-setup/structures/{$structure->id}/lines",
            '/app/finance/fee-setup/selections',
            '/app/finance/ledger-accounts',
        ] as $url) {
            $this->post($url, [])->assertForbidden();
        }
        $this->get('/app/finance/fee-setup/students/search?q=ab')->assertForbidden();
    }

    #[Test]
    public function the_ledger_accounts_page_lets_a_manager_create_and_deactivate(): void
    {
        $w = $this->feeWorld();
        $this->memberWith(['finance.ledger.view'], $w['school']);
        $this->get('/app/finance/ledger-accounts')->assertInertia(fn ($page) => $page->where('canManage', false));

        $this->memberWith(['finance.ledger.view', 'finance.accounts.manage'], $w['school']);
        $this->get('/app/finance/ledger-accounts')->assertInertia(fn ($page) => $page->where('canManage', true)->has('types', 5));

        $this->post('/app/finance/ledger-accounts', ['code' => 'cash', 'name' => 'Cash in hand', 'type' => 'asset'])
            ->assertRedirect('/app/finance/ledger-accounts');
        $cash = $this->inSchool($w['school'], fn () => LedgerAccount::query()->where('code', 'CASH')->sole());

        $this->post('/app/finance/ledger-accounts', ['code' => 'Cash', 'name' => 'Again', 'type' => 'asset'])
            ->assertSessionHasErrors('code');

        $this->post("/app/finance/ledger-accounts/{$cash->id}/status", ['status' => 'inactive'])
            ->assertRedirect('/app/finance/ledger-accounts');
        $this->assertSame('inactive', $this->inSchool($w['school'], fn () => $cash->refresh()->status));
    }

    #[Test]
    public function a_manager_builds_activates_and_amends_a_structure_through_the_pages(): void
    {
        $w = $this->feeWorld();
        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);

        $this->post('/app/finance/fee-setup/fee-heads', [
            'code' => 'tuition', 'name' => 'Tuition',
            'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id,
        ])->assertRedirect('/app/finance/fee-setup');
        $head = $this->inSchool($w['school'], fn () => FeeHead::query()->where('code', 'TUITION')->sole());

        $this->post('/app/finance/fee-setup/fee-heads', [
            'code' => 'TUITION', 'name' => 'Dup',
            'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id,
        ])->assertSessionHasErrors('code');

        $response = $this->post('/app/finance/fee-setup/structures', [
            'academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id, 'campus_id' => null, 'code' => 'g1', 'name' => 'Grade 1',
        ]);
        $structure = $this->inSchool($w['school'], fn () => FeeStructure::query()->where('code', 'G1')->sole());
        $response->assertRedirect("/app/finance/fee-setup/structures/{$structure->id}");

        $base = "/app/finance/fee-setup/structures/{$structure->id}";
        $this->post("{$base}/activate")->assertRedirect($base)->assertSessionHasErrors('action');

        $this->post("{$base}/lines", ['fee_head_id' => $head->id, 'amount' => '1200.00'])->assertRedirect($base);
        $line = $this->inSchool($w['school'], fn () => $structure->lines()->sole());
        $this->post("{$base}/lines/{$line->id}/generate", ['frequency' => 'monthly'])->assertRedirect($base);

        $this->get($base)->assertInertia(fn ($page) => $page
            ->component('App/Finance/FeeSetup/Structure')
            ->where('structure.status', 'draft')
            ->has('structure.lines.0.installments', 12)
            ->where('structure.lines.0.amount', '1200.00')
            ->where('canManage', true)
        );

        $this->post("{$base}/activate")->assertRedirect($base)->assertSessionHasNoErrors();
        $this->post("{$base}/lines/{$line->id}", ['amount' => '1.00'])->assertSessionHasErrors('action');

        $this->post("{$base}/successor", ['code' => 'G1-V2'])->assertRedirect();
        $successor = $this->inSchool($w['school'], fn () => FeeStructure::query()->where('code', 'G1-V2')->sole());
        $this->assertSame($structure->id, $successor->supersedes_fee_structure_id);
    }

    #[Test]
    public function optional_selections_are_managed_on_the_structure_page(): void
    {
        $w = $this->feeWorld();
        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);
        $structure = $this->makeDraftStructure($w);
        $bus = $this->makeFeeHead($w, ['code' => 'BUS', 'name' => 'Bus']);
        $line = app(FeeStructureService::class)->addLine($w['school'], $structure->id, ['fee_head_id' => $bus->id, 'amount' => '500.00', 'is_optional' => true], $w['actor']);
        $student = $this->createStudent($w['school'], ['first_name' => 'Asha', 'student_number' => 'S-77']);

        $this->getJson('/app/finance/fee-setup/students/search?q=Asha')->assertOk()->assertJsonPath('data.0.id', $student->id);

        $this->from("/app/finance/fee-setup/structures/{$structure->id}")
            ->post('/app/finance/fee-setup/selections', ['student_id' => $student->id, 'fee_structure_line_id' => $line->id])
            ->assertRedirect("/app/finance/fee-setup/structures/{$structure->id}");

        $this->get("/app/finance/fee-setup/structures/{$structure->id}")->assertInertia(fn ($page) => $page
            ->has('selections', 1)
            ->where('selections.0.studentNumber', 'S-77')
        );

        $selection = $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->sole());
        $this->post("/app/finance/fee-setup/selections/{$selection->id}/withdraw")->assertRedirect();
        $this->assertSame('withdrawn', $this->inSchool($w['school'], fn () => $selection->refresh()->status));
    }

    #[Test]
    public function another_schools_structure_page_is_a_404(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $foreign = $this->makeCompleteDraft($b);
        $this->memberWith(['finance.fee_structures.view', 'finance.fee_structures.manage'], $a['school']);

        $this->get("/app/finance/fee-setup/structures/{$foreign->id}")->assertNotFound();
        $this->post("/app/finance/fee-setup/structures/{$foreign->id}/activate")->assertNotFound();
    }
}
