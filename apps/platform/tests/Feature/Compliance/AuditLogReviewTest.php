<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Application\AuditLogReviewService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SchoolAuditEventEntry;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0L.4 -- Compliance: the School audit-log review
 * (/app/compliance/audit-log, ADR 0042 §10). Runs against the real demo
 * world so every persona's role is the seeded one: only School Admin and
 * Principal hold `school.audit.view`; the ledger is Highly Sensitive; the
 * v1 metadata allowlist is empty; each review is audited exactly once.
 */
class AuditLogReviewTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const URL = '/app/compliance/audit-log';

    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function as(User $user, ?School $school): static
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);

        return $school === null ? $this : $this->withHeader('X-School-Id', $school->id);
    }

    /** Writes a School audit event exactly as any module does. */
    private function record(School $school, string $eventType, array $metadata = [], ?User $actor = null, ?User $subject = null): SchoolAuditEvent
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, $eventType, actor: $actor, subject: $subject, metadata: $metadata));
    }

    /** @return list<string> */
    private function eventTypesIn(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->pluck('event_type')->all());
    }

    private function countIn(School $school, ?string $eventType = null): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)
            ->when($eventType, fn ($q) => $q->where('event_type', $eventType))
            ->count());
    }

    #[Test]
    public function school_admin_and_principal_can_review_their_schools_audit_log(): void
    {
        $result = $this->build();
        $this->record($result->school, 'test.marker.demo_school');

        foreach (['school.admin@example.test', 'principal@example.test'] as $email) {
            $this->as($this->user($email), $result->school)->get(self::URL)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('App/Compliance/AuditLog')
                    ->where('log.fields', SchoolAuditEventEntry::FIELDS)
                    ->where('log.pageSize', SchoolAuditEventReader::PAGE_SIZE)
                    ->where('log.entries', fn ($entries) => collect($entries)->pluck('eventType')->contains('test.marker.demo_school'))
                );
        }
    }

    #[Test]
    public function every_other_persona_is_refused_and_leaves_no_access_event(): void
    {
        $result = $this->build();
        $before = $this->countIn($result->school, AuditLogReviewService::ACCESS_EVENT);

        $refused = [
            'teacher@example.test', 'student@example.test', 'guardian01@example.test',
            'hr.payroll@example.test', 'finance.officer@example.test', 'library.operator@example.test',
            'transport.operator@example.test', 'reception@example.test', 'hostel.warden@example.test',
            'canteen.operator@example.test', 'communications@example.test',
        ];

        foreach ($refused as $email) {
            $this->as($this->user($email), $result->school)->get(self::URL)->assertForbidden();
        }

        // Platform Super Admin: no implicit School access, with or without
        // naming a School -- it never has a School context, so the page
        // returns it to the /app landing (Phase 0N.1, RequireSchoolContext).
        $platformAdmin = $this->user('platform.admin@example.test');
        $this->as($platformAdmin, null)->get(self::URL)->assertRedirect('/app');
        $this->as($platformAdmin, $result->school)->get(self::URL)->assertRedirect('/app');

        $this->assertSame($before, $this->countIn($result->school, AuditLogReviewService::ACCESS_EVENT));
    }

    #[Test]
    public function only_the_school_admin_and_principal_roles_hold_school_audit_view(): void
    {
        $this->build();

        // Every role, including the demo-only operations-desk and HR &
        // Payroll roles the demo world creates.
        $roles = DB::table('role_capabilities')
            ->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('role_capabilities.capability_key', AuditLogReviewService::VIEW_CAPABILITY)
            ->orderBy('roles.key')
            ->pluck('roles.key')
            ->all();

        $this->assertSame(['principal', 'school_admin'], $roles);
    }

    #[Test]
    public function guests_are_sent_to_login_and_a_member_without_a_selected_school_is_refused(): void
    {
        $this->build();

        $this->get(self::URL)->assertRedirect('/login');

        // Has the capability once a School is selected, but none is: back
        // to the /app landing to select one (Phase 0N.1), never the page.
        $this->as($this->user('school.admin@example.test'), null)->get(self::URL)->assertRedirect('/app');
    }

    #[Test]
    public function each_school_sees_only_its_own_events_including_a_multi_school_member(): void
    {
        $result = $this->build();
        $this->record($result->school, 'test.marker.demo_school');
        $this->record($result->secondSchool, 'test.marker.annexe');

        $types = fn ($response): array => collect($response->viewData('page')['props']['log']['entries'])->pluck('eventType')->all();

        $annexe = $this->as($this->user('annexe.admin@example.test'), $result->secondSchool)->get(self::URL)->assertOk();
        $this->assertContains('test.marker.annexe', $types($annexe));
        $this->assertNotContains('test.marker.demo_school', $types($annexe));

        // Principal at the Demo School, School Admin at the Annexe.
        $multi = $this->user('multi.school@example.test');
        $demo = $this->as($multi, $result->school)->get(self::URL)->assertOk();
        $this->assertContains('test.marker.demo_school', $types($demo));
        $this->assertNotContains('test.marker.annexe', $types($demo));

        $second = $this->as($multi, $result->secondSchool)->get(self::URL)->assertOk();
        $this->assertContains('test.marker.annexe', $types($second));
        $this->assertNotContains('test.marker.demo_school', $types($second));

        // The Demo School's own admin cannot reach the Annexe by naming it:
        // naming a School is not membership, so there is no School context.
        $this->as($this->user('school.admin@example.test'), $result->secondSchool)->get(self::URL)->assertRedirect('/app');
    }

    #[Test]
    public function only_the_approved_envelope_fields_are_serialized_and_metadata_never_is(): void
    {
        $result = $this->build();
        $employee = $this->user('hr.payroll@example.test');
        $this->record($result->school, 'payroll.statutory.identifier.revealed', [
            'identifierType' => 'pan',
            'value' => 'ABCDE1234F',
            'note' => 'LEAK-CANARY-METADATA',
            'nested' => ['salary' => '98765.43'],
        ], subject: $employee);

        $response = $this->as($this->user('school.admin@example.test'), $result->school)->get(self::URL)->assertOk();
        $entries = $response->viewData('page')['props']['log']['entries'];

        foreach ($entries as $entry) {
            $this->assertSame(SchoolAuditEventEntry::FIELDS, array_keys($entry));
        }

        $revealed = collect($entries)->firstWhere('eventType', 'payroll.statutory.identifier.revealed');
        $this->assertSame('User', $revealed['subjectType']);
        $this->assertSame($employee->id, $revealed['subjectId']);

        foreach (['LEAK-CANARY-METADATA', 'ABCDE1234F', '98765.43', 'identifierType', 'metadata', 'App\\Models\\User', 'App\\\\Models\\\\User', $result->school->id] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $response->getContent(), "Page leaked [{$forbidden}].");
        }
    }

    #[Test]
    public function each_review_records_exactly_one_access_event_with_no_viewed_content(): void
    {
        $result = $this->build();
        $admin = $this->user('school.admin@example.test');
        $this->record($result->school, 'test.marker.with_metadata', ['note' => 'LEAK-CANARY-METADATA']);

        $totalBefore = $this->countIn($result->school);
        $this->as($admin, $result->school)->get(self::URL)->assertOk();
        $this->assertSame($totalBefore + 1, $this->countIn($result->school), 'One review writes exactly one event.');

        $this->as($admin, $result->school)->get(self::URL)->assertOk();
        $this->assertSame($totalBefore + 2, $this->countIn($result->school), 'A repeat review writes one more, nothing recursive.');

        $events = app(TenantContext::class)->withSchool($result->school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $result->school->id)
            ->where('event_type', AuditLogReviewService::ACCESS_EVENT)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->get());

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame($admin->id, $event->getAttribute('actor_user_id'));
            $this->assertNull($event->subject_type);
            $this->assertSame(['paged', 'resultCount'], array_keys($event->metadata));
            $this->assertFalse($event->metadata['paged']);
            $this->assertStringNotContainsString('LEAK-CANARY-METADATA', json_encode($event->metadata));
        }

        // The previous review appears on the next one, like any event.
        $latest = $this->as($admin, $result->school)->get(self::URL)->viewData('page')['props']['log']['entries'][0];
        $this->assertSame(AuditLogReviewService::ACCESS_EVENT, $latest['eventType']);
    }

    #[Test]
    public function pages_are_bounded_newest_first_and_stable_while_reviews_append_events(): void
    {
        $school = $this->createEmptySchoolWithReviewer();
        $reviewer = $school['reviewer'];

        for ($i = 0; $i < 120; $i++) {
            $this->record($school['school'], 'test.bulk.'.$i);
        }

        $expected = app(TenantContext::class)->withSchool($school['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $school['school']->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->pluck('id')->all());

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $props = $this->as($reviewer, $school['school'])->get(self::URL.($cursor ? '?cursor='.$cursor : ''))
                ->assertOk()->viewData('page')['props']['log'];
            $this->assertLessThanOrEqual(SchoolAuditEventReader::PAGE_SIZE, count($props['entries']));
            $seen = [...$seen, ...array_column($props['entries'], 'id')];
            $cursor = $props['nextCursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        // Every page after the first starts below the previous page's last
        // row, so the access events each review appended never shift,
        // repeat or skip a row.
        $this->assertSame(3, $pages);
        $this->assertSame($expected, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    #[Test]
    public function a_cursor_cannot_leave_the_selected_school_and_a_forged_one_is_rejected(): void
    {
        $result = $this->build();
        $a = $result->school;
        $b = $result->secondSchool;
        for ($i = 0; $i < 60; $i++) {
            $this->record($b, 'test.annexe.'.$i);
        }

        // A real cursor issued for School B, replayed by School A's viewer.
        $bCursor = $this->as($this->user('annexe.admin@example.test'), $b)->get(self::URL)->viewData('page')['props']['log']['nextCursor'];
        $this->assertNotNull($bCursor);

        $entries = $this->as($this->user('school.admin@example.test'), $a)->get(self::URL.'?cursor='.$bCursor)
            ->assertOk()->viewData('page')['props']['log']['entries'];
        $aIds = app(TenantContext::class)->withSchool($a, fn () => SchoolAuditEvent::query()->where('school_id', $a->id)->pluck('id')->all());
        $this->assertEmpty(array_diff(array_column($entries, 'id'), $aIds));
        $this->assertNotContains('test.annexe.0', array_column($entries, 'eventType'));

        $before = $this->countIn($a, AuditLogReviewService::ACCESS_EVENT);
        foreach (['not-a-cursor', base64_encode('2026-01-01 00:00:00|not-a-uuid'), base64_encode("1' OR '1'='1|".Str::uuid())] as $forged) {
            $this->as($this->user('school.admin@example.test'), $a)->get(self::URL.'?cursor='.urlencode($forged))
                ->assertSessionHasErrors('cursor');
        }
        $this->assertSame($before, $this->countIn($a, AuditLogReviewService::ACCESS_EVENT));
    }

    #[Test]
    public function the_dashboard_link_follows_school_audit_view(): void
    {
        $result = $this->build();

        foreach (['school.admin@example.test' => true, 'principal@example.test' => true, 'teacher@example.test' => false, 'hr.payroll@example.test' => false, 'finance.officer@example.test' => false, 'student@example.test' => false, 'guardian01@example.test' => false] as $email => $visible) {
            $this->as($this->user($email), $result->school)->get('/app')
                ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewAuditLog', $visible));
        }
    }

    /** @return array{school: School, reviewer: User} */
    private function createEmptySchoolWithReviewer(): array
    {
        $school = $this->createSchool();
        $reviewer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($reviewer, $school), 'principal');

        return ['school' => $school, 'reviewer' => $reviewer];
    }
}
