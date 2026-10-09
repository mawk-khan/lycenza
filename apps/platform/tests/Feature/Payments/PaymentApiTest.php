<?php

namespace Tests\Feature\Payments;

use App\Models\Role;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.6: HTTP transport for `PaymentController` -- READ ONLY.
 * Proves no human Payment mutation route exists anywhere (rule 52 of
 * the 0G.6 brief: there is no `finance.payments.manage` and none is
 * introduced here) and that the trusted `PaymentProviderEventService`
 * ingestion boundary is not publicly routed.
 */
class PaymentApiTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();
        $this->getJson("/api/v1/schools/{$school->id}/payments")->assertUnauthorized();
    }

    #[Test]
    public function a_member_without_finance_payments_view_is_denied(): void
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'no-payments-'.uniqid(), 'name' => 'No Payments', 'scope' => 'school', 'is_system' => false]));
        // SR.1: an empty role is never grantable -- a role holding only an unrelated capability.
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['students.view']));
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/payments")
            ->assertForbidden();
    }

    #[Test]
    public function a_non_member_is_denied(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/payments")
            ->assertNotFound();
    }

    #[Test]
    public function school_admin_can_list_and_view_payment_detail_with_exact_decimal_and_no_internal_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '250.00');
        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '250.00']], '250.00');

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $list = $client->getJson("/api/v1/schools/{$school->id}/payments");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('250.00', $list->json('data.0.amount'));

        $detail = $client->getJson("/api/v1/schools/{$school->id}/payments/{$result->paymentId}");
        $detail->assertOk();
        $data = $detail->json('data');
        $this->assertSame('250.00', $data['amount']);
        $this->assertSame($charge->id, $data['allocations'][0]['chargeId']);
        $this->assertSame('250.00', $data['allocations'][0]['amount']);

        foreach (['creation_txid', 'creationTxid', 'posting_txid', 'postingTxid', 'rawPayload', 'raw_payload', 'secret', 'signature'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "Payment detail response must never expose '{$forbidden}'.");
        }
    }

    #[Test]
    public function a_cross_school_payment_id_is_not_found_never_403(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $settlementB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB, '100.00');
        $resultB = $this->recordSettlement($schoolB, $settlementB->id, [[$chargeB, '100.00']], '100.00');

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/payments/{$resultB->paymentId}")
            ->assertNotFound();
    }

    #[Test]
    public function no_human_payment_mutation_route_exists(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        // No POST/PATCH/DELETE route is registered for /payments at all
        // -- the URI itself only ever matches a GET route, so the
        // router correctly returns 405 Method Not Allowed (the route
        // exists, the verb does not) rather than a real endpoint merely
        // denying authorization; a URI with no route match at all
        // (a provider-callback-shaped path) returns a genuine 404.
        // Confirms rule 52: there is no finance.payments.manage
        // capability and no route a School Admin -- however privileged
        // -- could use to fabricate a settled Payment.
        $client->postJson("/api/v1/schools/{$school->id}/payments", [])->assertStatus(405);
        $client->patchJson("/api/v1/schools/{$school->id}/payments/anything", [])->assertStatus(405);
        $client->deleteJson("/api/v1/schools/{$school->id}/payments/anything")->assertStatus(405);
        $client->postJson('/api/v1/payments/provider-events', [])->assertStatus(404);
        $client->postJson('/api/v1/payments/webhook', [])->assertStatus(404);
        $client->postJson('/api/v1/provider/callback', [])->assertStatus(404);
    }

    #[Test]
    public function payment_list_is_bounded_and_paginated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        for ($i = 0; $i < 3; $i++) {
            $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '10.00');
            $this->recordSettlement($school, $settlement->id, [[$charge, '10.00']], '10.00');
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/payments?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
