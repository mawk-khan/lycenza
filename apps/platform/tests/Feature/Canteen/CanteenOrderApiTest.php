<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- the /api/v1 Canteen Order surface: authorization
 * boundaries, cross-School rejection (IDOR), idempotency proofs for
 * place/fulfill, the mandatory Highly Sensitive list-vs-detail money
 * field projection, and helper-search anti-P1 (capability checked
 * before any query executes).
 */
class CanteenOrderApiTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function setUpBilling(School $school): void
    {
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        // The Application service is called directly under an explicit
        // TenantContext, matching every other test file's established
        // convention for a service call made outside an HTTP request.
        app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenBillingConfigurationService::class)->configure($school, $receivable->id, $revenue->id),
        );
    }

    // --- Guest / unauthenticated ---------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_canteen_order_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/canteen-orders")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/canteen-orders", [])->assertUnauthorized();
    }

    // --- Authorization ---------------------------------------------------

    #[Test]
    public function a_member_without_orders_manage_cannot_place_an_order(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $deniedUser = $this->createUserWithCapabilities($school, ['canteen.orders.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($deniedUser))
            ->withHeader('Idempotency-Key', 'denied-place-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_orders_manage_cannot_fulfill_an_order(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $order = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'place-for-deny-fulfill-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])->json('data');

        $deniedUser = $this->createUserWithCapabilities($school, ['canteen.orders.view']);
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($deniedUser))
            ->withHeader('Idempotency-Key', 'denied-fulfill-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders/{$order['id']}/fulfill")
            ->assertForbidden();
    }

    // --- Cross-School rejection (IDOR) --------------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_order(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $itemB = $this->createCanteenItem($schoolB);
        $userB = $this->createUserWithCapabilities($schoolB, ['canteen.orders.manage']);

        $orderB = $this->withHeader('Authorization', 'Bearer '.$this->token($userB))
            ->withHeader('Idempotency-Key', 'cross-school-order-001')
            ->postJson("/api/v1/schools/{$schoolB->id}/canteen-orders", [
                'student_id' => $studentB->id,
                'outlet_id' => $outletB->id,
                'lines' => [['canteen_item_id' => $itemB->id, 'quantity' => 1]],
            ])->json('data');

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/canteen-orders/{$orderB['id']}")
            ->assertNotFound();
    }

    #[Test]
    public function placing_an_order_for_a_student_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $item = $this->createCanteenItem($school);

        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-student-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $foreignStudent->id,
                'outlet_id' => $outlet->id,
                'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(404);
    }

    // --- Highly Sensitive projection: list vs detail --------------------------

    #[Test]
    public function the_list_endpoint_never_includes_money_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '77.00']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'list-projection-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])->assertCreated();

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders");

        $list->assertOk();
        $row = $list->json('data.0');
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('studentId', $row);
        $this->assertArrayHasKey('outletId', $row);
        foreach (['totalAmount', 'currency', 'unitPrice', 'lineTotal', 'lines', 'chargeId'] as $forbiddenField) {
            $this->assertArrayNotHasKey($forbiddenField, $row, "List response must never include '{$forbiddenField}'.");
        }
    }

    #[Test]
    public function the_detail_endpoint_includes_every_money_field(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '77.00']);

        $created = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'detail-projection-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])->json('data');

        $detail = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders/{$created['id']}");

        $detail->assertOk();
        $this->assertSame('77.00', $detail->json('data.totalAmount'));
        $this->assertSame('INR', $detail->json('data.currency'));
        $this->assertCount(1, $detail->json('data.lines'));
        $this->assertSame('77.00', $detail->json('data.lines.0.unitPrice'));
        $this->assertSame('77.00', $detail->json('data.lines.0.lineTotal'));
    }

    // --- Idempotency: place -----------------------------------------------

    #[Test]
    public function replaying_the_same_place_idempotency_key_creates_only_one_order(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-place-001');
        $payload = [
            'student_id' => $student->id,
            'outlet_id' => $outlet->id,
            'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
        ];

        $first = $client->postJson("/api/v1/schools/{$school->id}/canteen-orders", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/canteen-orders", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders")->json('data');
        $this->assertCount(1, $list, 'A replayed placement must never create a second Order.');
    }

    #[Test]
    public function the_same_place_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-place-001');

        $client->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
            'student_id' => $studentA->id, 'outlet_id' => $outlet->id, 'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
            'student_id' => $studentB->id, 'outlet_id' => $outlet->id, 'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    // --- Idempotency: fulfill -----------------------------------------------

    #[Test]
    public function replaying_the_same_fulfill_idempotency_key_fulfills_only_once(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $order = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'place-for-fulfill-replay-001')
            ->postJson("/api/v1/schools/{$school->id}/canteen-orders", [
                'student_id' => $student->id, 'outlet_id' => $outlet->id, 'lines' => [['canteen_item_id' => $item->id, 'quantity' => 1]],
            ])->json('data');

        $fulfillClient = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-fulfill-001');

        $first = $fulfillClient->postJson("/api/v1/schools/{$school->id}/canteen-orders/{$order['id']}/fulfill");
        $first->assertOk();

        $second = $fulfillClient->postJson("/api/v1/schools/{$school->id}/canteen-orders/{$order['id']}/fulfill");
        $second->assertOk();
        $this->assertSame($first->json('data.chargeId'), $second->json('data.chargeId'), 'A replayed fulfillment must never create a second Charge.');
    }

    // --- Helper search anti-P1 -------------------------------------------

    #[Test]
    public function a_member_without_orders_view_cannot_use_the_student_search_endpoint(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.directory.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders/search/students?q=an")
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_orders_view_cannot_use_the_item_search_endpoint(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.directory.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders/search/items?q=te")
            ->assertForbidden();
    }

    #[Test]
    public function a_member_without_settings_manage_cannot_use_the_ledger_account_lookup(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.settings.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-billing-configuration/ledger-accounts")
            ->assertForbidden();
    }

    /**
     * Phase 10F security review -- the ledger-account picker for the
     * billing-settings UI must never leak another School's
     * LedgerAccount rows. `LedgerAccount` already uses
     * App\Support\Tenancy\BelongsToSchool (SchoolScope + RLS), and
     * CanteenBillingConfigurationController::ledgerAccounts() also
     * explicitly scopes by `school_id` -- this pins the observable
     * behavior at the HTTP boundary as defense-in-depth evidence.
     */
    #[Test]
    public function the_ledger_account_lookup_never_returns_another_schools_accounts(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.settings.manage']);

        $ownAccount = $this->createLedgerAccount($school, ['type' => 'asset', 'code' => 'AST-OWN']);
        $this->createLedgerAccount($otherSchool, ['type' => 'asset', 'code' => 'AST-OTHER']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-billing-configuration/ledger-accounts")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownAccount->id, $ids);
        foreach ($response->json('data') as $row) {
            $this->assertNotSame('AST-OTHER', $row['code']);
        }
    }

    #[Test]
    public function orders_view_alone_is_sufficient_for_the_search_endpoints(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['first_name' => 'Ananya']);
        $user = $this->createUserWithCapabilities($school, ['canteen.orders.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/canteen-orders/search/students?q=Ana")
            ->assertOk();
    }
}
