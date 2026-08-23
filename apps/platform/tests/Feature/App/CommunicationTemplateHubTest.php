<?php

namespace Tests\Feature\App;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.4 §41: HTTP-layer coverage for Template administration --
 * authorization, tenant isolation, pagination, activate/deactivate.
 * Mirrors AnnouncementHubTest's conventions.
 */
class CommunicationTemplateHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/app/communications/templates')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_without_templates_manage_cannot_reach_the_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/communications/templates')->assertForbidden();
    }

    #[Test]
    public function an_authorized_user_can_create_a_template(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/templates', [
            'name' => 'Closure Notice', 'subject' => 'School Closure',
            'body' => 'School closes at 1 PM today.', 'priority' => 'urgent',
        ]);
        $create->assertRedirect();

        $this->get('/app/communications/templates')->assertInertia(fn ($page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.name', 'Closure Notice')
            ->where('templates.data.0.status', 'active')
        );
    }

    #[Test]
    public function an_unauthorized_member_cannot_create_a_template(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->post('/app/communications/templates', [
            'name' => 'X', 'body' => 'Y',
        ])->assertForbidden();
    }

    #[Test]
    public function school_a_cannot_view_school_bs_template(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $templateB = $this->createTemplate($schoolB, $creatorB);

        $this->activate($adminA, $schoolA);

        $this->get("/app/communications/templates/{$templateB->id}/edit")->assertNotFound();
    }

    #[Test]
    public function school_a_cannot_update_school_bs_template(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $templateB = $this->createTemplate($schoolB, $creatorB);

        $this->activate($adminA, $schoolA);

        $this->put("/app/communications/templates/{$templateB->id}", [
            'name' => 'Hijacked', 'body' => 'Hijacked body',
        ])->assertNotFound();
    }

    #[Test]
    public function an_authorized_user_can_deactivate_and_reactivate_a_template(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $template = $this->createTemplate($school, $admin);
        $this->activate($admin, $school);

        $this->post("/app/communications/templates/{$template->id}/deactivate")->assertRedirect();
        $this->get("/app/communications/templates/{$template->id}/edit")->assertInertia(fn ($page) => $page
            ->where('template.status', 'inactive')
        );

        $this->post("/app/communications/templates/{$template->id}/activate")->assertRedirect();
        $this->get("/app/communications/templates/{$template->id}/edit")->assertInertia(fn ($page) => $page
            ->where('template.status', 'active')
        );
    }

    #[Test]
    public function the_index_is_paginated_and_filterable_by_status(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createTemplate($school, $admin, ['status' => 'active']);
        $this->createTemplate($school, $admin, ['status' => 'inactive']);
        $this->activate($admin, $school);

        $this->get('/app/communications/templates?status=active')->assertInertia(fn ($page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.status', 'active')
        );

        $this->get('/app/communications/templates?status=inactive')->assertInertia(fn ($page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.status', 'inactive')
        );

        $this->get('/app/communications/templates')->assertInertia(fn ($page) => $page->has('templates.data', 2));
    }
}
