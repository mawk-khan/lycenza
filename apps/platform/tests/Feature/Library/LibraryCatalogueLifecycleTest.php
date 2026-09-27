<?php

namespace Tests\Feature\Library;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A -- catalogue (Title/Copy) lifecycle: update, deactivate/
 * reactivate (rule 73's active/inactive convention, no delete
 * endpoint), and that a historical Loan referencing a deactivated
 * Copy/Title remains fully valid.
 */
class LibraryCatalogueLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_title_can_be_updated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school, ['title' => 'Original Title']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}", ['title' => 'Updated Title']);

        $response->assertOk();
        $this->assertSame('Updated Title', $response->json('data.title'));
    }

    #[Test]
    public function a_title_can_be_deactivated_and_reactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $deactivate = $client->patchJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}", ['status' => 'inactive']);
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $reactivate = $client->patchJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}", ['status' => 'active']);
        $reactivate->assertOk();
        $this->assertSame('active', $reactivate->json('data.status'));
    }

    #[Test]
    public function an_inactive_title_is_excluded_from_the_default_listing_but_included_with_include_inactive(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createLibraryTitle($school, ['title' => 'Active One', 'status' => 'active']);
        $this->createLibraryTitle($school, ['title' => 'Inactive One', 'status' => 'inactive']);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $default = $client->getJson("/api/v1/schools/{$school->id}/library-titles");
        $default->assertJsonCount(1, 'data');

        $withInactive = $client->getJson("/api/v1/schools/{$school->id}/library-titles?include_inactive=1");
        $withInactive->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_copy_can_be_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson("/api/v1/schools/{$school->id}/library-copies/{$copy->id}", ['status' => 'inactive']);

        $response->assertOk();
        $this->assertSame('inactive', $response->json('data.status'));
        $this->assertFalse($response->json('data.available'));
    }

    #[Test]
    public function an_inactive_copy_cannot_be_checked_out(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title, ['status' => 'inactive']);
        $student = $this->createStudent($school, ['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'inactive-copy-checkout-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copy->id,
                'student_id' => $student->id,
                'due_at' => now()->addDays(14)->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $this->assertSame('LIBRARY_COPY_NOT_AVAILABLE', $response->json('error.code'));
    }

    #[Test]
    public function a_historical_loan_survives_the_referenced_title_and_copy_being_deactivated(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $loan = $this->createLibraryLoan($copy, $student, ['status' => 'returned', 'checked_out_at' => now()->subDay(), 'due_at' => now()->addDays(13), 'checked_in_at' => now()]);
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->patchJson("/api/v1/schools/{$school->id}/library-titles/{$title->id}", ['status' => 'inactive'])->assertOk();
        $client->patchJson("/api/v1/schools/{$school->id}/library-copies/{$copy->id}", ['status' => 'inactive'])->assertOk();

        $client->getJson("/api/v1/schools/{$school->id}/library-loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.copy.id', $copy->id);
    }
}
