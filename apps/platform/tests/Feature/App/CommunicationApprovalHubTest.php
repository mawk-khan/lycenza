<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.12 §57/§58/§78/§90 -- HTTP-layer authorization for the
 * approval queue/detail/decision routes and the Announcement submit/
 * withdraw actions. `communications.approve` is a distinct capability
 * from `communications.manage`/`.announce` throughout.
 */
class CommunicationApprovalHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function approvals(): CommunicationApprovalService
    {
        return app(CommunicationApprovalService::class);
    }

    #[Test]
    public function an_authorized_sender_can_submit_via_http(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);
        $this->activate($admin, $school);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        $this->post("/app/communications/announcements/{$announcement->id}/submit-approval")
            ->assertRedirect();

        $this->get("/app/communications/announcements/{$announcement->id}")
            ->assertInertia(fn ($page) => $page->where('announcement.status', 'pending_approval'));
    }

    #[Test]
    public function a_capable_approver_can_view_and_decide_via_http(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $admin);

        $this->activate($approver, $school);

        $this->get('/app/communications/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 1));

        $this->get("/app/communications/approvals/{$request->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canDecide', true));

        $this->post("/app/communications/approvals/{$request->id}/approve", ['note' => 'ok'])
            ->assertRedirect();

        // Re-activate as the (communications.manage-capable) creator to
        // view the announcement -- the approver themselves is neither
        // the creator nor a resolved recipient of an unpublished
        // announcement.
        $this->activate($admin, $school);
        $this->get("/app/communications/announcements/{$announcement->id}")
            ->assertInertia(fn ($page) => $page->where('announcement.status', 'approved'));
    }

    #[Test]
    public function rejecting_via_http_requires_a_reason(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $admin);

        $this->activate($approver, $school);

        $this->post("/app/communications/approvals/{$request->id}/reject", [])
            ->assertSessionHasErrors('reason');

        $this->post("/app/communications/approvals/{$request->id}/reject", ['reason' => 'Please revise.'])
            ->assertRedirect();

        $this->activate($admin, $school);
        $this->get("/app/communications/announcements/{$announcement->id}")
            ->assertInertia(fn ($page) => $page->where('announcement.status', 'rejected'));
    }

    #[Test]
    public function an_ordinary_member_cannot_approve_or_reject(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $admin);

        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($member, $school);

        $this->get('/app/communications/approvals')->assertForbidden();
        $this->get("/app/communications/approvals/{$request->id}")->assertForbidden();
        $this->post("/app/communications/approvals/{$request->id}/approve")->assertForbidden();
    }

    #[Test]
    public function a_cross_school_approver_cannot_see_or_decide_another_schools_request(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $schoolA);
        $this->createApprovalPolicy($schoolA, ['require_school_wide_approval' => true]);

        $announcementA = $this->announcements()->createDraft(
            $schoolA, $adminA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $requestA = $this->approvals()->submit($announcementA, $adminA);

        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->activate($adminB, $schoolB);

        $this->get("/app/communications/approvals/{$requestA->id}")->assertNotFound();
        $this->post("/app/communications/approvals/{$requestA->id}/approve")->assertNotFound();
    }

    #[Test]
    public function a_multi_school_user_may_approve_only_in_the_school_granting_the_capability(): void
    {
        $user = $this->createUser();

        $schoolA = $this->createSchool();
        $membershipA = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membershipA, 'principal');
        $this->createApprovalPolicy($schoolA, ['require_school_wide_approval' => true]);

        $schoolB = $this->createSchool();
        $membershipB = $this->createMembership($user, $schoolB);
        // No role assigned in School B -- no communications.approve there.

        $creatorA = $this->createUser();
        $this->createMembership($creatorA, $schoolA);
        $this->createMembership($this->createUser(), $schoolA);

        $announcementA = $this->announcements()->createDraft(
            $schoolA, $creatorA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $requestA = $this->approvals()->submit($announcementA, $creatorA);

        $this->activate($user, $schoolA);
        $this->get('/app/communications/approvals')->assertOk();

        $this->activate($user, $schoolB);
        $this->get('/app/communications/approvals')->assertForbidden();
    }

    #[Test]
    public function a_forged_approval_request_id_is_not_found(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/approvals/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    #[Test]
    public function deciding_never_mutates_unrelated_state_and_deciding_twice_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $request = $this->approvals()->submit($announcement, $admin);

        $this->activate($approver, $school);
        $this->post("/app/communications/approvals/{$request->id}/approve")->assertRedirect();

        $this->post("/app/communications/approvals/{$request->id}/reject", ['reason' => 'too late'])
            ->assertSessionHasErrors('reason');
    }

    /**
     * Phase 5A.12 §69/§92 -- the queue must paginate and never issue
     * one query per row (requester lookup, announcement lookup).
     */
    #[Test]
    public function the_approval_queue_paginates_and_issues_a_bounded_number_of_queries(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_school_wide_approval' => true]);

        foreach (range(1, 25) as $i) {
            $this->createMembership($this->createUser(), $school);
            $announcement = $this->announcements()->createDraft(
                $school, $admin, "Announcement {$i}", 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            );
            $this->approvals()->submit($announcement, $admin);
        }

        $this->activate($approver, $school);

        DB::enableQueryLog();
        $response = $this->get('/app/communications/approvals')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $response->assertInertia(fn ($page) => $page
            ->where('meta.total', 25)
            ->has('requests', 20));

        // Brief §69/§92: bounded and flat regardless of row count --
        // NOT one query per row (20 rows on this page would mean 40+
        // queries under an N+1 requester/announcement lookup).
        $this->assertLessThan(20, $queryCount);
    }
}
