<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Policy\CommunicationPreferenceService;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §11/§28: the preference write path, and the mandatory
 * multi-school isolation proof -- one User, two SchoolMembership rows,
 * independent preferences.
 */
class CommunicationPreferenceServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): CommunicationPreferenceService
    {
        return app(CommunicationPreferenceService::class);
    }

    #[Test]
    public function setting_a_preference_creates_it(): void
    {
        [$actor, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);

        $preference = $this->service()->setPreference($membership, $member, CommunicationChannel::Email, false);

        $this->assertSame('disabled', $preference->preference);
    }

    #[Test]
    public function setting_a_preference_twice_updates_the_same_row_not_a_duplicate(): void
    {
        [$actor, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);

        $this->service()->setPreference($membership, $member, CommunicationChannel::Email, false);
        $this->service()->setPreference($membership, $member, CommunicationChannel::Email, true);

        $context = app(TenantContext::class);
        $count = $context->withSchool($school, fn () => CommunicationPreference::query()
            ->where('school_membership_id', $membership->id)->where('channel', 'email')->count());

        $this->assertSame(1, $count);
    }

    #[Test]
    public function the_same_user_has_independent_preferences_in_two_different_schools(): void
    {
        $user = $this->createUser();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $membershipA = $this->createMembership($user, $schoolA);
        $membershipB = $this->createMembership($user, $schoolB);

        $this->service()->setPreference($membershipA, $user, CommunicationChannel::Email, false);
        $this->service()->setPreference($membershipB, $user, CommunicationChannel::Email, true);

        $context = app(TenantContext::class);
        $preferenceA = $context->withSchool($schoolA, fn () => CommunicationPreference::query()
            ->where('school_membership_id', $membershipA->id)->where('channel', 'email')->first());
        $preferenceB = $context->withSchool($schoolB, fn () => CommunicationPreference::query()
            ->where('school_membership_id', $membershipB->id)->where('channel', 'email')->first());

        $this->assertSame('disabled', $preferenceA->preference);
        $this->assertSame('enabled', $preferenceB->preference);
    }
}
