<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.3: Guardian contact information (email/mobile) via the
 * canonical write path, GuardianContactService. See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Searchable PII").
 */
class GuardianContactTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_guardian_can_have_multiple_contacts(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $this->createGuardianContact($guardian, ContactType::Email, 'personal@example.com', ['label' => 'Personal']);
        $this->createGuardianContact($guardian, ContactType::Email, 'work@example.com', ['label' => 'Work']);
        $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210', ['label' => 'Primary mobile']);

        $count = app(TenantContext::class)->withSchool($school, fn () => $guardian->contacts()->count());

        $this->assertSame(3, $count);
    }

    #[Test]
    public function a_valid_email_contact_is_created_and_decrypts_correctly(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $contact = $this->createGuardianContact($guardian, ContactType::Email, '  Parent@Example.COM  ');

        $this->assertSame(ContactType::Email, $contact->type);
        $this->assertSame('parent@example.com', $contact->encrypted_value);
        $this->assertFalse($contact->is_primary);
        $this->assertTrue($contact->is_active);
        $this->assertNull($contact->verified_at);
    }

    #[Test]
    public function a_malformed_email_is_rejected_before_any_row_is_written(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $this->expectException(InvalidArgumentException::class);

        $this->createGuardianContact($guardian, ContactType::Email, 'not-an-email');
    }

    #[Test]
    public function a_valid_mobile_contact_is_created(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $contact = $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');

        $this->assertSame(ContactType::Mobile, $contact->type);
        $this->assertSame('+919876543210', $contact->encrypted_value);
    }

    #[Test]
    public function a_noncanonical_mobile_number_is_rejected(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $this->expectException(InvalidArgumentException::class);

        $this->createGuardianContact($guardian, ContactType::Mobile, '98765 43210');
    }

    #[Test]
    public function a_guardian_may_have_one_primary_email_and_one_primary_mobile(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);

        $email = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com', ['is_primary' => true]);
        $mobile = $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210', ['is_primary' => true]);

        $this->assertTrue($email->is_primary);
        $this->assertTrue($mobile->is_primary);
    }

    #[Test]
    public function a_second_primary_email_for_the_same_guardian_is_rejected(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'first@example.com', ['is_primary' => true]);

        $this->expectException(QueryException::class);

        // GuardianContactService::create() already wraps its own write
        // in DB::transaction() internally, which nests as a SAVEPOINT
        // inside DatabaseTransactions' outer test transaction -- no
        // additional wrapping needed here for the connection to stay
        // healthy afterward, unlike the raw-Eloquent-bypass pattern
        // AcademicYearLifecycleTest uses for a DB CHECK constraint.
        $this->createGuardianContact($guardian, ContactType::Email, 'second@example.com', ['is_primary' => true]);
    }

    #[Test]
    public function setting_a_new_primary_contact_demotes_the_previous_one(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $first = $this->createGuardianContact($guardian, ContactType::Email, 'first@example.com', ['is_primary' => true]);
        $second = $this->createGuardianContact($guardian, ContactType::Email, 'second@example.com');

        app(GuardianContactService::class)->setPrimary($second);

        app(TenantContext::class)->withSchool($school, function () use ($first, $second): void {
            $this->assertFalse($first->fresh()->is_primary);
            $this->assertTrue($second->fresh()->is_primary);
        });
    }

    #[Test]
    public function a_duplicate_exact_contact_on_the_same_guardian_is_rejected(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $this->expectException(QueryException::class);

        $this->createGuardianContact($guardian, ContactType::Email, 'PARENT@EXAMPLE.COM');
    }

    #[Test]
    public function the_same_email_across_different_guardians_is_allowed(): void
    {
        $school = $this->createSchool();
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);

        $contactA = $this->createGuardianContact($guardianA, ContactType::Email, 'household@example.com');
        $contactB = $this->createGuardianContact($guardianB, ContactType::Email, 'household@example.com');

        $this->assertNotSame($contactA->id, $contactB->id);
        $this->assertSame($contactA->lookup_hash, $contactB->lookup_hash, 'Same School + type + value must share a digest.');
    }

    #[Test]
    public function the_same_mobile_across_different_guardians_is_allowed(): void
    {
        $school = $this->createSchool();
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);

        $contactA = $this->createGuardianContact($guardianA, ContactType::Mobile, '+919999999999');
        $contactB = $this->createGuardianContact($guardianB, ContactType::Mobile, '+919999999999');

        $this->assertNotSame($contactA->id, $contactB->id);
    }

    #[Test]
    public function duplicate_lookup_returns_every_legitimate_same_school_candidate(): void
    {
        $school = $this->createSchool();
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $this->createGuardianContact($guardianA, ContactType::Mobile, '+919999999999');
        $this->createGuardianContact($guardianB, ContactType::Mobile, '+919999999999');

        $candidates = app(GuardianContactService::class)->findCandidatesBySchool($school, ContactType::Mobile, '+919999999999');

        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing(
            [$guardianA->id, $guardianB->id],
            $candidates->pluck('id')->all(),
        );
    }

    #[Test]
    public function cross_school_lookup_never_leaks_a_candidate_from_another_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $guardianA = $this->createGuardian($schoolA);
        $guardianB = $this->createGuardian($schoolB);
        $this->createGuardianContact($guardianA, ContactType::Email, 'parent@example.com');
        $this->createGuardianContact($guardianB, ContactType::Email, 'parent@example.com');

        $candidatesFromA = app(GuardianContactService::class)->findCandidatesBySchool($schoolA, ContactType::Email, 'parent@example.com');
        $candidatesFromB = app(GuardianContactService::class)->findCandidatesBySchool($schoolB, ContactType::Email, 'parent@example.com');

        $this->assertEqualsCanonicalizing([$guardianA->id], $candidatesFromA->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$guardianB->id], $candidatesFromB->pluck('id')->all());
    }

    #[Test]
    public function deactivating_a_contact_clears_its_primary_flag(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com', ['is_primary' => true]);

        app(GuardianContactService::class)->deactivate($contact);

        app(TenantContext::class)->withSchool($school, function () use ($contact): void {
            $fresh = $contact->fresh();
            $this->assertFalse($fresh->is_active);
            $this->assertFalse($fresh->is_primary);
        });
    }

    #[Test]
    public function encrypted_value_and_lookup_hash_are_hidden_from_array_serialization(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $array = $contact->toArray();

        $this->assertArrayNotHasKey('encrypted_value', $array);
        $this->assertArrayNotHasKey('lookup_hash', $array);
    }
}
