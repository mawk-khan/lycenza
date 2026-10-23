<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11, §33): `inventory.stock.*` are Campus-adopted. A
 * Location's Campus decides; a Location without a Campus is a School-wide
 * store that only whole-School scope reaches; a movement is visible only
 * when every Location it touches is in scope; a transfer needs both ends.
 */
class InventoryStockCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

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
        $this->item = $this->createInventoryItem($this->school, ['status' => 'active']);
        $this->loc['a'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'code' => 'STORE-A', 'name' => 'North Store', 'status' => 'active']);
        $this->loc['b'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->b->id, 'code' => 'STORE-B', 'name' => 'South Store', 'status' => 'active']);
        $this->loc['school'] = $this->createInventoryLocation($this->school, ['campus_id' => null, 'code' => 'CENTRAL', 'name' => 'Central Store', 'status' => 'active']);

        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($this->school, function () use ($service): void {
            foreach ($this->loc as $location) {
                $service->receive($this->item, $location, '10');
            }
            $service->transfer($this->item, $this->loc['a'], $this->loc['b'], '1');
        });
    }

    /** @param  list<?string>  $campusIds */
    private function storesOfficer(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'stores_officer', $campusId);
        }

        return $user;
    }

    private function api(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    /** @return list<string> location codes of the listed balances */
    private function balanceCodes(User $user): array
    {
        return collect($this->api($user)->getJson("/api/v1/schools/{$this->school->id}/inventory-stock")->assertOk()->json('data'))
            ->pluck('location.code')->sort()->values()->all();
    }

    private function quantity(string $key): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => InventoryStockBalance::query()
            ->where('inventory_location_id', $this->loc[$key]->id)->value('quantity_on_hand'));
    }

    #[Test]
    public function a_whole_school_stores_officer_is_unchanged(): void
    {
        $user = $this->storesOfficer([null]);

        $this->assertSame(['CENTRAL', 'STORE-A', 'STORE-B'], $this->balanceCodes($user));
        $this->assertCount(4, $this->api($user)->getJson("/api/v1/schools/{$this->school->id}/inventory-stock/movements")->json('data'));
        $this->api($user)->withHeader('Idempotency-Key', 'whole-central')->postJson("/api/v1/schools/{$this->school->id}/inventory-stock/receive", [
            'inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['school']->id, 'quantity' => '1',
        ])->assertCreated();
    }

    #[Test]
    public function a_campus_a_stores_officer_reaches_only_campus_a_locations(): void
    {
        $user = $this->storesOfficer([$this->a->id]);

        // Balances: A only -- never B, never the School-wide store.
        $this->assertSame(['STORE-A'], $this->balanceCodes($user));

        // Movements: the receipt into A only; the A->B transfer touches B and is not shown.
        $movements = collect($this->api($user)->getJson("/api/v1/schools/{$this->school->id}/inventory-stock/movements")->json('data'));
        $this->assertSame(['receipt'], $movements->pluck('movementType')->all());
        $this->assertSame('STORE-A', $movements->first()['toLocation']['code']);

        // Web: same scope; the location search is scoped (and its OR is grouped).
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/inventory-stock')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('balances.data', 1)->where('canManage', true));
        $this->assertSame(['STORE-A'], collect($this->getJson('/app/inventory-stock/search/locations?q=Store')->assertOk()->json('data'))->pluck('code')->all());
        $this->assertSame([], collect($this->getJson('/app/inventory-stock/search/locations?q=South')->json('data'))->all());

        // Writes: A yes; B and the School-wide store NOT FOUND; a transfer needs both ends.
        $post = fn (string $path, array $body, string $key) => $this->api($user)->withHeader('Idempotency-Key', $key)->postJson("/api/v1/schools/{$this->school->id}/inventory-stock/{$path}", $body);
        $post('receive', ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['a']->id, 'quantity' => '2'], 'campus-scope-k1')->assertCreated();
        $post('receive', ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['b']->id, 'quantity' => '2'], 'campus-scope-k2')->assertNotFound();
        $post('issue', ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['school']->id, 'quantity' => '1'], 'campus-scope-k3')->assertNotFound();
        $post('transfer', ['inventory_item_id' => $this->item->id, 'from_location_id' => $this->loc['a']->id, 'to_location_id' => $this->loc['b']->id, 'quantity' => '1'], 'campus-scope-k4')->assertNotFound();
        $this->post('/app/inventory-stock/issue', ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['b']->id, 'quantity' => '1', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();

        $this->assertSame('11.000', $this->quantity('a'));
        $this->assertSame('11.000', $this->quantity('b'));
        $this->assertSame('10.000', $this->quantity('school'));
    }

    #[Test]
    public function both_campuses_allow_a_transfer_between_them_and_inactive_campus_gives_nothing(): void
    {
        $user = $this->storesOfficer([$this->a->id, $this->b->id]);
        $this->assertSame(['STORE-A', 'STORE-B'], $this->balanceCodes($user));
        $this->api($user)->withHeader('Idempotency-Key', 'campus-scope-ab')->postJson("/api/v1/schools/{$this->school->id}/inventory-stock/transfer", [
            'inventory_item_id' => $this->item->id, 'from_location_id' => $this->loc['a']->id, 'to_location_id' => $this->loc['b']->id, 'quantity' => '1',
        ])->assertCreated();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->b->forceFill(['status' => 'inactive'])->save());
        $this->assertSame(['STORE-A'], $this->balanceCodes($user));
    }

    #[Test]
    public function the_inventory_directory_stays_school_only_for_a_campus_grant(): void
    {
        $user = $this->storesOfficer([$this->a->id]);

        $this->api($user)->getJson("/api/v1/schools/{$this->school->id}/inventory-locations")->assertForbidden();
        $this->api($user)->postJson("/api/v1/schools/{$this->school->id}/inventory-items", ['code' => 'X', 'name' => 'X', 'unit_of_measure' => 'pcs'])->assertForbidden();
    }
}
