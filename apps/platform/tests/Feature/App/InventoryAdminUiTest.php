<?php

namespace Tests\Feature\App;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E -- the administrative Inventory Inertia UI
 * (App\Http\Controllers\App\{InventoryItemController,
 * InventoryLocationController, InventoryStockController}). Backend
 * authorization/tenant-safety/domain invariants are already proven by
 * the JSON API test suite (InventoryApiTest, InventoryLifecycleTest,
 * InventoryStockServiceTest) -- these tests cover the Inertia-specific
 * integration: page rendering, capability-aware props, and -- carrying
 * forward the Library/Transport/Visitor/Hostel precedent -- that the
 * receive/issue/transfer forms' live Item/Location search endpoints
 * reject an unauthorized School member exactly like the mutation
 * itself. Mirrors HostelAdminUiTest's exact pattern.
 */
class InventoryAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, User $user, string $roleKey, string $capability): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync([$capability]));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ])));
    }

    // --- Items ----------------------------------------------------------------

    #[Test]
    public function a_member_with_directory_view_sees_the_items_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createInventoryItem($school, ['name' => 'A4 Paper']);

        $this->get('/app/inventory-items')->assertInertia(fn ($page) => $page
            ->component('App/Inventory/Items/Index')
            ->where('canManage', true)
            ->has('items.data', 1)
        );
    }

    #[Test]
    public function a_member_without_directory_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/inventory-items')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_add_and_update_an_item_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/inventory-items', ['code' => 'ui-item', 'name' => 'UI Item', 'unit_of_measure' => 'each']);
        $response->assertRedirect();

        $item = app(TenantContext::class)->withSchool($school, fn () => InventoryItem::query()->where('code', 'UI-ITEM')->first());
        $this->assertNotNull($item);

        $update = $this->patch("/app/inventory-items/{$item->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $item->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    #[Test]
    public function a_view_only_directory_member_cannot_add_an_item(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_directory_viewer_ui', 'inventory.directory.view');
        $this->activate($viewer, $school);

        $this->post('/app/inventory-items', ['code' => 'DENIED', 'name' => 'Denied', 'unit_of_measure' => 'each'])->assertForbidden();
        $exists = app(TenantContext::class)->withSchool($school, fn () => InventoryItem::query()->where('code', 'DENIED')->exists());
        $this->assertFalse($exists);
    }

    // --- Locations --------------------------------------------------------------

    #[Test]
    public function a_school_admin_can_add_and_update_a_location_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/inventory-locations', ['code' => 'ui-loc', 'name' => 'UI Location']);
        $response->assertRedirect();

        $location = app(TenantContext::class)->withSchool($school, fn () => InventoryLocation::query()->where('code', 'UI-LOC')->first());
        $this->assertNotNull($location);

        $update = $this->patch("/app/inventory-locations/{$location->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $location->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    // --- Stock --------------------------------------------------------------------

    #[Test]
    public function a_member_with_stock_view_sees_the_stock_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $this->createInventoryStockBalance($item, $location, ['quantity_on_hand' => '4.000']);

        $this->get('/app/inventory-stock')->assertInertia(fn ($page) => $page
            ->component('App/Inventory/Stock/Index')
            ->where('canManage', true)
            ->has('balances.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_receive_issue_and_transfer_stock_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);

        $this->post('/app/inventory-stock/receive', [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $from->id, 'quantity' => '10',
        ])->assertRedirect('/app/inventory-stock');

        $this->post('/app/inventory-stock/issue', [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $from->id, 'quantity' => '2',
        ])->assertRedirect('/app/inventory-stock');

        $this->post('/app/inventory-stock/transfer', [
            'inventory_item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'quantity' => '3',
        ])->assertRedirect('/app/inventory-stock');

        $movementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->count());
        $this->assertSame(3, $movementCount);
    }

    #[Test]
    public function issuing_beyond_available_stock_via_the_ui_shows_a_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->post('/app/inventory-stock/receive', [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '2',
        ])->assertRedirect();

        $response = $this->post('/app/inventory-stock/issue', [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '5',
        ]);
        $response->assertSessionHasErrors('inventory_item_id');
    }

    /**
     * THE anti-P1 regression carried forward from Library/Transport/
     * Visitor/Hostel: the receive/issue/transfer forms' live search
     * endpoints must enforce inventory.stock.manage exactly like the
     * mutation actions themselves -- an unauthorized School member must
     * never be able to enumerate Item catalogue data or Location data
     * through these endpoints.
     */
    #[Test]
    public function a_member_without_stock_manage_cannot_use_the_stock_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $this->createInventoryItem($school, ['code' => 'FINDABLE-ITEM', 'name' => 'Findable']);
        $this->createInventoryLocation($school, ['code' => 'FINDABLE-LOC', 'name' => 'Findable']);

        $this->get('/app/inventory-stock/search/items?q=Findable')->assertForbidden();
        $this->get('/app/inventory-stock/search/locations?q=Findable')->assertForbidden();
    }

    #[Test]
    public function a_stock_manage_member_can_use_the_stock_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createInventoryItem($school, ['code' => 'FINDABLE-ITEM', 'name' => 'Findable']);
        $this->createInventoryLocation($school, ['code' => 'FINDABLE-LOC', 'name' => 'Findable']);

        $this->get('/app/inventory-stock/search/items?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/inventory-stock/search/locations?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_wrong_school_item_id_is_not_found_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);

        $this->patch("/app/inventory-items/{$itemB->id}", ['status' => 'inactive'])->assertNotFound();
    }

    #[Test]
    public function a_wrong_school_receive_payload_is_rejected_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $locationA = $this->createInventoryLocation($schoolA);
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);

        $this->post('/app/inventory-stock/receive', [
            'inventory_item_id' => $itemB->id, 'inventory_location_id' => $locationA->id, 'quantity' => '1',
        ])->assertNotFound();
    }
}
