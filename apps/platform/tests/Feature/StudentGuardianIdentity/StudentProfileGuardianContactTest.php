<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-1 (ADR 0076 §45, finding F-1): a Guardian's contact VALUE is
 * Guardian contact information -- `guardians.view` -- never `students.view`
 * alone. The Student Profile used to decrypt and send it to any Student
 * reader; it now does so only for a `guardians.view` holder, and only an
 * ACTIVE contact is ever chosen. Enforced in the server payload, not the UI.
 */
class StudentProfileGuardianContactTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PRIMARY_MOBILE = '+919876543210';

    private const ACTIVE_EMAIL = 'parent.contact@example.test';

    private School $school;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->student = $this->createStudent($this->school, ['student_number' => 'S-2001']);
        $guardian = $this->createGuardian($this->school, ['first_name' => 'Fatima', 'last_name' => 'Sharma']);
        $this->createStudentGuardianRelationship($this->student, $guardian, ['relationship_type' => 'mother', 'is_emergency_contact' => true]);
        $mobile = $this->createGuardianContact($guardian, ContactType::Mobile, self::PRIMARY_MOBILE, ['is_primary' => true]);
        $this->createGuardianContact($guardian, ContactType::Email, self::ACTIVE_EMAIL);
        // The primary mobile is later deactivated: it must never be chosen.
        app(TenantContext::class)->withSchool($this->school, fn () => GuardianContact::query()->whereKey($mobile->id)->update(['is_active' => false]));
    }

    /** @param  list<string>  $capabilities */
    private function holderOf(array $capabilities): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        app(TenantContext::class)->withSchool($this->school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $this->school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));

        return $user;
    }

    private function profileAs(User $user): TestResponse
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->actingAs($user)->get("/app/students/{$this->student->id}");
    }

    #[Test]
    public function students_view_alone_never_receives_a_guardian_contact_value(): void
    {
        $response = $this->profileAs($this->holderOf(['students.view']))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('App/Students/Show')
            ->where('canViewGuardianContacts', false)
            ->has('relationships', 1)
            // The relationship itself stays visible (documented, Phase 1A.6).
            ->where('relationships.0.guardian.firstName', 'Fatima')
            ->where('relationships.0.isEmergencyContact', true)
            ->where('relationships.0.contact', null)
        );
        // Not merely hidden: the values are absent from the whole response.
        $response->assertDontSee(self::PRIMARY_MOBILE, false);
        $response->assertDontSee(self::ACTIVE_EMAIL, false);
    }

    #[Test]
    public function a_guardians_view_holder_sees_only_an_active_contact(): void
    {
        $response = $this->profileAs($this->holderOf(['students.view', 'guardians.view']))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('canViewGuardianContacts', true)
            ->where('relationships.0.contact.type', 'email')
            ->where('relationships.0.contact.value', self::ACTIVE_EMAIL)
        );
        $response->assertDontSee(self::PRIMARY_MOBILE, false);
    }

    #[Test]
    public function guardians_view_alone_does_not_open_the_student_profile(): void
    {
        $this->profileAs($this->holderOf(['guardians.view']))->assertForbidden();
    }

    #[Test]
    public function the_api_student_guardian_list_carries_no_contact_value(): void
    {
        $user = $this->holderOf(['students.view']);
        app('auth')->forgetGuards();
        $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->getJson("/api/v1/schools/{$this->school->id}/students/{$this->student->id}/guardians")
            ->assertOk();

        $this->assertSame(['firstName', 'id', 'lastName', 'middleName', 'status'], collect($response->json('data.0.guardian'))->keys()->sort()->values()->all());
        $response->assertDontSee(self::PRIMARY_MOBILE, false);
        $response->assertDontSee(self::ACTIVE_EMAIL, false);
    }

    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }
}
