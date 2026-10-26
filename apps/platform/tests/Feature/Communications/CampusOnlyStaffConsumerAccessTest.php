<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Authorization\ConsumerCapabilities;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.10-R1 (ADR 0076 §79, OD-9.7, ORG10-D02): a current Staff member whose
 * role grants are Campus-scoped only is in the Staff audience, so they must be
 * able to CONSUME what is sent to them. Exactly the two scope-independent
 * consumer keys (ConsumerCapabilities) are activated School-wide by an active
 * Campus grant on an active Campus of the same School; no other key of that
 * grant reaches a whole-School check, and what the keys show stays bound to
 * recipiency (inbox) and current audience membership (calendar).
 */
class CampusOnlyStaffConsumerAccessTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $main;

    private Campus $north;

    private User $creator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->main = $this->createCampus($this->school, ['code' => 'MAIN', 'status' => 'active']);
        $this->north = $this->createCampus($this->school, ['code' => 'NORTH', 'status' => 'active']);
        $this->creator = $this->staff('communications_coordinator', null);
        $this->approver = $this->createUserWithCapabilities($this->school, ['communications.approve']);
    }

    /** A member holding $role on $campus (null = whole School), optionally a current/ended Employee. */
    private function staff(string $role, ?Campus $campus, ?string $employment = 'current', ?School $school = null): User
    {
        $school ??= $this->school;
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role, $campus?->id);
        if ($employment !== null) {
            $record = $this->createEmployee($school, ['user_id' => $user->id, 'record_status' => 'active']);
            $this->createEmploymentRecord($record, $employment === 'current'
                ? ['status' => 'active', 'starts_on' => '2025-01-01']
                : ['status' => 'separated', 'starts_on' => '2025-01-01', 'ends_on' => '2026-09-30']);
        }

        return $user;
    }

    private function publish(string $title, CommunicationAudienceType $type = CommunicationAudienceType::Staff, array $individuals = []): CommunicationAnnouncement
    {
        $svc = app(AnnouncementService::class);

        return $svc->publish($svc->createDraft($this->school, $this->creator, $title, "Body of {$title}.", CommunicationPriority::Normal, $type, $individuals), $this->creator);
    }

    /** @return list<string> */
    private function calendarTitles(User $viewer): array
    {
        $props = $this->signInTo($viewer, $this->school)->get('/app/communications/calendar')->assertOk()->viewData('page')['props'];

        return collect($props['events'])->pluck('title')->sort()->values()->all();
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    #[Test]
    public function campus_only_staff_consume_their_staff_communications_and_nothing_more(): void
    {
        $this->publishedEvent($this->school, $this->creator, $this->approver, ['title' => 'Open day']);
        $this->publishedEvent($this->school, $this->creator, $this->approver, ['title' => 'Staff meeting', 'audience_type' => 'staff']);
        $people = [
            'librarian@Main' => $this->staff('librarian', $this->main),
            'cashier@Main' => $this->staff('cashier', $this->main),
            'principal@North' => $this->staff('principal', $this->north),
        ];
        $colleague = $this->staff('teacher', null);
        $announcement = $this->publish('Staff briefing');
        $private = $this->publish('For a colleague only', CommunicationAudienceType::Individual, [(string) $colleague->id]);
        $draft = app(AnnouncementService::class)->createDraft($this->school, $this->creator, 'Draft', 'x', CommunicationPriority::Normal, CommunicationAudienceType::Staff);

        foreach ($people as $label => $user) {
            $this->signInTo($user, $this->school);
            $inbox = $this->get('/app/communications')->assertOk()->viewData('page')['props'];
            $this->assertContains('Staff briefing', collect($inbox['items'])->pluck('title')->all(), "{$label}: received Staff announcement is in the inbox");
            $this->assertFalse($inbox['canViewHub'], "{$label}: never the School list");
            $nav = self::navigationKeys($inbox['navigation']);
            $this->assertContains('communications', $nav, $label);
            $this->assertContains('communications-calendar', $nav, $label);
            $this->get("/app/communications/announcements/{$announcement->id}")->assertOk();
            $this->get("/app/communications/announcements/{$private->id}")->assertForbidden();
            $this->get("/app/communications/announcements/{$draft->id}")->assertForbidden();
            $this->assertSame(['Open day', 'Staff meeting'], $this->calendarTitles($user), "{$label}: Staff + whole-School Events");
            foreach (['/app/communications/announcements', '/app/communications/announcements/create', '/app/communications/sent', '/app/communications/approvals',
                '/app/communications/analytics', '/app/communications/events', '/app/communications/events/create', '/app/communications/templates'] as $url) {
                $this->get($url)->assertForbidden();
            }
        }
    }

    #[Test]
    public function only_the_two_consumer_keys_of_a_campus_grant_reach_whole_school_checks(): void
    {
        $resolver = app(CapabilityResolver::class);
        $principal = $this->staff('principal', $this->north);
        $cashier = $this->staff('cashier', $this->main);

        $this->assertSame(ConsumerCapabilities::SCOPE_INDEPENDENT, $this->sorted($resolver->schoolCapabilities($principal, $this->school)), 'A Campus principal activates nothing else School-wide.');
        $this->assertSame(ConsumerCapabilities::SCOPE_INDEPENDENT, $this->sorted($resolver->schoolCapabilities($cashier, $this->school)));
        foreach (['students.view', 'students.manage', 'hr.employees.view', 'communications.view', 'communications.announce', 'communications.approve', 'school.audit.view', 'attendance.manage', 'finance.charges.view', 'finance.payments.record'] as $key) {
            $this->assertFalse($resolver->can($principal, $key, $this->school), "principal@North never holds {$key} School-wide");
            $this->assertFalse($resolver->can($cashier, $key, $this->school), "cashier@Main never holds {$key} School-wide");
        }
        // Campus business scope is exactly what it was: adopted keys on the granted Campus only.
        $this->assertSame([$this->main->id], array_keys($resolver->campusCapabilities($cashier, $this->school)));
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $this->sorted($resolver->campusCapabilities($cashier, $this->school)[$this->main->id]));
        $this->assertArrayNotHasKey($this->main->id, $resolver->campusCapabilities($principal, $this->school));
    }

    #[Test]
    public function staff_identity_and_recipient_history_still_decide_what_is_shown(): void
    {
        $this->publishedEvent($this->school, $this->creator, $this->approver, ['title' => 'Open day']);
        $leaver = $this->staff('librarian', $this->main);
        $roleOnly = $this->staff('librarian', $this->main, null);
        $this->staff('teacher', null); // a current colleague keeps the later audience non-empty
        $before = $this->publish('Before leaving');
        $this->inSchool(fn () => DB::table('employment_records')
            ->whereIn('employee_id', DB::table('employees')->where('user_id', $leaver->id)->pluck('id'))
            ->update(['status' => 'separated', 'ends_on' => '2026-09-30']));
        $this->publishedEvent($this->school, $this->creator, $this->approver, ['title' => 'Staff meeting', 'audience_type' => 'staff']);
        $after = $this->publish('After leaving');

        $this->assertSame(['Open day'], $this->calendarTitles($roleOnly), 'Holding the key never makes anyone staff.');
        $this->assertSame([], collect($this->get('/app/communications')->viewData('page')['props']['items'])->pluck('title')->all(), 'Never snapshotted, so nothing to read.');
        $this->get("/app/communications/announcements/{$before->id}")->assertForbidden();

        $this->assertSame(['Open day'], $this->calendarTitles($leaver), 'A former Employee loses Staff Events.');
        $this->get("/app/communications/announcements/{$before->id}")->assertOk();
        $this->get("/app/communications/announcements/{$after->id}")->assertForbidden();
    }

    #[Test]
    public function activation_needs_an_active_grant_on_an_active_campus_of_the_same_school_and_the_module(): void
    {
        $resolver = app(CapabilityResolver::class);
        $librarian = $this->staff('librarian', $this->main);
        $this->assertSame(ConsumerCapabilities::SCOPE_INDEPENDENT, $this->sorted($resolver->schoolCapabilities($librarian, $this->school)));

        // Another School: a role-less membership there activates nothing.
        $other = $this->createSchool();
        $this->createMembership($librarian, $other);
        $this->assertSame([], $resolver->schoolCapabilities($librarian, $other));
        $this->signInTo($librarian, $other)->get('/app/communications')->assertForbidden();
        $this->get('/app/communications/calendar')->assertForbidden();

        // An inactive Campus confers nothing.
        $this->inSchool(fn () => $this->main->forceFill(['status' => 'inactive'])->save());
        $resolver->forgetCache($librarian, $this->school);
        $this->assertSame([], $resolver->schoolCapabilities($librarian, $this->school));
        $this->inSchool(fn () => $this->main->forceFill(['status' => 'active'])->save());
        $resolver->forgetCache($librarian, $this->school);

        // A disabled Communications module refuses both surfaces.
        $this->inSchool(fn () => DB::table('school_module_entitlements')->where('school_id', $this->school->id)->where('module_key', 'communications')->update(['enabled' => false]));
        app(SchoolModuleEntitlements::class)->forget($this->school->id);
        $this->signInTo($librarian, $this->school)->get('/app/communications')->assertForbidden();
        $this->get('/app/communications/calendar')->assertForbidden();
        $this->inSchool(fn () => DB::table('school_module_entitlements')->where('school_id', $this->school->id)->where('module_key', 'communications')->update(['enabled' => true]));
        app(SchoolModuleEntitlements::class)->forget($this->school->id);

        // A revoked grant confers nothing.
        $admin = $this->staff('school_admin', null);
        $membership = SchoolMembership::query()->where('user_id', $librarian->id)->where('school_id', $this->school->id)->firstOrFail();
        app(StaffAccessService::class)->revokeRole($this->school, $admin, $membership->id, 'librarian', $this->main->id);
        $resolver->forgetCache($librarian, $this->school);
        $this->assertSame([], $resolver->schoolCapabilities($librarian, $this->school));
        $this->signInTo($librarian, $this->school)->get('/app/communications')->assertForbidden();
    }

    #[Test]
    public function guardian_and_platform_accounts_gain_nothing(): void
    {
        $guardian = $this->portalGuardian($this->school);
        $this->signInTo($guardian['user'], $this->school)->get('/app/communications')->assertForbidden();
        $this->get('/app/communications/calendar')->assertForbidden();
        $this->get('/app/portal/communications')->assertOk();

        $root = $this->createPlatformRoot();
        $this->assertNotSame(200, $this->actingAs($root)->get('/app/communications')->getStatusCode());
    }

    /** @param  array<int, string>  $keys */
    private function sorted(array $keys): array
    {
        sort($keys);

        return array_values($keys);
    }
}
