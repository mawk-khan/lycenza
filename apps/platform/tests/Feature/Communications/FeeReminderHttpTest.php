<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Communications\Concerns\CreatesFeeReminderFixtures;
use Tests\TestCase;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): the fee reminder's HTTP surface and its
 * Campus behaviour. Entry is Finance -> Outstanding fees; preparing one
 * needs communications.announce AND the Finance read authority its
 * selection names (neither alone); the figures go to the preparer only; the
 * approver sees the message and the selection, never balances; a
 * Campus-scoped preparer reaches only Charges whose stored Campus is in
 * scope, in counts, totals and every Guardian's lines.
 */
class FeeReminderHttpTest extends TestCase
{
    use CreatesFeeReminderFixtures;

    private function as($user, array $w): self
    {
        $this->actingAs($user)->post("/app/schools/{$w['school']->id}/activate");

        return $this;
    }

    #[Test]
    public function the_outstanding_page_offers_a_reminder_only_to_someone_who_can_also_prepare_communications(): void
    {
        $w = $this->reminderWorld();
        $financeOnly = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']);

        $this->as($financeOnly, $w)->get('/app/finance/outstanding')->assertOk()->assertInertia(fn ($page) => $page->where('canSendFeeReminder', false));
        $this->as($w['initiator'], $w)->get('/app/finance/outstanding')->assertOk()->assertInertia(fn ($page) => $page->where('canSendFeeReminder', true));
    }

    #[Test]
    public function preparing_a_reminder_needs_both_communications_and_finance_authority(): void
    {
        $w = $this->reminderWorld();
        $financeOnly = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']);
        $commsOnly = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.announce']);
        $url = "/app/communications/announcements/create?audience=fee_reminder&status=overdue&academic_year_id={$w['year']->id}";

        $this->as($w['initiator'], $w)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('feeReminder.fields.status', 'overdue')
            ->where('feeReminder.labels.status', 'Students with overdue fees')
            ->where('feeReminder.labels.academicYear', $w['year']->name));
        $this->as($financeOnly, $w)->get($url)->assertForbidden();
        $this->as($commsOnly, $w)->get($url)->assertForbidden();

