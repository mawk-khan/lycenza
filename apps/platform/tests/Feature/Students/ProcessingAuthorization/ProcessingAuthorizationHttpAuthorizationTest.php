<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §41 -- the first genuine production route composing
 * `capability:students.processing_authorizations.{view|manage}` with
 * `mfa`, not a demonstration route. Proves both gates independently:
 * missing capability denies regardless of MFA; missing/expired MFA
 * assurance denies regardless of capability; both present allows.
 */
class ProcessingAuthorizationHttpAuthorizationTest extends TestCase
{
    use CreatesMfaFixtures;
    use CreatesProcessingAuthorizationFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function reading_without_the_view_capability_is_denied_even_with_valid_mfa(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $user = $this->createUser();
        $this->createMembership($user, $school); // no role assigned -- no capabilities at all
        $this->enrollActiveMfaFactor($user);
        $this->activate($user, $school);

        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get("/app/students/{$student->id}/processing-authorizations/")->assertStatus(403);
    }

    #[Test]
    public function reading_with_the_view_capability_but_no_mfa_assurance_is_denied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school); // school_admin -> has the capability
        $this->enrollActiveMfaFactor($actor);
        $this->activate($actor, $school);

        // No mfa_verified_at set at all.
        $this->get("/app/students/{$student->id}/processing-authorizations/")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'mfa_step_up_required');
    }

    #[Test]
    public function reading_with_capability_and_valid_mfa_succeeds(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $this->enrollActiveMfaFactor($actor);
        $this->activate($actor, $school);

        session(['mfa_verified_at' => now()->toIso8601String()]);
        $response = $this->get("/app/students/{$student->id}/processing-authorizations/");

        $response->assertOk();
        $response->assertJsonPath('authorized', false);
    }

    #[Test]
    public function an_unenrolled_actor_is_denied_with_the_not_enrolled_code(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school); // capability present, no MFA factor at all
        $this->activate($actor, $school);

        $this->get("/app/students/{$student->id}/processing-authorizations/")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'mfa_required_not_enrolled');
    }

    #[Test]
    public function recording_an_authorization_without_the_manage_capability_is_denied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $bareUser = $this->createUser();
        $this->createMembership($bareUser, $school); // no role assigned
        $this->enrollActiveMfaFactor($bareUser);
        $this->activate($bareUser, $school);

        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->postJson("/app/students/{$student->id}/processing-authorizations/", [
            'basis_type' => 'statutory_school_purpose',
        ])->assertStatus(403);
    }

    #[Test]
    public function recording_an_authorization_with_capability_and_mfa_succeeds(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['date_of_birth' => '2000-01-01']);
        $actor = $this->staffActor($school);
        $this->enrollActiveMfaFactor($actor);
        $this->activate($actor, $school);

        session(['mfa_verified_at' => now()->toIso8601String()]);
        $response = $this->postJson("/app/students/{$student->id}/processing-authorizations/", [
            'basis_type' => 'statutory_school_purpose',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('basisType', 'statutory_school_purpose');

        $this->assertTrue(
            app(StudentProcessingAuthorizationReadService::class)
                ->isAuthorizedForProcessing($school, $student, ProcessingAuthorizationPurpose::AcademicRecords),
        );
    }
}
