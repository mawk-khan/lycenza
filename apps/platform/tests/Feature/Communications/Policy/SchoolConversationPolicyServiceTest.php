<?php

namespace Tests\Feature\Communications\Policy;

use App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService;
use App\Domain\Communications\Application\Policy\SchoolConversationPolicyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1 §16: the admin write path, and the default-policy fallback
 * proving deploying this checkpoint changes nothing for a School that
 * never configures it -- Guardian conversations remain
 * capability-controlled-only, Student conversations remain disabled.
 */
class SchoolConversationPolicyServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_school_with_no_override_gets_the_conservative_system_default(): void
    {
        $school = $this->createSchool();

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);

        $this->assertTrue($view->allowGuardianConversations);
        $this->assertFalse($view->allowStudentConversations);
        $this->assertFalse($view->isOverride);
    }

    #[Test]
    public function setting_a_policy_persists_an_explicit_override(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        app(SchoolConversationPolicyService::class)->setPolicy($school, $admin, false, true);

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);

        $this->assertFalse($view->allowGuardianConversations);
        $this->assertTrue($view->allowStudentConversations);
        $this->assertTrue($view->isOverride);
    }

    #[Test]
    public function setting_a_policy_twice_updates_the_same_row(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $service = app(SchoolConversationPolicyService::class);

        $first = $service->setPolicy($school, $admin, true, true);
        $second = $service->setPolicy($school, $admin, true, false);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->allow_student_conversations);
    }
}
