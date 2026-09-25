<?php

namespace Tests\Feature\Platform\Audit;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformAuditEvent;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\PlatformAuditEventEntry;
use App\Support\Audit\PlatformAuditEventReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0N.7 (ADR 0046 sections 7-11): the platform audit log review --
 * `platform.audit.view` plus current MFA assurance, context-neutral, the
 * seven envelope fields only, keyset pages of 50, one access event per
 * review, and never the School ledger.
 */
class PlatformAuditLogReviewTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function withAssurance(User $user): static
    {
        return $this->actingAs($user)->withSession(['mfa_verified_at' => now()->toIso8601String()]);
    }

    private function auditor(): User
    {
        $auditor = $this->createUser();
        app(PlatformRoleGovernanceService::class)->grant($this->platformAdmin(), $auditor->email, 'platform_auditor');
        $this->enrollActiveMfaFactor($auditor);

        return $auditor;
    }

    private function views(User $actor): int
    {
        return PlatformAuditEvent::query()->where('event_type', 'platform.audit_log.viewed')->where('actor_user_id', $actor->id)->count();
    }

    #[Test]
    public function the_root_and_the_auditor_review_the_ledger_with_mfa_assurance(): void
    {
        foreach ([$this->platformAdmin(), $this->auditor()] as $reviewer) {
            $this->withAssurance($reviewer)->get('/app/platform/audit-log')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
                ->component('App/Platform/AuditLog')
                ->where('log.fields', PlatformAuditEventEntry::FIELDS)
                ->where('log.pageSize', 50)
            );
            $this->assertSame(1, $this->views($reviewer));
        }
    }

    #[Test]
    public function no_one_else_reviews_it_and_mfa_is_required_not_merely_the_capability(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        [$schoolAdmin] = $this->createSchoolAdmin('school_admin');
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');

        foreach ([$this->groupAdmin($group), $schoolAdmin, $principal, $this->createUser()] as $user) {
            if (! $user->mfaFactors()->where('status', 'active')->exists()) {
                $this->enrollActiveMfaFactor($user);
            }
            $this->withAssurance($user)->get('/app/platform/audit-log')->assertForbidden();
            $this->assertSame(0, $this->views($user));
        }

        // Capability, no factor: 403 mfa_required_not_enrolled.
        $noFactor = $this->platformAdmin(withMfa: false);
        $this->withAssurance($noFactor)->get('/app/platform/audit-log')->assertForbidden()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Platform/MfaRequired')->where('code', 'mfa_required_not_enrolled'));
        $this->getJson('/app/platform/audit-log')->assertForbidden()->assertJsonPath('error.code', 'mfa_required_not_enrolled');

        // Capability and factor, but assurance missing or lapsed: 401 mfa_step_up_required.
        $stale = $this->platformAdmin();
        $this->actingAs($stale)->withSession(['mfa_verified_at' => now()->subMinutes((int) config('mfa.assurance_window_minutes') + 1)->toIso8601String()]);
        $this->get('/app/platform/audit-log')->assertStatus(401)->assertInertia(fn (AssertableInertia $p) => $p->where('code', 'mfa_step_up_required'));
        $this->actingAs($stale)->withSession(['mfa_verified_at' => null]);
        $this->getJson('/app/platform/audit-log')->assertStatus(401)->assertJsonPath('error.code', 'mfa_step_up_required');

        $this->assertSame(0, $this->views($noFactor) + $this->views($stale));
    }

    #[Test]
    public function only_the_seven_envelope_fields_ever_leave_the_server(): void
    {
        $reviewer = $this->platformAdmin();
        $subject = $this->createUser();
        app(AuditRecorder::class)->platform(
            'test.canary.event',
            actor: $subject,
            subject: $subject,
            metadata: ['secret' => 'CANARY-METADATA-VALUE', 'nested' => ['k' => 'CANARY-NESTED']],
            ipAddress: '203.0.113.77',
            userAgent: 'CanaryAgent/9.9 (CANARY-UA)',
        );

        $response = $this->withAssurance($reviewer)->get('/app/platform/audit-log');
        $content = $response->getContent();

        foreach (['CANARY-METADATA-VALUE', 'CANARY-NESTED', '203.0.113.77', 'CANARY-UA', 'App\\\\Models\\\\User', 'App\\Models\\User'] as $canary) {
            $this->assertStringNotContainsString($canary, $content, $canary);
        }

        $entries = $response->viewData('page')['props']['log']['entries'];
        $canaryRow = collect($entries)->firstWhere('eventType', 'test.canary.event');
        $this->assertSame(PlatformAuditEventEntry::FIELDS, array_keys($canaryRow));
        $this->assertSame('User', $canaryRow['subjectType']);
        $this->assertSame($subject->id, $canaryRow['subjectId']);
        $this->assertSame($subject->id, $canaryRow['actorUserId']);

        foreach ($entries as $entry) {
            $this->assertSame(PlatformAuditEventEntry::FIELDS, array_keys($entry));
        }
    }

    #[Test]
    public function the_reader_never_selects_metadata_ip_or_user_agent_from_postgres(): void
    {
        app(AuditRecorder::class)->platform('test.select', metadata: ['x' => 1], ipAddress: '198.51.100.1', userAgent: 'UA');

        DB::enableQueryLog();
        app(PlatformAuditEventReader::class)->page();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertStringContainsString('platform_audit_events', $sql);
        foreach (['metadata', 'ip_address', 'user_agent', '*', 'school_audit_events'] as $never) {
            $this->assertStringNotContainsString($never, $sql, $never);
        }
    }

    #[Test]
    public function pages_are_fifty_newest_first_by_keyset_and_every_view_is_audited_once(): void
    {
        $reviewer = $this->platformAdmin();
        $base = now()->subDay()->startOfSecond();
        for ($i = 0; $i < 60; $i++) {
            PlatformAuditEvent::query()->create(['occurred_at' => $base->copy()->addSeconds(intdiv($i, 3)), 'event_type' => 'test.page.'.$i]);
        }

        $first = $this->withAssurance($reviewer)->get('/app/platform/audit-log')->assertOk()->viewData('page')['props']['log'];
        $this->assertCount(50, $first['entries']);
        $this->assertNotNull($first['nextCursor']);
        $this->assertSame(1, $this->views($reviewer));

        $second = $this->get('/app/platform/audit-log?cursor='.urlencode($first['nextCursor']))->assertOk()->viewData('page')['props']['log'];
        $this->assertSame(2, $this->views($reviewer));

        $all = array_merge($first['entries'], $second['entries']);
        $ids = array_column($all, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'No row repeats across pages.');
        $keys = array_map(fn ($e) => [$e['occurredAt'], $e['id']], $all);
        $sorted = $keys;
        usort($sorted, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $this->assertSame($sorted, $keys, 'Strictly newest first, ties broken by id.');

        $viewEvents = PlatformAuditEvent::query()->where('event_type', 'platform.audit_log.viewed')->where('actor_user_id', $reviewer->id)->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertEquals(['paged' => false, 'resultCount' => 50], $viewEvents[0]->metadata);
        $this->assertTrue($viewEvents[1]->metadata['paged']);
        $this->assertSame(['paged', 'resultCount'], array_keys($viewEvents[1]->metadata));

        foreach (['not-a-cursor', base64_encode('2026-01-01 00:00:00|'.Str::uuid()).'!!', base64_encode('x|y')] as $bad) {
            $this->get('/app/platform/audit-log?cursor='.urlencode($bad))->assertSessionHasErrors('cursor');
        }
        $this->assertSame(2, $this->views($reviewer), 'A refused page is not a review.');
    }

    #[Test]
    public function the_review_is_context_neutral_and_never_touches_the_school_ledger(): void
    {
        $reviewer = $this->platformAdmin();
        $school = $this->createSchool();
        app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, 'test.school.only'));
        app(AuditRecorder::class)->platform('test.platform.only');

        $route = app(Router::class)->getRoutes()->getByName('app.platform.audit-log');
        foreach ($route->gatherMiddleware() as $middleware) {
            $this->assertFalse(is_string($middleware) && str_starts_with($middleware, 'school-context'));
        }

        $log = $this->withAssurance($reviewer)->get('/app/platform/audit-log')->assertOk()->viewData('page')['props']['log'];
        $types = array_column($log['entries'], 'eventType');
        $this->assertContains('test.platform.only', $types);
        $this->assertNotContains('test.school.only', $types);
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);

        // School audit review is unchanged and still hides elevation_id.
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'test.school.only')->count()));
        $this->assertStringNotContainsString('platform_audit_events', file_get_contents(app_path('Support/Audit/SchoolAuditEventReader.php')));
        $this->assertStringNotContainsString('school_audit_events', file_get_contents(app_path('Support/Audit/PlatformAuditEventReader.php')));
    }

    #[Test]
    public function elevation_and_group_events_show_as_envelopes_and_elevation_grants_no_review(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group);
        $this->elevateViaGroup($groupAdmin, $group, $school);

        // Elevated, but without platform.audit.view.
        $this->withSession(['mfa_verified_at' => now()->toIso8601String()])->get('/app/platform/audit-log')->assertForbidden();

        $log = $this->withAssurance($root)->get('/app/platform/audit-log')->assertOk()->viewData('page')['props']['log'];
        $this->assertContains('platform.school_elevation.started', array_column($log['entries'], 'eventType'));
        $this->assertStringNotContainsString('group_role_assignment_id', json_encode($log));
        $this->assertStringNotContainsString('reason_code', json_encode($log));

        // Not exposed through the API.
        foreach (app(Router::class)->getRoutes()->getRoutes() as $r) {
            if (Str::startsWith($r->uri(), 'api/')) {
                $this->assertStringNotContainsString('audit-log', $r->uri());
            }
        }
    }
}
