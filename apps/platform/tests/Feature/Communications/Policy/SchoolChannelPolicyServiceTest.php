<?php

namespace Tests\Feature\Communications\Policy;

use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Application\Policy\SchoolChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §15/§16: the admin write path, and the default-policy
 * fallback proving deploying this checkpoint changes nothing for a
 * School that never configures it (brief §14).
 */
class SchoolChannelPolicyServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_school_with_no_override_gets_the_fully_permissive_system_default(): void
    {
        $school = $this->createSchool();

        $view = app(CommunicationChannelPolicyService::class)->policyFor($school, CommunicationChannel::Email);

        $this->assertTrue($view->optionalAllowed);
        $this->assertTrue($view->requiredAllowed);
        $this->assertTrue($view->recipientCanOptOut);
        $this->assertFalse($view->isOverride);
    }

    #[Test]
    public function setting_a_policy_persists_an_explicit_override(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        app(SchoolChannelPolicyService::class)->setPolicy($school, $admin, CommunicationChannel::Email, false, true, true);

        $view = app(CommunicationChannelPolicyService::class)->policyFor($school, CommunicationChannel::Email);

        $this->assertFalse($view->optionalAllowed);
        $this->assertTrue($view->isOverride);
    }

    #[Test]
    public function setting_a_policy_twice_updates_the_same_row(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $service = app(SchoolChannelPolicyService::class);

        $first = $service->setPolicy($school, $admin, CommunicationChannel::Email, true, true, true);
        $second = $service->setPolicy($school, $admin, CommunicationChannel::Email, false, false, false);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->optional_allowed);
    }
}
