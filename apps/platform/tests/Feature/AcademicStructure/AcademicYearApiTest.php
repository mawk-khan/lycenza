<?php

namespace Tests\Feature\AcademicStructure;

use App\Models\Role;
use App\Models\SchoolMembership;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D section 83 (mandatory matrix), applied to the Academic Year
 * API as the representative, most lifecycle-rich endpoint.
 */
class AcademicYearApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/academic-years")->assertUnauthorized();
    }

    #[Test]
    public function a_member_without_the_view_capability_is_denied(): void
    {
        $role = Role::query()->create(['key' => 'no-academics-'.uniqid(), 'name' => 'No Academics', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync([]);

        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/academic-years")
            ->assertForbidden();
    }

    #[Test]
    public function the_view_capability_alone_allows_reads_but_not_writes(): void
    {
        $role = Role::query()->create(['key' => 'academics-viewer-'.uniqid(), 'name' => 'Academics Viewer', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['academics.years.view']);

        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->getJson("/api/v1/schools/{$school->id}/academic-years")->assertOk();
        $client->withHeader('Idempotency-Key', 'view-cannot-mutate-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years", [
                'name' => '2026-27', 'code' => 'AY2026', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
            ])->assertForbidden();
    }

    #[Test]
    public function a_disabled_user_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $user->forceFill(['is_disabled' => true])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/academic-years")
            ->assertForbidden();
    }

    #[Test]
    public function a_suspended_membership_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->update(['status' => 'suspended']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/academic-years")
            ->assertNotFound();
    }

    #[Test]
    public function school_admin_can_create_activate_and_close_an_academic_year(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->withHeader('Idempotency-Key', 'create-year-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years", [
                'name' => '2026-27', 'code' => 'AY2026', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
            ]);
        $create->assertCreated();
        $yearId = $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));

        $activate = $client->withHeader('Idempotency-Key', 'activate-year-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$yearId}/activate");
        $activate->assertOk();
        $this->assertSame('active', $activate->json('data.status'));

        $close = $client->withHeader('Idempotency-Key', 'close-year-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$yearId}/close");
        $close->assertOk();
        $this->assertSame('closed', $close->json('data.status'));
    }

    #[Test]
    public function school_a_cannot_see_or_activate_school_bs_academic_year(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $yearB = $this->createAcademicYear($schoolB);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($userA));

        // Cross-School UUID under School A's own URL -> 404, never a
        // 403 (section 83's "cross-School UUID -> 404" requirement).
        $client->getJson("/api/v1/schools/{$schoolA->id}/academic-years/{$yearB->id}")->assertNotFound();
        $client->withHeader('Idempotency-Key', 'cross-school-activate-001')
            ->postJson("/api/v1/schools/{$schoolA->id}/academic-years/{$yearB->id}/activate")
            ->assertNotFound();
    }

    #[Test]
    public function overlapping_academic_years_are_rejected_with_a_clean_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createAcademicYear($school, ['code' => 'AY1', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'overlap-attempt-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years", [
                'name' => 'Overlap', 'code' => 'AY2',
                'starts_on' => '2026-12-01', 'ends_on' => '2027-12-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('ACADEMIC_YEAR_OVERLAP', $response->json('error.code'));
    }
}
