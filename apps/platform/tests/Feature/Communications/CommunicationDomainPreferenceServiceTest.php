<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.2 §11/§27/§46/§66 -- the Guardian/Student domain
 * preference write/read path: absence-means-default, one deterministic
 * current row per (school, identity, channel), never scoped to a
 * GuardianContact row, batched reads.
 */
class CommunicationDomainPreferenceServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): CommunicationDomainPreferenceService
    {
        return app(CommunicationDomainPreferenceService::class);
    }

    #[Test]
    public function no_row_means_no_explicit_preference(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $preference = $this->service()->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);

        $this->assertNull($preference);
    }

    #[Test]
    public function setting_a_preference_persists_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $this->service()->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, false);

        $preference = $this->service()->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertNotNull($preference);
        $this->assertFalse($preference->isEnabled());
    }

    #[Test]
    public function setting_a_preference_twice_updates_the_same_row(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $service = $this->service();

        $first = $service->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, false);
        $second = $service->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, true);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->isEnabled());
    }

    #[Test]
    public function a_guardian_shared_by_two_students_has_one_preference_governing_both(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $this->createStudentGuardianRelationship($studentA, $guardian, ['is_primary' => true]);
        $this->createStudentGuardianRelationship($studentB, $guardian, ['is_primary' => true]);

        $this->service()->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, false);

        // §48: one logical Guardian, one preference row regardless of
        // how many Students they're associated with.
        $preference = $this->service()->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertFalse($preference->isEnabled());
    }

    #[Test]
    public function preference_survives_a_guardian_contact_change(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'old@example.com', ['is_primary' => true]);

        $this->service()->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, false);

        // Simulate a new primary contact replacing the old one -- §46:
        // preference is scoped to the Guardian/channel, not the contact
        // row. Goes through the real service (not the raw fixture
        // helper): create() then setPrimary() properly demotes the old
        // primary in the same transaction (GuardianContactService's own
        // documented pattern).
        $contactService = app(GuardianContactService::class);
        $newContact = $contactService->create($guardian, ContactType::Email, 'new@example.com', [], $admin);
        $contactService->setPrimary($newContact, $admin);

        $preference = $this->service()->currentPreferenceForGuardian($school, $guardian, CommunicationChannel::Email);
        $this->assertFalse($preference->isEnabled(), 'Opt-out must remain effective after a contact change.');
    }

    #[Test]
    public function batch_preference_lookup_for_many_guardians_uses_a_bounded_query_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardianIds = [];
        for ($i = 0; $i < 20; $i++) {
            $guardian = $this->createGuardian($school);
            $this->service()->setPreferenceForGuardian($school, $guardian, $admin, CommunicationChannel::Email, $i % 2 === 0);
            $guardianIds[] = $guardian->id;
        }

        DB::enableQueryLog();
        $preferences = $this->service()->currentPreferencesForGuardians($school, $guardianIds, CommunicationChannel::Email);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $preferences);
        $this->assertLessThan(5, $queryCount, 'Batch preference lookup must be ONE query, not one per Guardian.');
    }

    #[Test]
    public function a_student_domain_preference_is_independent_of_any_guardian_preference(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);

        $this->service()->setPreferenceForStudent($school, $student, $admin, CommunicationChannel::Email, false);

        $preference = $this->service()->currentPreferenceForStudent($school, $student, CommunicationChannel::Email);
        $this->assertFalse($preference->isEnabled());
    }
}
