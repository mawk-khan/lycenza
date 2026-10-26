<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Events\CommunicationEventCalendar;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3R1 (ADR 0076 §76): the School calendar is CONSUMPTION on its own
 * capability, `communications.events.view`, carried by every staff persona
 * role. It never opens Communications administration (the Hub, the
 * announcement list, Event management, approvals), and it never makes
 * anyone staff: a Staff Event still needs current HR staff identity
 * (CurrentStaffMembers), checked for the viewer on every read.
 */
class CommunicationEventConsumerAccessTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    /** Every School system role that consumes the calendar (all but the hr_sensitive_records add-on). */
    private const CONSUMER_ROLES = [
        'accountant', 'admissions_officer', 'canteen_operator', 'cashier', 'communications_coordinator',
        'front_office', 'hostel_warden', 'hr_officer', 'librarian', 'payroll_officer', 'principal',
        'school_admin', 'staff_self_service', 'stores_officer', 'teacher', 'transport_coordinator',
    ];

    private School $school;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['communications.announce']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['communications.approve']);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Open day']);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Staff meeting', 'audience_type' => 'staff']);
    }

    /** A member of `$school` holding the real system role `$roleKey`, optionally employed (current, ended or future). */
    private function withRole(string $roleKey, ?string $employment = 'current', ?School $school = null): User
    {
        $school ??= $this->school;
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $school), Role::query()->where('key', $roleKey)->firstOrFail());
        if ($employment !== null) {
            $this->employ($user, $school, $employment);
        }

        return $user;
    }

    private function employ(User $user, School $school, string $employment): void
    {
        $record = $this->createEmployee($school, ['user_id' => $user->id, 'record_status' => 'active']);
        $this->createEmploymentRecord($record, match ($employment) {
            'current' => ['status' => 'active', 'starts_on' => '2025-01-01'],
            'ended' => ['status' => 'separated', 'starts_on' => '2025-01-01', 'ends_on' => '2026-09-30'],
            'future' => ['status' => 'active', 'starts_on' => '2026-11-01'],
        });
    }

    /** @return list<string> */
    private function calendarTitles(User $viewer, ?School $school = null): array
    {
        $school ??= $this->school;
        $props = $this->signInTo($viewer, $school)->get('/app/communications/calendar')->assertOk()->viewData('page')['props'];

        return collect($props['events'])->pluck('title')->sort()->values()->all();
    }

    /** @return list<string> the navigation entry keys offered to the signed-in viewer */
    private function offeredNavigation(): array
    {
        return self::navigationKeys($this->get('/app/communications/calendar')->viewData('page')['props']['navigation']);
    }

    #[Test]
    public function a_current_teacher_reads_the_calendar_and_reaches_no_communications_administration(): void
    {
        $teacher = $this->withRole('teacher');
        $draft = $this->draftEvent($this->school, $this->preparer, ['title' => 'Draft only']);
        $pending = $this->draftEvent($this->school, $this->preparer, ['title' => 'Pending']);
        $this->events()->submit($pending, $this->preparer);
        $request = app(TenantContext::class)->withSchool($this->school, fn () => CommunicationApprovalRequest::query()->where('event_id', $pending->id)->sole());

        $this->assertSame(['Open day', 'Staff meeting'], $this->calendarTitles($teacher), 'Whole-School and Staff Events; never a draft or pending Event.');
        $keys = $this->offeredNavigation();
        $this->assertContains('communications-calendar', $keys);
        $this->assertNotContains('communications-events', $keys);
        // ORG.9D-1R1 (ADR 0076 §77): the Hub entry is the teacher's own inbox
        // (communications.inbox.view), never the School announcement list.
        $this->assertContains('communications', $keys, 'The Hub entry is the teacher\'s own inbox.');

        $this->get('/app/communications')->assertOk();
        $this->get('/app/communications/announcements')->assertForbidden();
        $this->get('/app/communications/events')->assertForbidden();
        $this->get('/app/communications/events/create')->assertForbidden();
        $this->post('/app/communications/events', ['title' => 'Mine', 'body' => 'x', 'date_mode' => 'all_day', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05', 'audience_type' => 'school_wide'])->assertForbidden();
        $this->get("/app/communications/events/{$draft->id}")->assertForbidden();
        $this->get("/app/communications/events/{$draft->id}/edit")->assertForbidden();
        $this->put("/app/communications/events/{$draft->id}", ['title' => 'Changed'])->assertForbidden();
        $this->post("/app/communications/events/{$draft->id}/submit-approval")->assertForbidden();
        $this->post("/app/communications/events/{$pending->id}/withdraw-approval")->assertForbidden();
        $this->post("/app/communications/events/{$draft->id}/publish")->assertForbidden();
        $this->post("/app/communications/events/{$draft->id}/cancel")->assertForbidden();
        $this->get('/app/communications/approvals')->assertForbidden();
        $this->get("/app/communications/approvals/{$request->id}")->assertForbidden();
        $this->post("/app/communications/approvals/{$request->id}/approve")->assertForbidden();

        $this->assertSame(['draft', 'pending_approval'], app(TenantContext::class)->withSchool($this->school, fn () => [
            $this->freshEvent($draft)->status, $this->freshEvent($pending)->status,
        ]), 'Nothing the teacher tried changed an Event.');
    }

    #[Test]
    public function staff_events_follow_current_employment_never_the_role(): void
    {
        $this->assertSame(['Open day'], $this->calendarTitles($this->withRole('teacher', 'ended')), 'A former teacher keeps the role but loses Staff Events.');
        $this->assertSame(['Open day'], $this->calendarTitles($this->withRole('teacher', 'future')), 'Employment starting later grants nothing yet.');
        $this->assertSame(['Open day'], $this->calendarTitles($this->withRole('teacher', null)), 'The capability alone never makes anyone staff.');

        $later = $this->withRole('teacher', 'future');
        $this->travelTo(CarbonImmutable::parse('2026-11-01T06:00:00Z'));
        $this->assertSame(['Open day', 'Staff meeting'], $this->calendarTitles($later), 'Eligibility is evaluated when the calendar is opened.');
    }

    #[Test]
    public function every_consumer_role_needs_current_staff_identity_for_staff_events(): void
    {
        $calendar = app(CommunicationEventCalendar::class);
        $titles = fn (User $u) => collect($calendar->forStaff($this->school, $u, CommunicationEventCalendar::UPCOMING))->pluck('title')->sort()->values()->all();

        foreach (self::CONSUMER_ROLES as $role) {
            $this->assertSame(['Open day', 'Staff meeting'], $titles($this->withRole($role)), "{$role} + current Employee");
            $this->assertSame(['Open day'], $titles($this->withRole($role, null)), "{$role} without an Employee");
        }

        $this->expectException(AuthorizationException::class);
        $calendar->forStaff($this->school, $this->withRole('hr_sensitive_records'), CommunicationEventCalendar::UPCOMING);
    }

    #[Test]
    public function the_capability_sits_on_exactly_the_staff_persona_roles_and_nowhere_else(): void
    {
        $holders = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->where('rc.capability_key', 'communications.events.view')->orderBy('r.key')->pluck('r.key')->all();
        $this->assertSame(self::CONSUMER_ROLES, $holders, 'No Guardian, Group, platform or add-on role holds it.');
        $this->assertSame(['school'], DB::table('roles')->whereIn('key', $holders)->distinct()->pluck('scope')->all());
        $this->assertSame('school', DB::table('capabilities')->where('key', 'communications.events.view')->value('namespace'));
    }

    #[Test]
    public function the_hub_capability_alone_no_longer_opens_the_calendar_and_non_staff_accounts_get_none(): void
    {
        $hubOnly = $this->createUserWithCapabilities($this->school, ['communications.view']);
        $this->signInTo($hubOnly, $this->school)->get('/app/communications/calendar')->assertForbidden();

        $guardian = $this->portalGuardian($this->school);
        $this->signInTo($guardian['user'], $this->school)->get('/app/communications/calendar')->assertForbidden();
        $portal = $this->get('/app/portal/calendar')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Open day'], collect($portal['events'])->pluck('title')->all(), 'The portal is unchanged: never a Staff Event.');

        $noMembership = $this->createUser();
        $this->assertNotSame(200, $this->actingAs($noMembership)->get('/app/communications/calendar')->getStatusCode(), 'A User with no School membership (e.g. platform-only) has no calendar.');
    }

    #[Test]
    public function a_staff_member_who_is_also_a_guardian_sees_each_persona_on_its_own_surface(): void
    {
        $teacher = $this->withRole('teacher');
        $this->portalGuardian($this->school, $teacher, SchoolMembership::query()->where('user_id', $teacher->id)->where('school_id', $this->school->id)->first());

        $this->assertSame(['Open day', 'Staff meeting'], $this->calendarTitles($teacher));
        $portal = $this->get('/app/portal/calendar')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Open day'], collect($portal['events'])->pluck('title')->all());
    }

    #[Test]
    public function a_disabled_communications_module_closes_the_calendar_despite_the_role(): void
    {
        $teacher = $this->withRole('teacher');
        $this->inSchool(fn () => DB::table('school_module_entitlements')->where('school_id', $this->school->id)->where('module_key', 'communications')->update(['enabled' => false]));

        $this->signInTo($teacher, $this->school)->get('/app/communications/calendar')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CommunicationEventCalendar::class)->forStaff($this->school, $teacher, CommunicationEventCalendar::UPCOMING);
    }

    #[Test]
    public function authority_and_identity_are_evaluated_per_school(): void
    {
        $elsewhere = $this->createSchool();
        $foreignPreparer = $this->createUserWithCapabilities($elsewhere, ['communications.announce']);
        $this->publishedEvent($elsewhere, $foreignPreparer, $this->createUserWithCapabilities($elsewhere, ['communications.approve']), ['title' => 'Elsewhere staff day', 'audience_type' => 'staff']);

        // A teacher here who is a role-less member there: no calendar there.
        $teacher = $this->withRole('teacher');
        $this->createMembership($teacher, $elsewhere);
        $this->signInTo($teacher, $elsewhere)->get('/app/communications/calendar')->assertForbidden();
        $this->assertSame(['Open day', 'Staff meeting'], $this->calendarTitles($teacher), 'Never another School\'s Event here.');

        // The teacher role there, but employed only here: not staff there.
        $shared = $this->withRole('teacher');
        LocalCatalogueFixtures::grantRole($this->createMembership($shared, $elsewhere), Role::query()->where('key', 'teacher')->firstOrFail());
        $this->assertSame([], $this->calendarTitles($shared, $elsewhere), 'School-B Staff identity is School B\'s own.');
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }
}
