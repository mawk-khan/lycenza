<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\InvalidWriteOffReasonException;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * ORG.9C-2 (ADR 0076 §70): an Inventory write-off is a `write_off` movement
 * with a closed reason, DIRECT_WITH_ASSURANCE -- the existing
 * `inventory.stock.manage` in the Location's scope, re-read fresh under the
 * locks, a fresh MFA code, an explicit web confirmation, append-only
 * evidence and a replay-safe form key. No approval workflow, no free text.
 */
class InventoryWriteOffTest extends TestCase
{
    use CreatesTenancyFixtures;
    use ProvidesSensitiveActionMfa;

    private School $school;

    private Campus $a;

    private Campus $b;

    private InventoryItem $item;

    /** @var array<string, InventoryLocation> */
    private array $loc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
        $this->item = $this->createInventoryItem($this->school, ['status' => 'active', 'unit_of_measure' => 'each', 'code' => 'CHALK']);
        $this->loc['a'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'code' => 'STORE-A', 'status' => 'active']);
        $this->loc['b'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->b->id, 'code' => 'STORE-B', 'status' => 'active']);
        $this->loc['school'] = $this->createInventoryLocation($this->school, ['campus_id' => null, 'code' => 'CENTRAL', 'status' => 'active']);
        $this->inSchool(function (): void {
            foreach ($this->loc as $location) {
                app(InventoryStockService::class)->receive($this->item, $location, '10');
            }
        });
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function officer(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'stores_officer', $campusId);
        }

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(User $user, string $location = 'a', array $overrides = []): array
    {
        return [
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->loc[$location]->id,
            'quantity' => '3',
            'reason_code' => 'damaged',
            'confirm' => '1',
            'mfa_code' => $this->freshMfaCode($user),
            'idempotency_key' => $this->formKey(),
            ...$overrides,
        ];
    }

    /** The key the write-off form issued to this session (a refused page: any key, refused earlier). */
    private function formKey(): string
    {
        $page = $this->get('/app/inventory-stock/write-off');

        return $page->status() === 200 ? $page->viewData('page')['props']['idempotencyKey'] : (string) Str::uuid();
    }

    private function onHand(string $location): string
    {
        return $this->inSchool(fn () => InventoryStockBalance::query()->where('inventory_location_id', $this->loc[$location]->id)->value('quantity_on_hand'));
    }

    private function writeOffs(): int
    {
        return $this->inSchool(fn () => StockMovement::query()->where('movement_type', 'write_off')->count());
    }

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    private function service(): InventoryStockService
    {
        return app(InventoryStockService::class);
    }

    #[Test]
    public function a_write_off_records_one_reasoned_movement_and_its_provenance(): void
    {
        $officer = $this->officer([null]);
        $this->signIn($officer);
        $page = $this->get('/app/inventory-stock/write-off')->assertOk()->viewData('page')['props'];
        $this->assertSame(['damaged', 'expired', 'lost'], array_keys($page['reasons']));
        $this->assertTrue(Str::isUuid($page['idempotencyKey']));

        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['reason_code' => 'expired']))->assertRedirect('/app/inventory-stock');

        $this->assertSame('7.000', $this->onHand('a'));
        $movement = $this->inSchool(fn () => StockMovement::query()->where('movement_type', 'write_off')->sole());
        $this->assertSame(['expired', $this->loc['a']->id, null, null], [$movement->write_off_reason, $movement->from_location_id, $movement->to_location_id, $movement->recipient_department_id]);

        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'inventory.stock.written_off')->sole());
        $this->assertSame($officer->id, $audit->actor_user_id);
        $this->assertSame('expired', $audit->metadata['reasonCode']);
        $this->assertTrue($audit->metadata['freshMfa']);
        $this->assertArrayNotHasKey('mfa_code', $audit->metadata);

        $rows = $this->get('/app/inventory-stock?movement_type=write_off')->assertOk()->viewData('page')['props']['movements']['data'];
        $this->assertSame([['write_off', 'expired']], array_map(fn ($r) => [$r['movementType'], $r['writeOffReason']], $rows));
    }

    #[Test]
    public function a_reason_the_confirmation_and_a_fresh_code_are_each_required(): void
    {
        $officer = $this->officer([null]);
        $this->signIn($officer);

        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['reason_code' => '']))->assertSessionHasErrors('reason_code');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['reason_code' => 'other']))->assertSessionHasErrors('reason_code');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['reason_code' => 'count_correction']))->assertSessionHasErrors('reason_code');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['confirm' => '0']))->assertSessionHasErrors('confirm');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['mfa_code' => '']))->assertSessionHasErrors('mfa_code');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['mfa_code' => 'NOT-A-CODE']))->assertSessionHasErrors('mfa_code');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['idempotency_key' => '']))->assertSessionHasErrors('idempotency_key');

        $unenrolled = $this->officer([null]);
        $this->signIn($unenrolled);
        $this->assertFalse($this->get('/app/inventory-stock/write-off')->viewData('page')['props']['hasMfaFactor']);
        $this->post('/app/inventory-stock/write-off', [...$this->form($officer), 'mfa_code' => '123456'])->assertSessionHasErrors('mfa_code');

        $this->assertSame(0, $this->writeOffs());
        $this->assertSame('10.000', $this->onHand('a'));
    }

    #[Test]
    public function the_service_refuses_another_users_proof_and_an_unlisted_reason(): void
    {
        $officer = $this->officer([null]);
        $other = $this->officer([null]);

        $this->assertThrows(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'damaged', $officer, $this->proof($other)), LogicException::class);
        $this->assertThrows(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'other', $officer, $this->proof($officer)), InvalidWriteOffReasonException::class);
        $this->assertSame(0, $this->writeOffs());
    }

    #[Test]
    public function authority_is_read_fresh_at_execution_not_from_the_cache(): void
    {
        $whole = $this->officer([null]);
        $this->assertTrue(Gate::forUser($whole)->allows('capability', ['inventory.stock.manage', $this->school]), 'cache warmed');
        $this->inSchool(fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $whole->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertTrue(Gate::forUser($whole)->allows('capability', ['inventory.stock.manage', $this->school]), 'the cached floor still says yes');
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'lost', $whole, $this->proof($whole))), AuthorizationException::class);

        // A Campus grant: valid on its own active Campus only, never a School-wide store.
        $campusA = $this->officer([$this->a->id]);
        $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'lost', $campusA, $this->proof($campusA)));
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['b'], '1', 'lost', $campusA, $this->proof($campusA))), AuthorizationException::class);
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['school'], '1', 'lost', $campusA, $this->proof($campusA))), AuthorizationException::class);
        $this->inSchool(fn () => Campus::query()->whereKey($this->a->id)->update(['status' => 'inactive']));
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'lost', $campusA, $this->proof($campusA))), AuthorizationException::class);

        // A disabled module refuses behind the cache.
        $other = $this->officer([null]);
        $this->inSchool(fn () => DB::table('school_module_entitlements')->where('school_id', $this->school->id)->where('module_key', 'inventory')->update(['enabled' => false]));
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['b'], '1', 'lost', $other, $this->proof($other))), AuthorizationException::class);

        $this->assertSame(1, $this->writeOffs());
    }

    #[Test]
    public function a_campus_officer_writes_off_only_in_scope_locations(): void
    {
        $campusA = $this->officer([$this->a->id]);
        $this->signIn($campusA);

        $this->post('/app/inventory-stock/write-off', $this->form($campusA, 'a'))->assertRedirect('/app/inventory-stock');
        $this->post('/app/inventory-stock/write-off', $this->form($campusA, 'b'))->assertNotFound();
        $this->post('/app/inventory-stock/write-off', $this->form($campusA, 'school'))->assertNotFound();

        $this->assertSame(['7.000', '10.000', '10.000'], [$this->onHand('a'), $this->onHand('b'), $this->onHand('school')]);

        $outsider = $this->createUserWithCapabilities($this->school, ['inventory.stock.view']);
        $this->signIn($outsider);
        $this->get('/app/inventory-stock/write-off')->assertForbidden();
        $this->post('/app/inventory-stock/write-off', $this->form($outsider))->assertForbidden();
    }

    #[Test]
    public function stock_never_goes_negative_and_quantities_follow_the_unit(): void
    {
        $officer = $this->officer([null]);
        $this->signIn($officer);

        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['quantity' => '11']))->assertSessionHasErrors('inventory_item_id');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['quantity' => '0']))->assertSessionHasErrors('inventory_item_id');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['quantity' => '-1']))->assertSessionHasErrors('quantity');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['quantity' => '1.5']))->assertSessionHasErrors('inventory_item_id');
        $this->post('/app/inventory-stock/write-off', $this->form($officer, 'a', ['quantity' => '10']))->assertRedirect('/app/inventory-stock');
        $this->assertSame('0.000', $this->onHand('a'));
        $this->assertThrows(fn () => $this->inSchool(fn () => $this->service()->writeOff($this->school, $this->item, $this->loc['a'], '1', 'lost', $officer, $this->proof($officer))), InsufficientStockException::class);
        $this->assertSame(1, $this->writeOffs());
    }

    #[Test]
    public function a_retried_submission_writes_off_once_and_a_reused_key_with_other_terms_is_refused(): void
    {
        $officer = $this->officer([null]);
        $this->signIn($officer);
        $form = $this->form($officer);

        $this->post('/app/inventory-stock/write-off', $form)->assertRedirect('/app/inventory-stock');
        $this->post('/app/inventory-stock/write-off', [...$form, 'mfa_code' => $this->freshMfaCode($officer)])->assertRedirect('/app/inventory-stock');
        $this->assertSame([1, '7.000'], [$this->writeOffs(), $this->onHand('a')]);
        $this->assertSame(1, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'inventory.stock.written_off')->count()), 'a replay is not audited again');

        $this->post('/app/inventory-stock/write-off', [...$form, 'quantity' => '2', 'mfa_code' => $this->freshMfaCode($officer)])->assertSessionHasErrors('inventory_item_id');
        $this->post('/app/inventory-stock/write-off', [...$form, 'reason_code' => 'lost', 'mfa_code' => $this->freshMfaCode($officer)])->assertSessionHasErrors('inventory_item_id');
        $this->assertSame([1, '7.000'], [$this->writeOffs(), $this->onHand('a')]);
    }

    #[Test]
    public function only_a_key_this_session_was_issued_can_be_claimed(): void
    {
        $officer = $this->officer([null]);
        $this->signIn($officer);

        $this->post('/app/inventory-stock/write-off', [...$this->form($officer), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('idempotency_key');
        $this->post('/app/inventory-stock/issue', [
            'inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['a']->id, 'quantity' => '1', 'idempotency_key' => $this->formKey(),
        ])->assertSessionHasErrors('idempotency_key'); // a write-off key never claims an issue
        $this->assertSame(['10.000', 0], [$this->onHand('a'), $this->writeOffs()]);
    }

    #[Test]
    public function another_school_never_reaches_this_stock_and_keys_are_per_school(): void
    {
        $other = $this->createSchool();
        $otherOfficer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($otherOfficer, $other), 'stores_officer');
        $this->actingAs($otherOfficer)->post("/app/schools/{$other->id}/activate");

        $this->post('/app/inventory-stock/write-off', $this->form($otherOfficer))->assertNotFound();
        $this->assertSame('10.000', $this->onHand('a'));

        // The same literal key in another School is a different claim.
        $key = $this->formKey();
        $otherItem = $this->createInventoryItem($other, ['status' => 'active', 'unit_of_measure' => 'each']);
        $otherLoc = $this->createInventoryLocation($other, ['status' => 'active']);
        app(TenantContext::class)->withSchool($other, fn () => app(InventoryStockService::class)->receive($otherItem, $otherLoc, '5'));
        $this->post('/app/inventory-stock/write-off', [...$this->form($otherOfficer), 'inventory_item_id' => $otherItem->id, 'inventory_location_id' => $otherLoc->id, 'idempotency_key' => $key])->assertRedirect('/app/inventory-stock');
        $officer = $this->officer([null]);
        $this->signIn($officer);
        $this->post('/app/inventory-stock/write-off', [...$this->form($officer), 'idempotency_key' => $key])->assertRedirect('/app/inventory-stock');
        $this->assertSame('7.000', $this->onHand('a'));
    }

    #[Test]
    public function the_api_write_off_needs_a_fresh_code_and_the_locations_scope(): void
    {
        $officer = $this->officer([$this->a->id]);
        $token = $this->mfaToken($officer);
        $base = "/api/v1/schools/{$this->school->id}/inventory-stock/write-off";
        $body = ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['a']->id, 'quantity' => '2', 'reason_code' => 'damaged'];

        $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', 'write-off-1')->postJson($base, $body)->assertUnprocessable()->assertJsonPath('error.errors.mfa_code.0', 'Enter a current authentication code.');
        $this->withHeader('Idempotency-Key', 'write-off-2')->postJson($base, [...$body, ...$this->mfaBody($token)])->assertCreated()->assertJsonPath('data.writeOffReason', 'damaged')->assertJsonPath('data.movementType', 'write_off');
        $this->withHeader('Idempotency-Key', 'write-off-3')->postJson($base, [...$body, 'inventory_location_id' => $this->loc['b']->id, ...$this->mfaBody($token)])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'write-off-4')->postJson($base, [...$body, 'reason_code' => 'other', ...$this->mfaBody($token)])->assertUnprocessable();
        $this->assertSame(['8.000', 1], [$this->onHand('a'), $this->writeOffs()]);
    }
}
