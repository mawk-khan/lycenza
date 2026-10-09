<?php

namespace Tests\Feature\Finance;

use App\Models\Role;
use App\Models\SchoolMembership;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.6: HTTP transport for `LedgerAccountController`/
 * `JournalEntryController` -- thin controllers over
 * `LedgerReadService`/`LedgerAdministrationService`, never
 * `LedgerService` directly. Mirrors
 * `Tests\Feature\AcademicStructure\AcademicYearApiTest`'s exact
 * authentication/authorization/cross-School test matrix shape.
 */
class JournalEntryApiTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function viewerRole(array $capabilities): Role
    {
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'finance-viewer-'.uniqid(), 'name' => 'Finance Viewer', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));

        return $role;
    }

    #[Test]
    public function a_guest_is_denied_every_finance_route(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/ledger-accounts")->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/journal-entries")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/journal-entries", [])->assertUnauthorized();
    }

    #[Test]
    public function a_member_without_finance_ledger_view_is_denied(): void
    {
        // SR.1: an empty role is never grantable -- a role holding only an unrelated capability.
        $role = $this->viewerRole(['students.view']);
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/journal-entries")
            ->assertForbidden();
    }

    #[Test]
    public function view_only_can_read_but_not_post_or_reverse(): void
    {
        $role = $this->viewerRole(['finance.ledger.view']);
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '100.00');

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->getJson("/api/v1/schools/{$school->id}/ledger-accounts")->assertOk();
        $client->getJson("/api/v1/schools/{$school->id}/journal-entries")->assertOk();
        $client->getJson("/api/v1/schools/{$school->id}/journal-entries/{$entry->id}")->assertOk();

        $client->postJson("/api/v1/schools/{$school->id}/journal-entries", [
            'currency' => 'INR', 'description' => 'x',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '10.00'],
                ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertForbidden();

        $client->postJson("/api/v1/schools/{$school->id}/journal-entries/{$entry->id}/reverse")->assertForbidden();
    }

    #[Test]
    public function post_only_cannot_reverse(): void
    {
        $role = $this->viewerRole(['finance.ledger.view', 'finance.ledger.post']);
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '100.00');

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/journal-entries/{$entry->id}/reverse")
            ->assertForbidden();
    }

    #[Test]
    public function a_non_member_is_denied(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/journal-entries")
            ->assertNotFound();
    }

    #[Test]
    public function posting_a_balanced_exact_decimal_entry_succeeds_and_never_returns_posting_txid_or_raw_model(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/journal-entries", [
                'currency' => 'INR',
                'description' => 'Fee collection',
                'lines' => [
                    ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '1000.00'],
                    ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '1000.00'],
                ],
            ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame(['id', 'currency', 'description', 'postedAt', 'reversalOfJournalEntryId', 'lineCount'], array_keys($data));
        $this->assertSame(2, $data['lineCount']);
        $this->assertArrayNotHasKey('posting_txid', $data);
        $this->assertArrayNotHasKey('postingTxid', $data);

        $detail = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/journal-entries/{$data['id']}");
        $detail->assertOk();
        $this->assertSame('1000.00', $detail->json('data.lines.0.amount'));
        $this->assertArrayNotHasKey('posting_txid', $detail->json('data'));
    }

    #[Test]
    public function an_unbalanced_entry_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/journal-entries", [
                'currency' => 'INR', 'description' => 'x',
                'lines' => [
                    ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '100.00'],
                    ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '80.00'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame('UNBALANCED_JOURNAL_ENTRY', $response->json('error.code'));
    }

    #[Test]
    public function a_single_line_entry_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/journal-entries", [
                'currency' => 'INR', 'description' => 'x',
                'lines' => [['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '100.00']],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function zero_negative_float_shaped_and_non_inr_amounts_are_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        foreach (['0.00', '-100.00', '1e3', 'NaN', '100.999'] as $badAmount) {
            $client->postJson("/api/v1/schools/{$school->id}/journal-entries", [
                'currency' => 'INR', 'description' => 'x',
                'lines' => [
                    ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => $badAmount],
                    ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => $badAmount],
                ],
            ])->assertStatus(422);
        }

        $client->postJson("/api/v1/schools/{$school->id}/journal-entries", [
            'currency' => 'USD', 'description' => 'x',
            'lines' => [
                ['ledger_account_id' => $cash->id, 'side' => 'debit', 'amount' => '10.00'],
                ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_cross_school_ledger_account_produces_a_clean_error_never_a_500(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $foreignAccount = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $income = $this->createLedgerAccount($schoolA, ['type' => 'income']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$schoolA->id}/journal-entries", [
                'currency' => 'INR', 'description' => 'x',
                'lines' => [
                    ['ledger_account_id' => $foreignAccount->id, 'side' => 'debit', 'amount' => '10.00'],
                    ['ledger_account_id' => $income->id, 'side' => 'credit', 'amount' => '10.00'],
                ],
            ]);

        $response->assertStatus(404);
        $this->assertSame('LEDGER_ACCOUNT_NOT_FOUND', $response->json('error.code'));
    }

    #[Test]
    public function reversal_creates_exactly_one_reversal_and_a_repeat_is_a_stable_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '500.00');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $reversal = $client->postJson("/api/v1/schools/{$school->id}/journal-entries/{$entry->id}/reverse");
        $reversal->assertCreated();
        $this->assertSame($entry->id, $reversal->json('data.reversalOfJournalEntryId'));
        $this->assertArrayNotHasKey('posting_txid', $reversal->json('data'));

        $repeat = $client->postJson("/api/v1/schools/{$school->id}/journal-entries/{$entry->id}/reverse");
        $repeat->assertStatus(409);
        $this->assertSame('JOURNAL_ENTRY_ALREADY_REVERSED', $repeat->json('error.code'));
    }

    #[Test]
    public function a_cross_school_journal_entry_id_is_not_found_never_403(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $cashB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $incomeB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $entryB = $this->postBalancedJournalEntry($schoolB, $cashB, $incomeB, '100.00');

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($userA));

        $client->getJson("/api/v1/schools/{$schoolA->id}/journal-entries/{$entryB->id}")->assertNotFound();
        $client->postJson("/api/v1/schools/{$schoolA->id}/journal-entries/{$entryB->id}/reverse")->assertNotFound();
    }

    #[Test]
    public function a_disabled_user_and_a_suspended_membership_are_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $user->forceFill(['is_disabled' => true])->save();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/journal-entries")
            ->assertUnauthorized();

        [$user2, $school2] = $this->createSchoolAdmin('school_admin');
        SchoolMembership::query()->where('user_id', $user2->id)->where('school_id', $school2->id)->update(['status' => 'suspended']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user2))
            ->getJson("/api/v1/schools/{$school2->id}/journal-entries")
            ->assertNotFound();
    }

    #[Test]
    public function journal_entry_list_is_paginated_using_the_read_services_own_bounds(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        for ($i = 0; $i < 3; $i++) {
            $this->postBalancedJournalEntry($school, $cash, $income, '10.00');
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/journal-entries?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(['page', 'perPage', 'total'], array_keys($response->json('meta')));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
