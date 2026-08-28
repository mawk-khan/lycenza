<?php

namespace Tests\Feature\App;

use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- the administrative Canteen Inertia UI
 * (App\Http\Controllers\App\Canteen\*). Backend authorization/tenant-
 * safety/domain invariants are already proven by the Domain-layer test
 * suites (CanteenOrderPlacementTest, CanteenOrderFulfillmentTest,
 * CanteenCatalogueTest, CanteenBillingConfigurationTest, ...) -- these
 * tests cover the Inertia-specific integration: page rendering,
 * capability-aware props, and -- carrying forward the Inventory/
 * Library/Transport/Visitor/Hostel anti-P1 precedent -- that the Order
 * placement form's live Student/Item search endpoints reject an
 * unauthorized School member exactly like the mutation itself.
 *
 * IMPORTANT (this phase's report flags this as the load-bearing
 * assertion): CanteenOrderController::index()'s summary rows must never
 * include `unitPrice`/`lineTotal`/`totalAmount` (Highly Sensitive,
 * docs/security/DATA-CLASSIFICATION.md) -- only show()'s detail props
 * may. `orders_index_never_leaks_money_fields_while_show_does_include_them()`
 * below pins this at the actual HTTP boundary, not just in the
 * controller source.
 *
 * Mirrors InventoryAdminUiTest's exact pattern.
 */
class CanteenAdminUiTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, User $user, string $roleKey, string $capability): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync([$capability]);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ]));
    }

    // --- Outlets ----------------------------------------------------------------

    #[Test]
    public function a_member_with_directory_view_sees_the_outlets_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $location = $this->createInventoryLocation($school);
        $this->createCanteenOutlet($school, $location, ['name' => 'Main Canteen']);

        $this->get('/app/canteen-outlets')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Outlets/Index')
            ->where('canManage', true)
            ->has('outlets.data', 1)
        );
    }

    #[Test]
    public function a_member_without_directory_view_is_forbidden_from_outlets_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/canteen-outlets')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_add_and_toggle_an_outlet_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $location = $this->createInventoryLocation($school);

        $response = $this->post('/app/canteen-outlets', [
            'code' => 'ui-outlet', 'name' => 'UI Outlet', 'inventory_location_id' => $location->id,
        ]);
        $response->assertRedirect();

        $outlet = app(TenantContext::class)->withSchool($school, fn () => CanteenOutlet::query()->where('code', 'UI-OUTLET')->first());
        $this->assertNotNull($outlet);

        $update = $this->patch("/app/canteen-outlets/{$outlet->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $outlet->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    /**
     * Phase 10F fix -- the capability-boundary bug the UI phase flagged:
     * these two helper search endpoints (used by Outlets/Create.vue and
     * Items/Show.vue) must be reachable with ONLY
     * `canteen.directory.manage` -- NOT `inventory.stock.manage`, which
     * they were previously gated behind (they called Inventory's own
     * `/app/inventory-stock/search/...` endpoints). Also proves a
     * member with NEITHER capability is rejected, before-query-
     * execution, matching the existing anti-P1 test pattern
     * (`a_member_without_orders_manage_cannot_use_the_order_search_endpoints`
     * above).
     */
    #[Test]
    public function a_member_without_directory_manage_cannot_use_the_canteen_inventory_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_directory_viewer_ui', 'canteen.directory.view');
        $this->activate($user, $school);
        $this->createInventoryLocation($school, ['code' => 'FINDABLE-LOC', 'name' => 'Findable']);
        $this->createInventoryItem($school, ['code' => 'FINDABLE-INV', 'name' => 'Findable']);

        $this->get('/app/canteen-outlets/search/inventory-locations?q=Findable')->assertForbidden();
        $this->get('/app/canteen-items/search/inventory-items?q=Findable')->assertForbidden();
    }

    #[Test]
    public function a_directory_manage_member_without_inventory_stock_manage_can_use_the_canteen_inventory_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        // Deliberately grants ONLY canteen.directory.manage -- no
        // inventory.stock.manage, no inventory.directory.* -- to prove
        // the fix: a Canteen-only user no longer needs a raw Inventory
        // capability to search Locations/Items.
        $this->grantOnly($school, $user, 'test_directory_manager_ui', 'canteen.directory.manage');
        $this->activate($user, $school);
        $this->createInventoryLocation($school, ['code' => 'FINDABLE-LOC', 'name' => 'Findable']);
        $this->createInventoryItem($school, ['code' => 'FINDABLE-INV', 'name' => 'Findable']);

        $this->get('/app/canteen-outlets/search/inventory-locations?q=Findable')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get('/app/canteen-items/search/inventory-items?q=Findable')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Items + recipe -----------------------------------------------------------

    #[Test]
    public function a_member_with_directory_view_sees_the_items_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createCanteenItem($school, ['name' => 'Samosa']);

        $this->get('/app/canteen-items')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Items/Index')
            ->where('canManage', true)
            ->has('items.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_add_view_and_manage_an_items_recipe_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/canteen-items', ['code' => 'ui-item', 'name' => 'UI Item', 'price' => '25.00']);
        $response->assertRedirect();

        $item = app(TenantContext::class)->withSchool($school, fn () => CanteenItem::query()->where('code', 'UI-ITEM')->first());
        $this->assertNotNull($item);

        $this->get("/app/canteen-items/{$item->id}")->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Items/Show')
            ->where('item.code', 'UI-ITEM')
            ->where('canManage', true)
            ->has('item.recipe', 0)
        );

        $inventoryItem = $this->createInventoryItem($school);
        $storeRequirement = $this->post("/app/canteen-items/{$item->id}/recipe", [
            'inventory_item_id' => $inventoryItem->id, 'quantity_required' => '0.500',
        ]);
        $storeRequirement->assertRedirect();

        $this->get("/app/canteen-items/{$item->id}")->assertInertia(fn ($page) => $page
            ->has('item.recipe', 1)
            ->where('item.recipe.0.quantityRequired', '0.500')
        );

        $requirement = app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenItemInventoryRequirement::query()->where('canteen_item_id', $item->id)->firstOrFail(),
        );

        $this->delete("/app/canteen-items/{$item->id}/recipe/{$requirement->id}")->assertRedirect();

        $this->get("/app/canteen-items/{$item->id}")->assertInertia(fn ($page) => $page
            ->has('item.recipe', 0)
        );
    }

    #[Test]
    public function a_wrong_school_item_id_is_not_found_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $itemB = $this->createCanteenItem($schoolB);

        $this->get("/app/canteen-items/{$itemB->id}")->assertNotFound();
        $this->patch("/app/canteen-items/{$itemB->id}", ['status' => 'inactive'])->assertNotFound();
    }

    // --- Settings -----------------------------------------------------------------

    #[Test]
    public function settings_shows_not_configured_then_can_be_configured_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/canteen-settings')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Settings/Edit')
            ->where('configuration', null)
            ->where('canManage', true)
        );

        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $update = $this->put('/app/canteen-settings', [
            'receivable_ledger_account_id' => $receivable->id,
            'revenue_ledger_account_id' => $revenue->id,
        ]);
        $update->assertRedirect();

        $config = app(TenantContext::class)->withSchool($school, fn () => CanteenBillingConfiguration::query()->where('school_id', $school->id)->first());
        $this->assertNotNull($config);
        $this->assertSame($receivable->id, $config->receivable_ledger_account_id);

        $this->get('/app/canteen-settings')->assertInertia(fn ($page) => $page
            ->where('configuration.receivableLedgerAccountId', $receivable->id)
        );
    }

    #[Test]
    public function a_settings_viewer_without_manage_gets_a_readonly_page(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_settings_viewer_ui', 'canteen.settings.view');
        $this->activate($viewer, $school);

        $this->get('/app/canteen-settings')->assertInertia(fn ($page) => $page
            ->where('canManage', false)
        );

        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->put('/app/canteen-settings', [
            'receivable_ledger_account_id' => $receivable->id,
            'revenue_ledger_account_id' => $revenue->id,
        ])->assertForbidden();
    }

    #[Test]
    public function a_member_without_settings_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/canteen-settings')->assertForbidden();
    }

    // --- Orders ---------------------------------------------------------------------

    #[Test]
    public function orders_create_page_lists_active_outlets(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $location = $this->createInventoryLocation($school);
        $this->createCanteenOutlet($school, $location, ['code' => 'OUT-A']);

        $this->get('/app/canteen-orders/create')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Orders/Create')
            ->has('outlets', 1)
        );
    }

    #[Test]
    public function a_member_without_orders_manage_cannot_use_the_order_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_orders_viewer_ui', 'canteen.orders.view');
        $this->activate($user, $school);
        $this->createStudent($school, ['first_name' => 'Findable']);
        $this->createCanteenItem($school, ['code' => 'FINDABLE-ITEM', 'name' => 'Findable']);

        $this->get('/app/canteen-orders/search/students?q=Findable')->assertForbidden();
        $this->get('/app/canteen-orders/search/items?q=Findable')->assertForbidden();
    }

    #[Test]
    public function an_orders_manage_member_can_use_the_order_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createStudent($school, ['first_name' => 'Findable']);
        $this->createCanteenItem($school, ['code' => 'FINDABLE-ITEM', 'name' => 'Findable']);

        $this->get('/app/canteen-orders/search/students?q=Findable')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/app/canteen-orders/search/items?q=Findable')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_member_without_orders_view_is_forbidden_from_orders_index_and_show(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);

        $viewer = $this->createUser();
        $this->createMembership($viewer, $school);
        $this->activate($viewer, $school);

        $this->get('/app/canteen-orders')->assertForbidden();
        $this->get("/app/canteen-orders/{$order->id}")->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_place_fulfill_and_view_an_order_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createAcademicYear($school, ['status' => 'active']);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configureCanteenBilling($school, $receivable, $revenue);

        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '12.50']);

        $place = $this->post('/app/canteen-orders', [
            'student_id' => $student->id,
            'outlet_id' => $outlet->id,
            'lines' => [['canteen_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $place->assertRedirect();

        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->where('student_id', $student->id)->firstOrFail());
        $this->assertSame('25.00', $order->total_amount);

        $fulfill = $this->post("/app/canteen-orders/{$order->id}/fulfill");
        $fulfill->assertRedirect();

        $fulfilled = app(TenantContext::class)->withSchool($school, fn () => $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->status);
        $this->assertNotNull($fulfilled->charge_id);

        $this->get("/app/canteen-orders/{$order->id}")->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Orders/Show')
            ->where('order.status', 'fulfilled')
            ->where('order.totalAmount', '25.00')
            ->where('order.lines.0.unitPrice', '12.50')
            ->where('order.lines.0.lineTotal', '25.00')
        );
    }

    #[Test]
    public function a_pending_order_can_be_cancelled_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '5.00']);

        $place = $this->post('/app/canteen-orders', [
            'student_id' => $student->id,
            'outlet_id' => $outlet->id,
            'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $place->assertRedirect();
        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->where('student_id', $student->id)->firstOrFail());

        $this->post("/app/canteen-orders/{$order->id}/cancel")->assertRedirect();

        $cancelled = app(TenantContext::class)->withSchool($school, fn () => $order->fresh());
        $this->assertSame('cancelled', $cancelled->status);
    }

    /**
     * THE load-bearing assertion this phase's report calls out: proves,
     * at the actual HTTP boundary (not just by reading controller
     * source), that CanteenOrderController::index()'s summary rows
     * never carry `unitPrice`/`lineTotal`/`totalAmount` while show()'s
     * detail response does. See DATA-CLASSIFICATION.md -- Order money
     * fields are Highly Sensitive and must never appear in a list/
     * summary response.
     */
    #[Test]
    public function orders_index_never_leaks_money_fields_while_show_does_include_them(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '30.00']);

        $place = $this->post('/app/canteen-orders', [
            'student_id' => $student->id,
            'outlet_id' => $outlet->id,
            'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $place->assertRedirect();
        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->where('student_id', $student->id)->firstOrFail());

        $this->get('/app/canteen-orders')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Orders/Index')
            ->has('orders.data', 1)
            ->missing('orders.data.0.unitPrice')
            ->missing('orders.data.0.lineTotal')
            ->missing('orders.data.0.totalAmount')
            ->missing('orders.data.0.currency')
            ->missing('orders.data.0.lines')
        );

        $this->get("/app/canteen-orders/{$order->id}")->assertInertia(fn ($page) => $page
            ->has('order.totalAmount')
            ->has('order.currency')
            ->has('order.lines.0.unitPrice')
            ->has('order.lines.0.lineTotal')
        );
    }

    // --- Navigation -------------------------------------------------------------

    #[Test]
    public function dashboard_nav_shows_canteen_links_only_when_the_matching_capability_is_held(): void
    {
        $school = $this->createSchool();
        $directoryViewer = $this->createUser();
        $this->grantOnly($school, $directoryViewer, 'test_canteen_directory_viewer_ui', 'canteen.directory.view');
        $this->activate($directoryViewer, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewCanteenDirectory', true)
            ->where('nav.canViewCanteenOrders', false)
            ->where('nav.canViewCanteenSettings', false)
        );

        $noAccess = $this->createUser();
        $this->createMembership($noAccess, $school);
        $this->activate($noAccess, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewCanteenDirectory', false)
            ->where('nav.canViewCanteenOrders', false)
            ->where('nav.canViewCanteenSettings', false)
        );

        [$admin, $adminSchool] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $adminSchool);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewCanteenDirectory', true)
            ->where('nav.canViewCanteenOrders', true)
            ->where('nav.canViewCanteenSettings', true)
        );
    }
}
