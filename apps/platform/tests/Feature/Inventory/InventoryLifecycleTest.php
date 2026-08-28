<?php

namespace Tests\Feature\Inventory;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E -- Item/Location directory lifecycle: create, update,
 * activate/deactivate (rule 73's active/inactive convention, no
 * delete endpoint), code uniqueness at each level, and that inactive
 * resources block new stock mutations while historical movements
 * survive deactivation. Mirrors HostelLifecycleTest.php's pattern.
 */
class InventoryLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Item ------------------------------------------------------------------

    #[Test]
    public function an_item_can_be_created(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-items", [
                'code' => 'paper-a4', 'name' => 'A4 Paper', 'unit_of_measure' => 'box',
            ]);

        $response->assertCreated();
        $this->assertSame('PAPER-A4', $response->json('data.code'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertFalse($response->json('data.allowsFractionalQuantity'));
    }

    #[Test]
    public function a_fractional_unit_item_reports_allows_fractional_quantity(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-items", [
                'code' => 'flour', 'name' => 'Flour', 'unit_of_measure' => 'kg',
            ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.allowsFractionalQuantity'));
    }

    #[Test]
    public function an_unrecognized_unit_of_measure_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-items", [
                'code' => 'widget', 'name' => 'Widget', 'unit_of_measure' => 'gallon',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('unit_of_measure', $response->json('error.errors'));
    }

    #[Test]
    public function a_duplicate_item_code_within_the_same_school_is_rejected_case_insensitively(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->postJson("/api/v1/schools/{$school->id}/inventory-items", ['code' => 'ITEM-A', 'name' => 'A', 'unit_of_measure' => 'each'])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/inventory-items", ['code' => 'item-a', 'name' => 'B', 'unit_of_measure' => 'each']);
        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    #[Test]
    public function two_different_schools_may_reuse_the_identical_item_code(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createInventoryItem($schoolB, ['code' => 'ITEM-A']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/inventory-items", ['code' => 'ITEM-A', 'name' => 'A', 'unit_of_measure' => 'each']);

        $response->assertCreated();
    }

    #[Test]
    public function an_item_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-items/{$item->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-items/{$item->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    // --- Location ----------------------------------------------------------------

    #[Test]
    public function a_location_can_be_created_without_a_campus(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-locations", ['code' => 'main-store', 'name' => 'Main Store']);

        $response->assertCreated();
        $this->assertSame('MAIN-STORE', $response->json('data.code'));
        $this->assertNull($response->json('data.campusId'));
    }

    #[Test]
    public function a_location_can_be_created_with_a_campus(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $campus = $this->createCampus($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-locations", ['code' => 'campus-store', 'name' => 'Campus Store', 'campus_id' => $campus->id]);

        $response->assertCreated();
        $this->assertSame($campus->id, $response->json('data.campusId'));
    }

    #[Test]
    public function a_location_cannot_be_created_with_a_campus_from_a_different_school(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignCampus = $this->createCampus($otherSchool);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-locations", ['code' => 'bad-loc', 'name' => 'Bad', 'campus_id' => $foreignCampus->id]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('campus_id', $response->json('error.errors'));
    }

    #[Test]
    public function two_different_schools_may_reuse_the_identical_location_code(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createInventoryLocation($schoolB, ['code' => 'LOC-A']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->postJson("/api/v1/schools/{$schoolA->id}/inventory-locations", ['code' => 'LOC-A', 'name' => 'A']);

        $response->assertCreated();
    }

    #[Test]
    public function a_location_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-locations/{$location->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-locations/{$location->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    // --- Inactive resources block new mutations, history survives -----------------

    #[Test]
    public function an_inactive_item_cannot_receive_a_new_stock_mutation(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school, ['status' => 'inactive']);
        $location = $this->createInventoryLocation($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-item-receive-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '1',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INVENTORY_ITEM_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function an_inactive_location_cannot_receive_a_new_stock_mutation(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school, ['status' => 'inactive']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-location-receive-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '1',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INVENTORY_LOCATION_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function historical_movements_and_balance_survive_item_and_location_deactivation(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $movement = $client->withHeader('Idempotency-Key', 'history-survive-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '5',
            ])->json('data');

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-items/{$item->id}", ['status' => 'inactive'])->assertOk();
        $client->patchJson("/api/v1/schools/{$school->id}/inventory-locations/{$location->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/inventory-stock/movements")
            ->assertOk()
            ->assertJsonPath('data.0.id', $movement['id']);

        $client->getJson("/api/v1/schools/{$school->id}/inventory-stock?inventory_item_id={$item->id}")
            ->assertOk()
            ->assertJsonPath('data.0.quantityOnHand', '5.000');
    }

    #[Test]
    public function deactivating_an_item_with_positive_balance_does_not_alter_the_balance(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'deactivate-no-drain-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '7',
            ])->assertCreated();

        $client->patchJson("/api/v1/schools/{$school->id}/inventory-items/{$item->id}", ['status' => 'inactive'])->assertOk();

        $balance = $client->getJson("/api/v1/schools/{$school->id}/inventory-stock?inventory_item_id={$item->id}")->json('data.0');
        $this->assertSame('7.000', $balance['quantityOnHand'], 'Deactivation must never silently zero/drain an existing balance.');
    }
}
