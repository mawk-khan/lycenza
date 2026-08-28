<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E -- the /api/v1 Inventory surface: authorization boundaries
 * across both capability areas, cross-School rejection (IDOR), and the
 * required idempotency proof for all three stock mutations. Mirrors
 * HostelApiTest's exact pattern.
 */
class InventoryApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Guest / unauthenticated ---------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_inventory_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/inventory-items")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/inventory-stock")->assertUnauthorized();
    }

    // --- Authorization allow/deny per capability area --------------------

    #[Test]
    public function a_member_without_directory_manage_cannot_create_an_item(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['inventory.directory.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-items", ['code' => 'I1', 'name' => 'Denied', 'unit_of_measure' => 'each'])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_stock_manage_cannot_receive_stock(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $user = $this->createUserWithCapabilities($school, ['inventory.stock.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-receive-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '1',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function directory_manage_alone_cannot_receive_stock(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $user = $this->createUserWithCapabilities($school, ['inventory.directory.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-area-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '1',
            ])
            ->assertForbidden();
    }

    // --- Cross-School rejection (IDOR) --------------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_item(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/inventory-items/{$itemB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function receiving_stock_for_an_item_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);

        $otherSchool = $this->createSchool();
        $foreignItem = $this->createInventoryItem($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-item-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $foreignItem->id, 'inventory_location_id' => $location->id, 'quantity' => '1',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function receiving_stock_into_a_location_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);

        $otherSchool = $this->createSchool();
        $foreignLocation = $this->createInventoryLocation($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-location-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $foreignLocation->id, 'quantity' => '1',
            ])
            ->assertNotFound();
    }

    // --- Validation -------------------------------------------------------

    #[Test]
    public function item_creation_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/inventory-items", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['code', 'name', 'unit_of_measure'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function receive_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'invalid-receive-payload-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['inventory_item_id', 'inventory_location_id', 'quantity'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function receive_rejects_a_quantity_with_too_many_decimal_places(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'too-many-decimals-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '1.2345',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('quantity', $response->json('error.errors'));
    }

    #[Test]
    public function issuing_beyond_available_stock_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'receive-for-overissue-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '3',
            ])->assertCreated();

        $response = $client->withHeader('Idempotency-Key', 'overissue-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '4',
            ]);
        $response->assertStatus(422);
        $this->assertSame('INVENTORY_INSUFFICIENT_STOCK', $response->json('error.code'));
    }

    #[Test]
    public function transfer_to_the_same_location_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'same-location-transfer-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", [
                'inventory_item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => $location->id, 'quantity' => '1',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INVENTORY_SAME_LOCATION_TRANSFER', $response->json('error.code'));
    }

    // --- Idempotency: receive -----------------------------------------------

    #[Test]
    public function replaying_the_same_receive_idempotency_key_creates_only_one_movement(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-receive-001');
        $payload = ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '5'];

        $first = $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $movements = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/inventory-stock/movements")
            ->json('data');
        $this->assertCount(1, $movements, 'A replayed receive must never create a second movement.');

        $balance = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/inventory-stock")
            ->json('data.0');
        $this->assertSame('5.000', $balance['quantityOnHand'], 'A replayed receive must never double-apply its stock effect.');
    }

    #[Test]
    public function the_same_receive_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $locationA = $this->createInventoryLocation($school);
        $locationB = $this->createInventoryLocation($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-receive-001');

        $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $locationA->id, 'quantity' => '5',
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $locationB->id, 'quantity' => '5',
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function a_receive_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-receive-001');
        $payload = ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '5'];

        $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $client->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", $payload)->assertForbidden();
    }

    // --- Idempotency: issue -----------------------------------------------

    #[Test]
    public function replaying_the_same_issue_idempotency_key_creates_only_one_movement(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'issue-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '10',
            ])->assertCreated();

        $issueClient = $client->withHeader('Idempotency-Key', 'replay-issue-001');
        $payload = ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '4'];

        $first = $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", $payload);
        $first->assertCreated();

        $second = $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $issueMovements = $client->getJson("/api/v1/schools/{$school->id}/inventory-stock/movements?movement_type=issue")->json('data');
        $this->assertCount(1, $issueMovements, 'A replayed issue must never create a second movement.');

        $balance = $client->getJson("/api/v1/schools/{$school->id}/inventory-stock")->json('data.0');
        $this->assertSame('6.000', $balance['quantityOnHand'], 'A replayed issue must never double-apply its stock effect (10 - 4 = 6, not 2).');
    }

    #[Test]
    public function the_same_issue_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'issue-conflict-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '10',
            ])->assertCreated();

        $issueClient = $client->withHeader('Idempotency-Key', 'conflict-issue-001');

        $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '2',
        ])->assertCreated();

        $conflict = $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", [
            'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '3',
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function an_issue_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'issue-revoke-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '10',
            ])->assertCreated();

        $issueClient = $client->withHeader('Idempotency-Key', 'revoked-issue-001');
        $payload = ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '2'];

        $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $issueClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/issue", $payload)->assertForbidden();
    }

    // --- Idempotency: transfer -----------------------------------------------

    #[Test]
    public function replaying_the_same_transfer_idempotency_key_creates_only_one_movement(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'transfer-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $from->id, 'quantity' => '10',
            ])->assertCreated();

        $transferClient = $client->withHeader('Idempotency-Key', 'replay-transfer-001');
        $payload = ['inventory_item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'quantity' => '3'];

        $first = $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", $payload);
        $first->assertCreated();

        $second = $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $transferMovements = $client->getJson("/api/v1/schools/{$school->id}/inventory-stock/movements?movement_type=transfer")->json('data');
        $this->assertCount(1, $transferMovements, 'A replayed transfer must never create a second movement.');

        $fromBalance = $client->getJson("/api/v1/schools/{$school->id}/inventory-stock?inventory_location_id={$from->id}")->json('data.0');
        $this->assertSame('7.000', $fromBalance['quantityOnHand'], 'A replayed transfer must never double-apply its stock effect (10 - 3 = 7, not 4).');
    }

    #[Test]
    public function the_same_transfer_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $toA = $this->createInventoryLocation($school);
        $toB = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'transfer-conflict-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $from->id, 'quantity' => '10',
            ])->assertCreated();

        $transferClient = $client->withHeader('Idempotency-Key', 'conflict-transfer-001');

        $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", [
            'inventory_item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $toA->id, 'quantity' => '2',
        ])->assertCreated();

        $conflict = $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", [
            'inventory_item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $toB->id, 'quantity' => '2',
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function a_transfer_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->withHeader('Idempotency-Key', 'transfer-revoke-source-001')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", [
                'inventory_item_id' => $item->id, 'inventory_location_id' => $from->id, 'quantity' => '10',
            ])->assertCreated();

        $transferClient = $client->withHeader('Idempotency-Key', 'revoked-transfer-001');
        $payload = ['inventory_item_id' => $item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'quantity' => '2'];

        $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $transferClient->postJson("/api/v1/schools/{$school->id}/inventory-stock/transfer", $payload)->assertForbidden();
    }
}
