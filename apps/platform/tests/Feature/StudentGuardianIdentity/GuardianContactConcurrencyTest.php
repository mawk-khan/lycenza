<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Application\Exceptions\GuardianContactConflictException;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 2.1 (MC-4): contact corrections, reactivations and deactivations in two
 * real processes with a forced, observed overlap. Each takes the contact's row
 * lock, and the one-row-per-value unique index is the backstop for two
 * different rows racing to the same value -- so a second reactivation, a
 * correction prepared before a deactivation, and a racing duplicate are all
 * refused, never applied.
 */
class GuardianContactConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    private School $school;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->guardian = $this->createGuardian($this->school);
    }

    /** @return array<int, string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/guardian-contact-op.php', $args[0], $this->school->id, ...array_slice($args, 1)];
    }

    private function fresh(GuardianContact $contact): GuardianContact
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => GuardianContact::query()->findOrFail($contact->id));
    }

    #[Test]
    public function two_reactivations_bring_the_contact_back_once(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        app(GuardianContactService::class)->deactivate($this->fresh($contact));

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('reactivate', $contact->id), $this->op('reactivate', $contact->id));

        $this->assertSame('reactivated', $holder);
        $this->assertSame('rejected:'.GuardianContactConflictException::class.':GUARDIAN_CONTACT_ALREADY_ACTIVE', $contender);
        $this->assertTrue($this->fresh($contact)->is_active);
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'guardian_contact.reactivated')->count()));
    }

    #[Test]
    public function a_correction_prepared_before_a_deactivation_is_refused(): void
    {
        // Created, deactivated and corrected within the same second: the
        // version is the row's state, not only its one-second timestamp.
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('deactivate', $contact->id), $this->op('correct', $contact->id, 'new@example.com'));

        $this->assertSame('deactivated', $holder);
        $this->assertSame('rejected:'.GuardianContactConflictException::class.':GUARDIAN_CONTACT_CHANGED', $contender);
        $this->assertSame(['parent@example.com', false], [$this->fresh($contact)->encrypted_value, $this->fresh($contact)->is_active]);
    }

    #[Test]
    public function two_contacts_racing_to_the_same_value_leave_one(): void
    {
        $first = $this->createGuardianContact($this->guardian, ContactType::Email, 'one@example.com');
        $second = $this->createGuardianContact($this->guardian, ContactType::Email, 'two@example.com');

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('correct', $first->id, 'same@example.com'), $this->op('correct', $second->id, 'same@example.com'));

        $this->assertSame('corrected', $holder);
        $this->assertSame('rejected:'.GuardianContactConflictException::class.':GUARDIAN_CONTACT_DUPLICATE', $contender);
        $this->assertSame(['same@example.com', 'two@example.com'], [$this->fresh($first)->encrypted_value, $this->fresh($second)->encrypted_value]);
    }
}
