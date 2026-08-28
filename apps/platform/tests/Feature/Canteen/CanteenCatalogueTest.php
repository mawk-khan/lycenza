<?php

namespace Tests\Feature\Canteen;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- Outlet/Item/recipe directory CRUD-with-lifecycle over
 * the /api/v1 surface, mirroring InventoryLifecycleTest.php's exact
 * pattern (create, update, activate/deactivate, code uniqueness).
 */
class CanteenCatalogueTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Outlet ------------------------------------------------------------

    #[Test]
    public function an_outlet_can_be_created(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/canteen-outlets", [
                'code' => 'main-canteen', 'name' => 'Main Canteen', 'inventory_location_id' => $location->id,
            ]);

        $response->assertCreated();
        $this->assertSame('MAIN-CANTEEN', $response->json('data.code'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame($location->id, $response->json('data.inventoryLocationId'));
    }

    #[Test]
    public function an_outlet_code_must_be_unique_per_school_case_insensitively(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/canteen-outlets", [
            'code' => 'CANTEEN1', 'name' => 'Canteen 1', 'inventory_location_id' => $location->id,
        ])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/canteen-outlets", [
            'code' => 'canteen1', 'name' => 'Duplicate', 'inventory_location_id' => $location->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    #[Test]
    public function an_outlet_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $created = $client->postJson("/api/v1/schools/{$school->id}/canteen-outlets", [
            'code' => 'O1', 'name' => 'Outlet 1', 'inventory_location_id' => $location->id,
        ])->json('data');

        $client->patchJson("/api/v1/schools/{$school->id}/canteen-outlets/{$created['id']}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $client->patchJson("/api/v1/schools/{$school->id}/canteen-outlets/{$created['id']}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function a_member_without_directory_manage_cannot_create_an_outlet(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $user = $this->createUserWithCapabilities($school, ['canteen.directory.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/canteen-outlets", [
                'code' => 'X', 'name' => 'X', 'inventory_location_id' => $location->id,
            ])
            ->assertForbidden();
    }

    // --- Item ----------------------------------------------------------------

    #[Test]
    public function an_item_can_be_created(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/canteen-items", [
                'code' => 'samosa', 'name' => 'Samosa', 'price' => '15.00',
            ]);

        $response->assertCreated();
        $this->assertSame('SAMOSA', $response->json('data.code'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame('INR', $response->json('data.currency'));
    }

    #[Test]
    public function an_item_code_must_be_unique_per_school_case_insensitively(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'TEA', 'name' => 'Tea', 'price' => '10.00'])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'tea', 'name' => 'Dup', 'price' => '10.00']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    #[Test]
    public function an_item_rejects_a_negative_price(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'NEG', 'name' => 'Negative', 'price' => '-5.00']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('price', $response->json('error.errors'));
    }

    #[Test]
    public function an_item_can_update_its_price_and_status(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $created = $client->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'C1', 'name' => 'Coffee', 'price' => '20.00'])->json('data');

        $updated = $client->patchJson("/api/v1/schools/{$school->id}/canteen-items/{$created['id']}", ['price' => '25.00', 'status' => 'inactive']);
        $updated->assertOk();
        $this->assertSame('25.00', $updated->json('data.price'));
        $this->assertSame('inactive', $updated->json('data.status'));
    }

    // --- Recipe ----------------------------------------------------------------

    #[Test]
    public function a_recipe_requirement_can_be_added_updated_and_removed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $inventoryItem = $this->createInventoryItem($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $item = $client->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'BURGER', 'name' => 'Burger', 'price' => '80.00'])->json('data');

        $added = $client->postJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe", [
            'inventory_item_id' => $inventoryItem->id, 'quantity_required' => '0.200',
        ]);
        $added->assertCreated();
        $this->assertSame('0.200', $added->json('data.quantityRequired'));

        $requirementId = $added->json('data.id');

        $updated = $client->patchJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe/{$requirementId}", [
            'quantity_required' => '0.250',
        ]);
        $updated->assertOk();
        $this->assertSame('0.250', $updated->json('data.quantityRequired'));

        $client->deleteJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe/{$requirementId}")->assertNoContent();

        $index = $client->getJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe");
        $this->assertCount(0, $index->json('data'));
    }

    #[Test]
    public function a_duplicate_recipe_requirement_for_the_same_ingredient_replaces_rather_than_duplicates(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $inventoryItem = $this->createInventoryItem($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $item = $client->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'PIZZA', 'name' => 'Pizza', 'price' => '150.00'])->json('data');

        $client->postJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe", [
            'inventory_item_id' => $inventoryItem->id, 'quantity_required' => '0.100',
        ])->assertCreated();

        $client->postJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe", [
            'inventory_item_id' => $inventoryItem->id, 'quantity_required' => '0.300',
        ])->assertCreated();

        $index = $client->getJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe");
        $this->assertCount(1, $index->json('data'));
        $this->assertSame('0.300', $index->json('data.0.quantityRequired'));
    }

    #[Test]
    public function a_member_without_directory_manage_cannot_modify_a_recipe(): void
    {
        $school = $this->createSchool();
        $inventoryItem = $this->createInventoryItem($school);
        $adminUser = $this->createUserWithCapabilities($school, ['canteen.directory.manage']);
        $viewOnlyUser = $this->createUserWithCapabilities($school, ['canteen.directory.view']);

        $item = $this->withHeader('Authorization', 'Bearer '.$this->token($adminUser))
            ->postJson("/api/v1/schools/{$school->id}/canteen-items", ['code' => 'D1', 'name' => 'Donut', 'price' => '30.00'])
            ->json('data');

        // Two DIFFERENT users authenticate within this one test method --
        // Sanctum's guard caches its resolved user for the lifetime of
        // the shared test application instance, so switching Bearer
        // tokens alone is not enough; `Auth::forgetGuards()` forces
        // fresh re-resolution for the second request, mirroring
        // InventoryApiTest's identical established precedent.
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($viewOnlyUser))
            ->postJson("/api/v1/schools/{$school->id}/canteen-items/{$item['id']}/recipe", [
                'inventory_item_id' => $inventoryItem->id, 'quantity_required' => '0.100',
            ])
            ->assertForbidden();
    }
}
