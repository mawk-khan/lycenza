<?php

namespace Tests\Feature\App;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §23.14): the session-authenticated Leave administration
 * pages and the manager's approvals page.
 *
 * - Every page is capability-gated, and the forms are offered only to the
 *   capability that owns them.
 * - Domain refusals are form errors.
 * - The approvals page lists only the acting manager's current direct
 *   reports, resolved server-side. Anything else is the private 404.
 */
class LeaveAdminUiTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function actor(array $w, ?User $user = null): static
    {
        return $this->actingAs($user ?? $w['admin'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w);

        return $w;
    }

    #[Test]
    public function every_administration_page_renders_for_a_viewer_and_offers_forms_only_to_their_owners(): void
    {
        $w = $this->world();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);

        foreach (['configuration' => 'Configuration', 'entitlements' => 'Entitlements', 'requests' => 'Requests', 'year-close' => 'YearClose'] as $path => $component) {
            $this->actor($w)->get("/app/leave/{$path}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component("App/Leave/{$component}"));
            $this->actor($w, $this->createUserWithCapabilities($w['school'], ['hr.leave.approve']))->get("/app/leave/{$path}")->assertForbidden();
        }
        $this->actor($w, $viewer)->get('/app/leave/requests')->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
        $this->actor($w, $viewer)->get('/app/leave/configuration')->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', false));
        $this->actor($w)->get('/app/leave/configuration')->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', true)->has('types', 1));

        // A viewer cannot write through the pages either; the services refuse.
        $this->actor($w, $viewer)->post('/app/leave/requests', ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'full', 'ends_on' => '2026-10-12', 'end_portion' => 'full'])->assertForbidden();
        $this->actor($w, $viewer)->post('/app/leave/types', ['code' => 'XL', 'name' => 'X', 'is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => true])->assertForbidden();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_requests')->count()));

        $this->actor($w)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewLeave', true)->where('nav.canUseLeaveApprovals', false));
    }

    #[Test]
    public function an_administrator_submits_decides_and_cancels_through_the_pages_and_refusals_are_form_errors(): void
    {
        $w = $this->world();

        $this->actor($w)->post('/app/leave/requests', ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'full', 'ends_on' => '2026-10-13', 'end_portion' => 'full', 'reason_code' => 'personal'])
            ->assertSessionHasNoErrors();
        $id = $this->inSchool($w['school'], fn () => DB::table('leave_requests')->value('id'));

        $this->actor($w)->get('/app/leave/requests')->assertInertia(fn (AssertableInertia $page) => $page->has('requests', 1)->where('requests.0.submittedUnits', 4)
            ->where("employees.{$w['employment']->id}.fullName", fn ($name) => is_string($name)));
        $this->actor($w)->post('/app/leave/requests', ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-13', 'start_portion' => 'full', 'ends_on' => '2026-10-13', 'end_portion' => 'full'])
            ->assertSessionHasErrors('leave');

        $this->actor($w)->post("/app/leave/requests/{$id}/approve")->assertSessionHasNoErrors();
        $this->actor($w)->get("/app/leave/requests/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('App/Leave/RequestShow')
            ->has('request.days', 2)->where('request.status', 'approved'));
        $this->actor($w)->post("/app/leave/requests/{$id}/cancel", ['reason_code' => 'not a code'])->assertSessionHasErrors('reason_code');
        $this->actor($w)->post("/app/leave/requests/{$id}/cancel", ['reason_code' => 'plans_changed'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $id)->value('status')));
        $this->actor($w)->post("/app/leave/requests/{$id}/approve")->assertSessionHasErrors('leave');
    }

    #[Test]
    public function the_approvals_page_shows_only_current_direct_reports_and_answers_the_private_404_otherwise(): void
    {
        $w = $this->world();
        $report = $this->staffMember($w['school']);
        $manager = $this->staffMember($w['school'], ['hr.leave.approve']);
        $stranger = $this->staffMember($w['school'], ['hr.leave.approve']);
        $this->reportTo($report['assignment'], $manager['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $report['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $report['employment']);
        $mine = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full', null, $report['employment']);
        $this->submitLeave($w, '2026-10-12', '2026-10-12');

        $this->actor($w, $manager['user'])->get('/app/leave/approvals')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Leave/Approvals')->has('requests', 1)->where('requests.0.id', $mine->id)->has('employees', 1));
        $this->actor($w, $stranger['user'])->get('/app/leave/approvals')->assertInertia(fn (AssertableInertia $page) => $page->has('requests', 0));
        $this->actor($w, $stranger['user'])->post("/app/leave/approvals/{$mine->id}/approve")->assertNotFound();
        $this->actor($w)->get('/app/leave/approvals')->assertForbidden(); // manage without approve is no manager surface
        $this->actor($w, $this->createUserWithCapabilities($w['school'], ['hr.leave.view']))->get('/app/leave/approvals')->assertForbidden();

        $this->actor($w, $manager['user'])->post("/app/leave/approvals/{$mine->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('manager', $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $mine->id)->value('path')));
        $this->actor($w, $manager['user'])->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canUseLeaveApprovals', true));
    }

    #[Test]
    public function the_year_close_page_previews_and_executes_and_configuration_pages_post(): void
    {
        $w = $this->world();
        $this->actor($w)->post('/app/leave/calendar/holidays', ['date' => '2026-12-25', 'portion' => 'full', 'name' => 'Staff holiday'])->assertSessionHasNoErrors();
        $this->actor($w)->post('/app/leave/calendar/weekdays', ['weekdays' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off']])->assertSessionHasNoErrors();
        $this->actor($w)->post('/app/leave/settings', ['leave_year_start_month' => 1])->assertSessionHasErrors('leave');

        $this->travelTo('2027-04-10 10:00:00');
        app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);
        $this->actor($w)->get("/app/leave/year-close?leave_year_id={$w['year']->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preview.blockers', [])->has('preview.items', 1));
        $this->actor($w)->post('/app/leave/year-close', ['leave_year_id' => $w['year']->id])->assertSessionHasNoErrors();
        $this->actor($w)->post('/app/leave/year-close', ['leave_year_id' => $w['year']->id])->assertSessionHasErrors('leave');
        $closeId = $this->inSchool($w['school'], fn () => DB::table('leave_year_closes')->value('id'));
        $this->actor($w)->get("/app/leave/year-close?close_id={$closeId}")->assertInertia(fn (AssertableInertia $page) => $page->has('close.items', 1)->has('employees', 1));
    }
}