        $payload = ['title' => 'Fees', 'body' => 'Please pay.', 'priority' => 'normal', 'audience_type' => 'fee_reminder', 'channels' => ['email'], 'fee_reminder' => ['status' => 'outstanding']];
        $this->as($commsOnly, $w)->post('/app/communications/announcements', $payload)->assertSessionHasErrors('audience');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => CommunicationAnnouncement::query()->count()));

        $other = $this->createSchool();
        $foreignCampus = $this->createCampus($other);
        $this->as($w['initiator'], $w)->post('/app/communications/announcements', [...$payload, 'fee_reminder' => ['status' => 'outstanding', 'campus_id' => $foreignCampus->id]])
            ->assertSessionHasErrors('fee_reminder.campus_id');

        Config::set('communications.channels.email.enabled', false);
        $this->as($w['initiator'], $w)->post('/app/communications/announcements', [...$payload, 'channels' => ['in_app']])->assertSessionHasErrors('audience');
        Config::set('communications.channels.email.enabled', true);

        $this->as($w['initiator'], $w)->post('/app/communications/announcements', $payload)->assertRedirect();
        $draft = $this->inSchool($w['school'], fn () => CommunicationAnnouncement::query()->sole());
        $this->assertSame('fee_reminder', $draft->audience_type);
        $this->assertSame(['email'], $this->inSchool($w['school'], fn () => DB::table('communication_announcement_channels')->where('announcement_id', $draft->id)->pluck('channel')->all()));
        $this->assertSame('outstanding', $this->inSchool($w['school'], fn () => DB::table('communication_announcement_fee_reminders')->where('announcement_id', $draft->id)->value('balance_status')));
    }

    #[Test]
    public function the_figures_go_to_the_preparer_only_and_the_approver_sees_the_selection_not_balances(): void
    {
        $w = $this->reminderWorld();
        $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        $draft = $this->draftReminder($w);
        $manager = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.announce', 'communications.manage']);

        $this->as($w['initiator'], $w)->get("/app/communications/announcements/{$draft->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('preview.count', 1)
            ->where('preview.feeReminder.students', 1)
            ->where('preview.feeReminder.studentsWithoutRecipient', 0)
            ->where('preview.feeReminder.totalOutstanding', '1000.00')
            ->where('feeReminder.labels.status', 'Students with outstanding fees')
            ->where('canSchedule', false));

        $managerPage = $this->as($manager, $w)->get("/app/communications/announcements/{$draft->id}")->assertOk();
        $managerPage->assertInertia(fn ($page) => $page->where('preview', null)->where('feeReminder.labels.campus', 'All Campuses you can see'));
        $this->assertStringNotContainsString('1000.00', json_encode($managerPage->viewData('page')['props']));

        $this->as($w['initiator'], $w)->post("/app/communications/announcements/{$draft->id}/submit-approval")->assertRedirect();
        $request = $this->inSchool($w['school'], fn () => CommunicationApprovalRequest::query()->sole());
        $approvalPage = $this->as($w['approver'], $w)->get("/app/communications/approvals/{$request->id}")->assertOk();
        $approvalPage->assertInertia(fn ($page) => $page->where('request.audienceType', 'fee_reminder')->where('request.feeReminder.status', 'Students with outstanding fees'));
        $this->assertStringNotContainsString('1000.00', json_encode($approvalPage->viewData('page')['props']));

        $this->as($w['initiator'], $w)->post("/app/communications/approvals/{$request->id}/approve")->assertForbidden();
        $this->as($w['approver'], $w)->post("/app/communications/approvals/{$request->id}/approve")->assertRedirect();

        // A Communications manager without the preparer's Finance scope never
        // learns who (or how many) a fee reminder reaches.
        $this->as($manager, $w)->get("/app/communications/announcements/{$draft->id}/analytics")->assertForbidden();

        // Losing communications.announce stops the send at the door.
        LocalCatalogueFixtures::removeRoleCapability($w['commsRole'], 'communications.announce');
        app(CapabilityResolver::class)->forgetCache($w['initiator'], $w['school']);
        $this->as($w['initiator'], $w)->post("/app/communications/announcements/{$draft->id}/publish")->assertForbidden();
        $this->assertSame('approved', $this->freshOf($w, $draft)->status);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function a_campus_scoped_preparer_reaches_only_in_scope_charges_in_counts_totals_and_lines(): void
    {
        $w = $this->reminderWorld();
        $a = $w['campus'];
        $b = $this->createCampus($w['school'], ['name' => 'South']);
        $sectionA = $this->createSection($w['year'], $a, $w['grade'], ['code' => 'SA', 'status' => 'active']);
        $sectionB = $this->createSection($w['year'], $b, $w['grade'], ['code' => 'SB', 'status' => 'active']);
        $placed = function (string $name, $section, string $amount) use ($w) {
            $student = $this->createStudent($w['school'], ['first_name' => $name, 'last_name' => 'Pupil']);
            $this->createStudentEnrollment($student, $section, ['starts_on' => '2026-06-01', 'status' => 'active']);
            $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], $amount);

            return $student;
        };
        $inA = $placed('Asha', $sectionA, '100.00');
        $inB = $placed('Bilal', $sectionB, '200.00');
        $siblings = $this->guardianAccount($w, [$inA, $inB], 'siblings@school-os.test');
        $onlyB = $this->guardianAccount($w, [$inB], 'onlyb@school-os.test');

        $cashier = $this->createUser();
        $membership = $this->createMembership($cashier, $w['school']);
        $this->assignSchoolRole($membership, 'cashier', $a->id);
        LocalCatalogueFixtures::grantRole($membership, $w['commsRole']);
        $w['initiator'] = $cashier;

        // Naming a Campus outside the scope, or the whole-School Charges, is refused outright.
        $this->as($cashier, $w)->get("/app/communications/announcements/create?audience=fee_reminder&campus_id={$b->id}")->assertForbidden();
        $this->as($cashier, $w)->get('/app/communications/announcements/create?audience=fee_reminder&campus_id=unavailable')->assertForbidden();
        $this->as($cashier, $w)->get("/app/communications/announcements/create?audience=fee_reminder&campus_id={$a->id}")->assertOk();

        $draft = $this->draftReminder($w);
        $this->as($cashier, $w)->get("/app/communications/announcements/{$draft->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('preview.count', 1)
            ->where('preview.feeReminder.students', 1)
            ->where('preview.feeReminder.totalOutstanding', '100.00'));

        $this->approveReminder($w, $draft);
        $published = app(AnnouncementService::class)->publish($this->freshOf($w, $draft), $cashier);

        $this->assertSame([$siblings['user']->id => ['Asha Pupil: 100.00']], $this->linesOf($w, $published), 'Only the in-scope Student, even for the sibling Guardian.');
        $manager = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.manage']);
        $this->as($manager, $w)->get("/app/communications/announcements/{$published->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('announcement.recipientCount', null)->where('channelDeliverySummary', null)->where('canViewAnalytics', false));
        $this->as($cashier, $w)->get("/app/communications/announcements/{$published->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('announcement.recipientCount', 1));

        // The approval binds the preparer's Finance scope: widened after approval, it is stale.
        $second = $this->draftReminder($w);
        $this->approveReminder($w, $second);
        $this->assignSchoolRole($membership, 'cashier', $b->id);
        app(CapabilityResolver::class)->forgetCache($cashier, $w['school']);
        $this->assertThrows(fn () => app(AnnouncementService::class)->publish($this->freshOf($w, $second), $cashier), ApprovalRequiredException::class);
        $this->assertEmailNotAcceptedTo('onlyb@school-os.test');
        $this->assertArrayNotHasKey($onlyB['user']->id, $this->linesOf($w, $published));
    }
}
