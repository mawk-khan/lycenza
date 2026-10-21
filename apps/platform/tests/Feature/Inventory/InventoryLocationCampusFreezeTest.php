<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Application\InventoryLocationService;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7H-1 (ADR 0076 §54): an Inventory Location's Campus is operational
 * provenance. Before any use it can be corrected every way (A -> B, A ->
 * NULL, NULL -> A); once the Location has operational history -- a stock
 * movement from or to it, a stock balance, a Canteen Order -- every Campus
 * change is refused (409 INVENTORY_LOCATION_CAMPUS_FROZEN) while every other
 * field keeps its edit behaviour. Location Campus stays whole-School
 * directory authority; history keeps following the fixed Campus; a stock
 * write never commits under a Campus the Location no longer has.
 */
class InventoryLocationCampusFreezeTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private User $admin;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin('school_admin');
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->item = $this->createInventoryItem($this->school);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function location(?Campus $campus): InventoryLocation
    {
        return $this->createInventoryLocation($this->school, ['campus_id' => $campus?->id, 'status' => 'active']);
    }

    private function api(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    /** @param  array<string, mixed>  $payload */
    private function patchLocation(InventoryLocation $location, array $payload, ?User $user = null): TestResponse
    {
        return $this->api($user ?? $this->admin)->patchJson("/api/v1/schools/{$this->school->id}/inventory-locations/{$location->id}", $payload);
    }

    private function campusOf(InventoryLocation $location): ?string
    {
        return $this->inSchool(fn () => InventoryLocation::query()->whereKey($location->id)->value('campus_id'));
    }

    private function receive(InventoryLocation $location, string $quantity = '5'): StockMovement
    {
        return $this->inSchool(fn () => app(InventoryStockService::class)->receive($this->item, $location->fresh(), $quantity, $this->admin));
    }

    private function assertFrozen(InventoryLocation $location, string $because): void
    {
        $was = $this->campusOf($location);
        $targets = $was === null ? [$this->a->id] : [$was === $this->a->id ? $this->b->id : $this->a->id, null];

        foreach ($targets as $target) {
            $this->patchLocation($location, ['campus_id' => $target])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'INVENTORY_LOCATION_CAMPUS_FROZEN')
                ->assertJsonPath('error.message', 'This Inventory Location already has stock history, so its Campus can no longer change.');
            $this->assertSame($was, $this->campusOf($location), $because);
        }

        // Never frozen: the same Campus (any case), and every other field.
        $this->patchLocation($location, ['campus_id' => $was === null ? null : strtoupper($was), 'name' => 'Renamed store'])->assertOk()
            ->assertJsonPath('data.campusId', $was)->assertJsonPath('data.name', 'Renamed store');
        $this->patchLocation($location, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchLocation($location, ['status' => 'active'])->assertOk();
    }

    #[Test]
    public function an_unused_location_campus_can_be_corrected_every_way(): void
    {
        $location = $this->location($this->a);
        $audits = fn () => $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'inventory.location.updated')->count());

        $this->patchLocation($location, ['campus_id' => $this->b->id])->assertOk()->assertJsonPath('data.campusId', $this->b->id);
        $this->patchLocation($location, ['campus_id' => null])->assertOk()->assertJsonPath('data.campusId', null);
        $this->patchLocation($location, ['campus_id' => $this->a->id])->assertOk()->assertJsonPath('data.campusId', $this->a->id);
        $this->assertSame(3, $audits());

        // Same-School validation is unchanged.
        $foreign = $this->createCampus($this->createSchool());
        $this->patchLocation($location, ['campus_id' => $foreign->id])->assertStatus(422);
        $this->assertSame($this->a->id, $this->campusOf($location));
    }

    #[Test]
    public function a_stock_receipt_freezes_a_campus_location(): void
    {
        $location = $this->location($this->a);
        $this->receive($location);

        $this->assertFrozen($location, 'a received Location keeps Campus A');
    }

    #[Test]
    public function a_transfer_freezes_its_destination_and_a_used_shared_location_stays_shared(): void
    {
        $source = $this->location($this->a);
        $shared = $this->location(null);
        $this->receive($source, '4');
        $this->inSchool(fn () => app(InventoryStockService::class)->transfer($this->item, $source->fresh(), $shared->fresh(), '1', $this->admin));

        $this->assertFrozen($shared, 'a School-shared Location with history is never attached to a Campus');
        $this->assertFrozen($source, 'the transfer source keeps Campus A');
    }

    #[Test]
    public function a_stock_balance_alone_freezes_the_campus(): void
    {
        $location = $this->location($this->b);
        $this->createInventoryStockBalance($this->item, $location, ['quantity_on_hand' => '0.000']);

        $this->assertFrozen($location, 'current stock state is operational evidence even without a movement');
    }

    #[Test]
    public function a_canteen_order_alone_freezes_the_campus(): void
    {
        $location = $this->location($this->a);
        $outlet = $this->createCanteenOutlet($this->school, $location, ['campus_id' => null]);
        $this->createCanteenOrderRaw($this->school, $this->createStudent($this->school), $outlet, ['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->assertFrozen($location, 'a Canteen Order snapshot is operational evidence, cancelled or not');
    }

    #[Test]
    public function history_keeps_following_the_fixed_campus(): void
    {
        $location = $this->location($this->a);
        $movement = $this->receive($location);
        $this->patchLocation($location, ['campus_id' => $this->b->id])->assertStatus(409);

        $officer = fn (string $campusId) => tap($this->createUser(), fn (User $u) => $this->assignSchoolRole($this->createMembership($u, $this->school), 'stores_officer', $campusId));
        $movements = fn (User $user) => collect($this->api($user)->getJson("/api/v1/schools/{$this->school->id}/inventory-stock/movements")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$movement->id], $movements($officer($this->a->id)), 'Campus A keeps its history');
        $this->assertSame([], $movements($officer($this->b->id)), 'Campus B never gains it');
    }

    #[Test]
    public function location_campus_stays_whole_school_directory_authority(): void
    {
        $location = $this->location($this->a);

        foreach (['stores_officer', 'principal'] as $role) {
            $user = $this->createUser();
            $this->assignSchoolRole($this->createMembership($user, $this->school), $role, $this->a->id);
            $this->patchLocation($location, ['campus_id' => $this->b->id], $user)->assertForbidden();
        }
        $this->assertSame($this->a->id, $this->campusOf($location));

        foreach (array_keys(CampusScopeCatalogue::ADOPTED) as $key) {
            $this->assertStringStartsNotWith('inventory.directory.', $key, 'the Location directory is never Campus-adopted');
        }
    }

    #[Test]
    public function the_web_location_edit_never_changes_a_campus(): void
    {
        $location = $this->location($this->a);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");

        $this->patch("/app/inventory-locations/{$location->id}", ['name' => 'Kitchen', 'campus_id' => $this->b->id])->assertRedirect();

        $this->assertSame($this->a->id, $this->campusOf($location));
        $this->assertSame('Kitchen', $this->inSchool(fn () => InventoryLocation::query()->whereKey($location->id)->value('name')));
    }

    #[Test]
    public function a_stock_write_never_commits_under_a_campus_the_location_no_longer_has(): void
    {
        $location = $this->location($this->a);
        $resolved = $this->inSchool(fn () => InventoryLocation::query()->findOrFail($location->id)); // authorized while on Campus A

        $this->inSchool(fn () => app(InventoryLocationService::class)->update($this->school, $location->id, ['campus_id' => $this->b->id], $this->admin));

        $this->assertThrows(fn () => $this->inSchool(fn () => app(InventoryStockService::class)->receive($this->item, $resolved, '1', $this->admin)), LocationNotAvailableException::class);
        $this->assertSame(0, $this->inSchool(fn () => StockMovement::query()->count()));
        $this->assertFalse($this->inSchool(fn () => app(InventoryLocationService::class)->hasOperationalHistory($location)), 'the refused write left no history');

        $this->receive($location); // the Location's current Campus works
        $this->assertTrue($this->inSchool(fn () => app(InventoryLocationService::class)->hasOperationalHistory($location)));
    }
}
