<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.1b §16/§18/§19/§31 -- HTTP coverage for the Private
 * conversations section of the existing Communication Channels
 * settings page: authorized read/write, unauthorized denial, and
 * cross-School isolation. Mirrors
 * CommunicationChannelPolicySettingsHubTest's exact conventions.
 */
class CommunicationConversationPolicySettingsHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_school_admin_sees_the_conservative_system_default_when_no_override_exists(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/settings/channels')->assertInertia(fn ($page) => $page
            ->where('conversationPolicy.allowGuardianConversations', true)
            ->where('conversationPolicy.allowStudentConversations', false)
        );
    }

    #[Test]
    public function a_school_admin_can_update_the_guardian_and_student_toggles(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => false,
            'allow_student_conversations' => true,
        ])->assertRedirect();

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);
        $this->assertFalse($view->allowGuardianConversations);
        $this->assertTrue($view->allowStudentConversations);
        $this->assertTrue($view->isOverride);

        $this->get('/app/communications/settings/channels')->assertInertia(fn ($page) => $page
            ->where('conversationPolicy.allowGuardianConversations', false)
            ->where('conversationPolicy.allowStudentConversations', true)
        );
    }

    #[Test]
    public function the_guardian_toggle_persists_independently_of_the_student_toggle(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => false,
            'allow_student_conversations' => false,
        ])->assertRedirect();

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => true,
            'allow_student_conversations' => false,
        ])->assertRedirect();

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);
        $this->assertTrue($view->allowGuardianConversations);
        $this->assertFalse($view->allowStudentConversations);
    }

    #[Test]
    public function the_student_toggle_persists_independently_of_the_guardian_toggle(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => true,
            'allow_student_conversations' => true,
        ])->assertRedirect();

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);
        $this->assertTrue($view->allowStudentConversations);
    }

    #[Test]
    public function a_principal_without_communications_manage_cannot_view_or_update_the_policy(): void
    {
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->get('/app/communications/settings/channels')->assertForbidden();

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => false,
            'allow_student_conversations' => false,
        ])->assertForbidden();

        $view = app(CommunicationConversationPolicyService::class)->policyFor($school);
        $this->assertTrue($view->allowGuardianConversations, 'Default must be untouched by the denied request.');
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        $this->get('/app/communications/settings/channels')->assertRedirect('/login');
    }

    #[Test]
    public function invalid_payload_renders_a_server_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => 'not-a-boolean-array-should-still-coerce',
            'allow_student_conversations' => null,
        ])->assertInvalid(['allow_student_conversations']);
    }

    #[Test]
    public function updating_school_as_policy_never_affects_school_bs_policy(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createConversationPolicy($schoolB, [
            'allow_guardian_conversations' => true,
            'allow_student_conversations' => false,
        ]);

        $this->activate($adminA, $schoolA);
        $this->put('/app/communications/settings/conversations', [
            'allow_guardian_conversations' => false,
            'allow_student_conversations' => true,
        ])->assertRedirect();

        $viewB = app(CommunicationConversationPolicyService::class)->policyFor($schoolB);
        $this->assertTrue($viewB->allowGuardianConversations, "School A's update must not leak into School B.");
        $this->assertFalse($viewB->allowStudentConversations);
    }
}
