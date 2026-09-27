<?php

namespace Tests\Feature\CustomDomains;

use App\Domain\Platform\Application\Domains\SchoolDomainAudit;
use App\Domain\Platform\Application\Domains\SchoolDomainException;
use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolDomain;
use App\Support\Domains\DomainState;
use App\Support\Domains\HostnameRejected;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 3-4, 7.3-7.4, 10): School-side domain
 * management through SchoolDomainService -- claims, the non-disclosing
 * "not available", the per-School limit, challenge regeneration, the primary
 * choice, revocation with a required replacement, operator revocation, and
 * the capability checks inside the service itself.
 */
class SchoolDomainServiceTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    private function service(): SchoolDomainService
    {
        return app(SchoolDomainService::class);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (SchoolDomainException $e) {
            return $e->refusal.($e->reason !== null ? ':'.$e->reason : '');
        }

        $this->fail('expected a refusal');
    }

    /** @return list<array{string, array<string, mixed>}> */
    private function schoolEvents(School $school, string $prefix = 'school.domain.'): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)->where('event_type', 'like', $prefix.'%')
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (SchoolAuditEvent $e) => [$e->event_type, $e->metadata])->all());
    }

    #[Test]
    public function a_claim_is_a_pending_row_with_a_fresh_encrypted_43_character_challenge(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();

        $domain = $this->service()->claim($school, $admin, '  ERP.Northfield.ORG. ');

        $this->assertSame('erp.northfield.org', $domain->hostname);
        $this->assertSame(DomainState::PendingVerification, $domain->state);
        $this->assertFalse($domain->is_primary);
        $this->assertSame(1, $domain->challenge_generation);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $domain->challenge_token);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $domain->challenge_expires_at->timestamp, 5);

        $raw = (string) DB::table('school_domains')->where('id', $domain->id)->value('challenge_token');
        $this->assertStringNotContainsString((string) $domain->challenge_token, $raw, 'encrypted at rest');
        $this->assertSame($domain->challenge_token, decrypt($raw, false), 'recoverable for every later check (never hash-only)');
        $this->assertArrayNotHasKey('challenge_token', $domain->toArray(), 'never serialized');

        $other = $this->service()->claim($school, $admin, 'portal.northfield.org');
        $this->assertNotSame($domain->challenge_token, $other->challenge_token);

        $events = $this->schoolEvents($school);
        $this->assertEquals([SchoolDomainAudit::CLAIMED, ['hostname' => 'erp.northfield.org', 'from_state' => null, 'to_state' => 'pending_verification', 'outcome' => 'claimed']], $events[0]);
        $this->assertStringNotContainsString((string) $domain->challenge_token, json_encode($events), 'the challenge is never audited');
    }

    #[Test]
    public function unclaimable_hostnames_are_refused_with_a_closed_reason(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        config(['app.url' => 'https://app.lycenza-platform.com', 'domains.reserved_suffixes' => ['lycenza-mail.org']]);

        foreach ([
            'https://erp.northfield.org' => HostnameRejected::SYNTAX,
            'erp.northfield.org:8443' => HostnameRejected::SYNTAX,
            '*.northfield.org' => HostnameRejected::SYNTAX,
            '192.0.2.4' => HostnameRejected::IP_LITERAL,
            'xn--bcher-kva.northfield.org' => HostnameRejected::IDN,
            'bücher.northfield.org' => HostnameRejected::IDN,
            'co.uk' => HostnameRejected::PUBLIC_SUFFIX,
            'github.io' => HostnameRejected::PUBLIC_SUFFIX,
            'erp.northfield.notatld' => HostnameRejected::UNKNOWN_SUFFIX,
            'school.lycenza-platform.com' => HostnameRejected::RESERVED,
            'x.lycenza-mail.org' => HostnameRejected::RESERVED,
            'edge.lycenza-local.invalid' => HostnameRejected::LOCAL_NAME,
            'erp.northfield.test' => HostnameRejected::LOCAL_NAME,
        ] as $input => $reason) {
            $this->assertSame('invalid_hostname:'.$reason, $this->refusal(fn () => $this->service()->claim($school, $admin, $input)), $input);
        }

        $this->assertSame(0, SchoolDomain::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function a_hostname_held_by_another_school_is_simply_not_available(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin();
        [$adminB, $schoolB] = $this->createSchoolAdmin();
        $this->service()->claim($schoolA, $adminA, 'erp.northfield.org');

        $this->assertSame('unavailable', $this->refusal(fn () => $this->service()->claim($schoolB, $adminB, 'erp.northfield.org')));
        $this->assertSame('unavailable', $this->refusal(fn () => $this->service()->claim($schoolA, $adminA, 'ERP.northfield.org')), 'the same School cannot claim twice either');
        $this->assertStringNotContainsString($schoolA->name, SchoolDomainException::MESSAGES['unavailable']);
    }

    #[Test]
    public function an_expired_pending_claim_is_expired_in_the_competing_claims_own_transaction(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin();
        [$adminB, $schoolB] = $this->createSchoolAdmin();
        $stale = $this->service()->claim($schoolA, $adminA, 'erp.northfield.org');

        $this->travel(25)->hours();

        $fresh = $this->service()->claim($schoolB, $adminB, 'erp.northfield.org');

        $this->assertSame(DomainState::Expired, $stale->refresh()->state, 'no scheduler needed');
        $this->assertSame($schoolB->id, $fresh->school_id);
        $this->assertSame([SchoolDomainAudit::EXPIRED], array_column($this->schoolEvents($schoolA, SchoolDomainAudit::EXPIRED), 0), "the previous claimant's history records it");
    }

    #[Test]
    public function a_school_holds_at_most_three_domains_and_history_does_not_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $first = $this->service()->claim($school, $admin, 'a.northfield.org');
        $this->service()->claim($school, $admin, 'b.northfield.org');
        $this->service()->claim($school, $admin, 'c.northfield.org');

        $this->assertSame('limit', $this->refusal(fn () => $this->service()->claim($school, $admin, 'd.northfield.org')));

        $this->service()->revoke($school, $admin, $first->id);
        $this->assertSame('d.northfield.org', $this->service()->claim($school, $admin, 'd.northfield.org')->hostname);
    }

    #[Test]
    public function regenerating_replaces_the_token_advances_the_generation_and_only_while_pending(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $domain = $this->service()->claim($school, $admin, 'erp.northfield.org');
        $old = $domain->challenge_token;

        $this->travel(20)->hours();
        $regenerated = $this->service()->regenerateChallenge($school, $admin, $domain->id);

        $this->assertNotSame($old, $regenerated->challenge_token);
        $this->assertSame(2, $regenerated->challenge_generation);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $regenerated->challenge_expires_at->timestamp, 5, 'the lifetime restarts');
        $this->assertSame(['generation' => 2], array_intersect_key($this->schoolEvents($school, SchoolDomainAudit::CHALLENGE_REGENERATED)[0][1], ['generation' => 1]));

        $active = $this->createSchoolDomain($school, 'portal.northfield.org');
        $this->assertSame('invalid_state', $this->refusal(fn () => $this->service()->regenerateChallenge($school, $admin, $active->id)), 'a verified token never rotates');
    }

    #[Test]
    public function the_primary_moves_only_to_an_active_domain_in_one_transaction(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');
        $pending = $this->service()->claim($school, $admin, 'c.northfield.org');

        $this->assertTrue($a->is_primary);
        $this->assertSame('invalid_state', $this->refusal(fn () => $this->service()->setPrimary($school, $admin, $pending->id)));

        $this->service()->setPrimary($school, $admin, $b->id);

        $this->assertSame([$b->id], SchoolDomain::query()->where('school_id', $school->id)->where('is_primary', true)->pluck('id')->all());
        $this->assertEquals(['hostname' => 'b.northfield.org', 'from_state' => 'active', 'to_state' => 'active', 'outcome' => 'primary_changed', 'previous_primary' => 'a.northfield.org'], $this->schoolEvents($school, SchoolDomainAudit::PRIMARY_CHANGED)[0][1]);
    }

    #[Test]
    public function revoking_the_primary_while_another_domain_is_active_requires_an_explicit_replacement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');
        $pending = $this->service()->claim($school, $admin, 'c.northfield.org');

        $this->assertSame('replacement_required', $this->refusal(fn () => $this->service()->revoke($school, $admin, $a->id)));
        $this->assertSame('replacement_required', $this->refusal(fn () => $this->service()->revoke($school, $admin, $a->id, $pending->id)), 'only an active domain can take over');
        $this->assertSame('replacement_required', $this->refusal(fn () => $this->service()->revoke($school, $admin, $a->id, $a->id)));

        $this->service()->revoke($school, $admin, $a->id, $b->id);

        $this->assertSame(DomainState::Revoked, $a->refresh()->state);
        $this->assertFalse($a->is_primary);
        $this->assertSame('school', $a->revocation_source);
        $this->assertTrue($b->refresh()->is_primary);

        // The last active domain: revoked with no replacement; links fall back to the platform.
        $this->service()->revoke($school, $admin, $b->id);
        $this->assertSame(0, SchoolDomain::query()->where('school_id', $school->id)->where('is_primary', true)->count());
        $this->assertSame('invalid_state', $this->refusal(fn () => $this->service()->regenerateChallenge($school, $admin, $a->id)));
        $this->assertSame('not_found', $this->refusal(fn () => $this->service()->revoke($school, $admin, $a->id)), 'terminal history is not revocable again');
    }

    #[Test]
    public function an_operator_revocation_is_audited_on_both_ledgers_and_promotes_the_oldest_alias(): void
    {
        $school = $this->createSchool();
        $a = $this->createSchoolDomain($school, 'a.northfield.org');
        $b = $this->createSchoolDomain($school, 'b.northfield.org');

        $this->assertSame(0, Artisan::call('platform:domain-revoke', ['hostname' => 'A.northfield.org', '--force' => true, '--no-interaction' => true]));

        $this->assertSame(DomainState::Revoked, $a->refresh()->state);
        $this->assertSame('operator', $a->revocation_source);
        $this->assertTrue($b->refresh()->is_primary);
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', SchoolDomainAudit::OPERATOR_REVOKED)->where('subject_id', $a->id)->count());
        $this->assertContains(SchoolDomainAudit::REVOKED, array_column($this->schoolEvents($school), 0));

        $this->assertSame(1, Artisan::call('platform:domain-revoke', ['hostname' => 'a.northfield.org', '--force' => true, '--no-interaction' => true]), 'nothing claiming left');
    }

    #[Test]
    public function the_service_itself_checks_capabilities_and_the_school_lifecycle(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW]);
        $outsider = $this->createUser();
        $root = $this->createPlatformRoot();

        foreach ([$viewer, $outsider, $root] as $actor) {
            try {
                $this->service()->claim($school, $actor, 'erp.northfield.org');
                $this->fail('refused');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->service()->listFor($school, $viewer));

        $manager = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_MANAGE, SchoolDomainService::CAPABILITY_VIEW]);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);
        $this->assertSame('school_not_active', $this->refusal(fn () => $this->service()->claim($school->refresh(), $manager, 'erp.northfield.org')));
    }

    #[Test]
    public function the_listing_shows_the_dns_record_to_managers_only_and_never_another_schools_rows(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $other = $this->createSchool();
        $mine = $this->service()->claim($school, $admin, 'erp.northfield.org');
        $this->createSchoolDomain($other, 'erp.southfield.org');
        $viewer = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_VIEW]);

        $managerRows = $this->service()->listFor($school, $admin);
        $this->assertSame(['erp.northfield.org'], array_column($managerRows, 'hostname'));
        $this->assertSame(['type' => 'TXT', 'name' => '_lycenza-verification.erp.northfield.org', 'value' => 'lycenza-domain-verification='.$mine->challenge_token], $managerRows[0]['dnsRecord']);
        $this->assertSame('Waiting for DNS verification', $managerRows[0]['label']);

        $this->assertNull($this->service()->listFor($school, $viewer)[0]['dnsRecord']);
        $this->assertStringNotContainsString('southfield', json_encode($this->service()->listFor($school, $viewer)));
    }
}
