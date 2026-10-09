<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3: the Finance -> Concessions browser pages
 * (App\Http\Controllers\App\Finance\FeeConcessionController) and the
 * charge-page context. Domain rules are proven by the service, API, RLS
 * and race suites; this covers rendering, capability-aware props,
 * maker/checker through the pages, the server-issued request key, and that
 * a hidden button is never the only protection.
 */
class FeeConcessionUiTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private function memberWith(array $capabilities, School $school): User
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'fee-concession-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role->key);
        $this->signIn($user, $school);

        return $user;
    }

    private function signIn(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function the_list_needs_view_and_shows_the_pending_queue_and_settings_by_capability(): void
    {
        $w = $this->concessionWorld();
        $this->requestTargeted($w);

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance/concessions')->assertForbidden();

        $this->memberWith(['finance.fee_concessions.view'], $w['school']);
        $this->get('/app/finance/concessions')->assertInertia(fn ($page) => $page
            ->component('App/Finance/Concessions/Index')
            ->where('filters.status', 'pending')
            ->has('concessions.data', 1)
            ->where('canRequest', false)
            ->where('settings', null));

        $this->memberWith(['finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_structures.view', 'finance.fee_structures.manage'], $w['school']);
        $this->get('/app/finance/concessions?status=approved')->assertInertia(fn ($page) => $page
            ->has('concessions.data', 0)
            ->where('canRequest', true)
            ->where('settings.concessionLedgerAccountId', $w['expense']->id)
            ->where('settings.canManage', true));
    }

    #[Test]
    public function maker_requests_and_checker_approves_through_the_pages(): void
    {
        $w = $this->concessionWorld();
        $maker = $this->memberWith(['finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_concessions.approve'], $w['school']);

        $form = $this->get("/app/finance/concessions/create?charge_id={$w['charge']->id}");
        $form->assertInertia(fn ($page) => $page->component('App/Finance/Concessions/Create')->where('charge.id', $w['charge']->id)->has('idempotencyKey'));
        $key = $form->viewData('page')['props']['idempotencyKey'];

        $body = ['idempotency_key' => $key, 'scope' => 'targeted', 'category' => 'waiver', 'kind' => 'fixed', 'fixed_amount' => '120.00', 'charge_id' => $w['charge']->id];
        $this->post('/app/finance/concessions', $body)->assertRedirect();
        $this->post('/app/finance/concessions', $body)->assertRedirect();
        $concession = $this->inSchool($w['school'], fn () => FeeConcession::query()->sole());
        $this->assertSame($maker->id, $concession->requested_by_user_id, 'The double submit replayed one request.');

        $this->get("/app/finance/concessions/{$concession->id}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Concessions/Show')
            ->where('can.decide', false)
            ->where('can.isRequester', true)
            ->where('can.withdraw', true));
        $this->post("/app/finance/concessions/{$concession->id}/approve")->assertSessionHasErrors('action');

        $this->memberWith(['finance.fee_concessions.view', 'finance.fee_concessions.approve'], $w['school']);
        $this->get("/app/finance/concessions/{$concession->id}")->assertInertia(fn ($page) => $page->where('can.decide', true));
        $this->post("/app/finance/concessions/{$concession->id}/approve")->assertRedirect()->assertSessionHasNoErrors();

        $this->get("/app/finance/concessions/{$concession->id}")->assertInertia(fn ($page) => $page
            ->where('concession.status', 'approved')
            ->has('adjustments', 1)
            ->where('adjustments.0.amount', '120.00'));

        $adjustment = $this->adjustmentsOf($w)->sole();
        $this->post("/app/finance/fee-adjustments/{$adjustment->id}/cancel")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->adjustmentsOf($w)->sole()->cancelled_at);
    }

    #[Test]
    public function a_view_only_member_cannot_post_any_action(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);
        $this->memberWith(['finance.fee_concessions.view'], $w['school']);

        $this->post('/app/finance/concessions', ['idempotency_key' => (string) Str::uuid(), 'scope' => 'targeted', 'category' => 'waiver', 'kind' => 'fixed', 'fixed_amount' => '1.00', 'charge_id' => $w['charge']->id])->assertForbidden();
        foreach (['withdraw', 'approve', 'reject', 'revoke'] as $action) {
            $this->post("/app/finance/concessions/{$concession->id}/{$action}")->assertForbidden();
        }
        $this->post('/app/finance/concessions/settings', ['concession_ledger_account_id' => $w['expense']->id])->assertForbidden();
        $this->get('/app/finance/concessions/create')->assertForbidden();
        $this->assertSame('pending', $this->inSchool($w['school'], fn () => $concession->refresh()->status));
    }

    #[Test]
    public function the_charge_page_shows_adjustments_only_with_concession_view(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '75.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get("/app/finance/charges/{$w['charge']->id}")->assertInertia(fn ($page) => $page
            ->where('adjustments', null)->where('canRequestConcession', false));

        $this->memberWith(['finance.charges.view', 'finance.fee_concessions.view', 'finance.fee_concessions.request'], $w['school']);
        $this->get("/app/finance/charges/{$w['charge']->id}")->assertInertia(fn ($page) => $page
            ->has('adjustments', 1)->where('adjustments.0.amount', '75.00')->where('canRequestConcession', true));
    }

    #[Test]
    public function another_schools_concession_page_is_a_404(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $foreign = $this->requestTargeted($b);
        $this->memberWith(['finance.fee_concessions.view', 'finance.fee_concessions.approve'], $a['school']);

        $this->get("/app/finance/concessions/{$foreign->id}")->assertNotFound();
        $this->post("/app/finance/concessions/{$foreign->id}/approve")->assertNotFound();
    }

    #[Test]
    public function the_run_preview_projects_gross_concession_and_net_only_for_concession_viewers(): void
    {
        $w = $this->concessionWorld();
        $enrollment = $this->enroll($w);
        $over = $this->enroll($w);
        $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_PERCENTAGE, '10.00');
        $this->approvedStanding($w, $over->student_id, FeeConcession::KIND_FIXED, '6000.00');
        $run = $this->previewedRun($w);
        $item = $this->itemFor($w, $run, $enrollment->student_id);
        $overItem = $this->itemFor($w, $run, $over->student_id);

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get("/app/finance/fee-runs/{$run->id}")->assertInertia(fn ($page) => $page
            ->where('items.data.0.concessionPreview', null)->where('items.data.1.concessionPreview', null));

        $this->memberWith(['finance.charges.view', 'finance.fee_concessions.view'], $w['school']);
        $props = $this->get("/app/finance/fee-runs/{$run->id}")->viewData('page')['props'];
        $byId = collect($props['items']['data'])->keyBy('id');
        $this->assertSame(['concession' => '500.00', 'net' => '4500.00', 'exceeds' => false], $byId[$item->id]['concessionPreview']);
        $this->assertTrue($byId[$overItem->id]['concessionPreview']['exceeds'], 'A preview warns that this item will fail closed.');
    }
}
