<?php

namespace Tests\Feature\Fees;

use App\Models\Role;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.6: HTTP transport for `ChargeController` -- thin controller
 * over `ChargeReadService`/`ChargeAdministrationService`, never
 * `ChargeService` directly. No `update`/`delete` route exists at all --
 * a recognized Charge's amount/Student/AcademicYear/account mapping
 * remain structurally immutable.
 */
class ChargeApiTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function viewerRole(array $capabilities): Role
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'charges-viewer-'.uniqid(), 'name' => 'Charges Viewer', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));

        return $role;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();
        $this->getJson("/api/v1/schools/{$school->id}/charges")->assertUnauthorized();
    }

    #[Test]
    public function view_only_can_read_but_not_assess_or_cancel(): void
    {
        $role = $this->viewerRole(['finance.charges.view']);
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $client->getJson("/api/v1/schools/{$school->id}/charges")->assertOk();
        $client->getJson("/api/v1/schools/{$school->id}/charges/{$charge->id}")->assertOk();

        $client->postJson("/api/v1/schools/{$school->id}/charges", [
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'description' => 'x',
            'amount' => '100.00', 'currency' => 'INR',
            'receivable_ledger_account_id' => $receivable->id, 'revenue_ledger_account_id' => $revenue->id,
        ])->assertForbidden();

        $client->postJson("/api/v1/schools/{$school->id}/charges/{$charge->id}/cancel")->assertForbidden();
    }

    #[Test]
    public function assessing_a_charge_succeeds_with_exact_decimal_and_never_exposes_a_raw_model(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/charges", [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'description' => 'Term 1 tuition',
                'amount' => '1000.00',
                'currency' => 'INR',
                'receivable_ledger_account_id' => $receivable->id,
                'revenue_ledger_account_id' => $revenue->id,
            ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('1000.00', $data['amount']);
        $this->assertArrayNotHasKey('posting_txid', $data);
        $this->assertArrayNotHasKey('creation_txid', $data);
    }

    #[Test]
    public function zero_negative_and_non_inr_amounts_are_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        foreach (['0.00', '-50.00'] as $badAmount) {
            $client->postJson("/api/v1/schools/{$school->id}/charges", [
                'student_id' => $student->id, 'academic_year_id' => $year->id, 'description' => 'x',
                'amount' => $badAmount, 'currency' => 'INR',
                'receivable_ledger_account_id' => $receivable->id, 'revenue_ledger_account_id' => $revenue->id,
            ])->assertStatus(422);
        }

        $client->postJson("/api/v1/schools/{$school->id}/charges", [
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'description' => 'x',
            'amount' => '100.00', 'currency' => 'USD',
            'receivable_ledger_account_id' => $receivable->id, 'revenue_ledger_account_id' => $revenue->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_cross_school_student_produces_a_clean_error(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearA = $this->createAcademicYear($schoolA);
        $receivableA = $this->createLedgerAccount($schoolA, ['type' => 'asset']);
        $revenueA = $this->createLedgerAccount($schoolA, ['type' => 'income']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$schoolA->id}/charges", [
                'student_id' => $studentB->id, 'academic_year_id' => $yearA->id, 'description' => 'x',
                'amount' => '100.00', 'currency' => 'INR',
                'receivable_ledger_account_id' => $receivableA->id, 'revenue_ledger_account_id' => $revenueA->id,
            ]);

        $response->assertStatus(404);
        $this->assertSame('STUDENT_NOT_FOUND', $response->json('error.code'));
    }

    #[Test]
    public function cancelling_a_charge_succeeds_once_and_a_repeat_is_a_stable_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $cancel = $client->postJson("/api/v1/schools/{$school->id}/charges/{$charge->id}/cancel");
        $cancel->assertOk();
        $this->assertNotNull($cancel->json('data.cancelledAt'));

        $repeat = $client->postJson("/api/v1/schools/{$school->id}/charges/{$charge->id}/cancel");
        $repeat->assertStatus(409);
        $this->assertSame('CHARGE_ALREADY_CANCELLED', $repeat->json('error.code'));
    }

    #[Test]
    public function a_charge_with_a_payment_allocation_cannot_be_cancelled_via_http(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '500.00']], '500.00');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/charges/{$charge->id}/cancel");

        $response->assertStatus(409);
        $this->assertSame('CHARGE_HAS_PAYMENT_ALLOCATIONS', $response->json('error.code'));
    }

    #[Test]
    public function a_cross_school_charge_id_is_not_found_never_403(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB, '100.00');

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($userA));
        $client->getJson("/api/v1/schools/{$schoolA->id}/charges/{$chargeB->id}")->assertNotFound();
        $client->postJson("/api/v1/schools/{$schoolA->id}/charges/{$chargeB->id}/cancel")->assertNotFound();
    }

    #[Test]
    public function charge_list_is_bounded_and_paginated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        for ($i = 0; $i < 3; $i++) {
            $this->assessCharge($school, $student, $year, $receivable, $revenue, '10.00');
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/charges?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
