<?php

namespace Tests\Feature\Library;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A -- the /api/v1 Library catalogue + circulation surface,
 * including the required idempotency proof for checkout (checkpoint
 * brief section 19/23). Mirrors CampusApiTest.php's exact pattern.
 */
class LibraryApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    // --- Catalogue -----------------------------------------------------

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/library-titles")->assertUnauthorized();
    }

    #[Test]
    public function school_admin_can_create_a_title_a_copy_and_view_both(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $title = $client->postJson("/api/v1/schools/{$school->id}/library-titles", [
            'title' => 'The Hobbit', 'author' => 'J.R.R. Tolkien',
        ]);
        $title->assertCreated();
        $titleId = $title->json('data.id');

        $copy = $client->postJson("/api/v1/schools/{$school->id}/library-titles/{$titleId}/copies", [
            'code' => 'LIB-0001',
        ]);
        $copy->assertCreated();
        $this->assertTrue($copy->json('data.available'));

        $client->getJson("/api/v1/schools/{$school->id}/library-titles/{$titleId}")->assertOk();
        $client->getJson("/api/v1/schools/{$school->id}/library-titles/{$titleId}/copies")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function catalogue_creation_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/library-titles", ['author' => 'No title given']);

        // This project's /api/* surface nests validation errors under
        // its own custom envelope (docs/architecture/API.md
        // "Error format": {"error": {..., "errors": {...}}}), not
        // Laravel's default top-level `errors` key --
        // assertJsonValidationErrors() does not apply here.
        $response->assertStatus(422);
        $this->assertArrayHasKey('title', $response->json('error.errors'));
    }

    #[Test]
    public function a_member_without_catalogue_manage_cannot_create_a_title(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.catalogue.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/library-titles", ['title' => 'Denied'])
            ->assertForbidden();
    }

    #[Test]
    public function school_a_cannot_see_school_bs_title(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $titleB = $this->createLibraryTitle($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/library-titles/{$titleB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function two_copies_of_the_same_title_may_share_no_code_collision_but_reject_a_duplicate(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $title = $this->createLibraryTitle($school);

        $client->postJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}/copies", ['code' => 'DUP-1'])->assertCreated();

        $response = $client->postJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}/copies", ['code' => 'dup-1']);
        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('error.errors'));
    }

    // --- Circulation -----------------------------------------------------

    #[Test]
    public function school_admin_can_check_out_and_check_in(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $checkout = $client->withHeader('Idempotency-Key', 'checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id,
                'student_id' => $student->id,
                'due_at' => now()->addDays(14)->toIso8601String(),
            ]);
        $checkout->assertCreated();
        $this->assertSame('active', $checkout->json('data.status'));

        $loanId = $checkout->json('data.id');

        $client->getJson("/api/v1/schools/{$school->id}/library-loans")->assertOk()->assertJsonCount(1, 'data');

        $checkIn = $client->postJson("/api/v1/schools/{$school->id}/library-loans/{$loanId}/check-in");
        $checkIn->assertOk();
        $this->assertSame('returned', $checkIn->json('data.status'));
    }

    #[Test]
    public function checkout_rejects_an_invalid_payload(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'invalid-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", []);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        foreach (['library_copy_id', 'student_id', 'due_at'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function a_member_without_circulation_manage_cannot_check_out(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.circulation.view']);
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'denied-checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id,
                'student_id' => $student->id,
                'due_at' => now()->addDays(14)->toIso8601String(),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function checking_out_a_student_from_a_different_school_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);

        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool, ['status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'cross-school-checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id,
                'student_id' => $foreignStudent->id,
                'due_at' => now()->addDays(14)->toIso8601String(),
            ])
            ->assertNotFound();
    }

    #[Test]
    public function checking_out_an_already_loaned_copy_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);

        $client->withHeader('Idempotency-Key', 'first-checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id, 'student_id' => $studentOne->id, 'due_at' => now()->addDays(14)->toIso8601String(),
            ])->assertCreated();

        $client->withHeader('Idempotency-Key', 'second-checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id, 'student_id' => $studentTwo->id, 'due_at' => now()->addDays(14)->toIso8601String(),
            ])->assertStatus(422)->assertJsonPath('error.code', 'LIBRARY_COPY_NOT_AVAILABLE');
    }

    #[Test]
    public function checking_in_an_already_returned_loan_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $loan = $this->createLibraryLoan($copy, $student, ['status' => 'returned', 'checked_out_at' => now()->subDay(), 'due_at' => now()->addDays(13), 'checked_in_at' => now()]);

        $client->postJson("/api/v1/schools/{$school->id}/library-loans/{$loan->id}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIBRARY_LOAN_ALREADY_RETURNED');
    }

    // --- Idempotency (checkout specifically) --------------------------------

    #[Test]
    public function replaying_the_same_checkout_idempotency_key_creates_only_one_loan(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'replay-checkout-001');
        $payload = [
            'library_copy_id' => $copy->id,
            'student_id' => $student->id,
            'due_at' => now()->addDays(14)->toIso8601String(),
        ];

        $first = $client->postJson("/api/v1/schools/{$school->id}/library-loans", $payload);
        $first->assertCreated();

        $second = $client->postJson("/api/v1/schools/{$school->id}/library-loans", $payload);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        $count = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/library-loans")
            ->json('data');
        $this->assertCount(1, $count, 'A replayed checkout must never create a second Loan.');
    }

    #[Test]
    public function the_same_checkout_key_with_a_different_payload_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copyOne = $this->createLibraryCopy($title);
        $copyTwo = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'conflict-checkout-001');

        $client->postJson("/api/v1/schools/{$school->id}/library-loans", [
            'library_copy_id' => $copyOne->id, 'student_id' => $student->id, 'due_at' => now()->addDays(14)->toIso8601String(),
        ])->assertCreated();

        $conflict = $client->postJson("/api/v1/schools/{$school->id}/library-loans", [
            'library_copy_id' => $copyTwo->id, 'student_id' => $student->id, 'due_at' => now()->addDays(14)->toIso8601String(),
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $conflict->json('error.code'));
    }

    #[Test]
    public function a_checkout_replay_after_losing_the_manage_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'revoked-checkout-001');
        $payload = [
            'library_copy_id' => $copy->id,
            'student_id' => $student->id,
            'due_at' => now()->addDays(14)->toIso8601String(),
        ];

        $client->postJson("/api/v1/schools/{$school->id}/library-loans", $payload)->assertCreated();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $client->postJson("/api/v1/schools/{$school->id}/library-loans", $payload)->assertUnauthorized();
    }
}
