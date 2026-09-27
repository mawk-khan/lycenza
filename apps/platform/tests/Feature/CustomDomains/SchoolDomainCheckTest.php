<?php

namespace Tests\Feature\CustomDomains;

use App\Domain\Platform\Application\Domains\SchoolDomainAudit;
use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolDomain;
use App\Support\Domains\Dns\DnsLookup;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainSignals;
use App\Support\Domains\DomainState;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Domains\Probe\DomainProber;
use App\Support\Domains\Probe\FakeDomainProber;
use App\Support\Domains\Probe\ProbeResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 4-7): the evidence-driven lifecycle with the
 * double-guarded fakes (no real DNS or TLS) and injected time -- activation
 * only when ownership, routing, the TLS probe and the School all pass in one
 * decision; the frozen drift thresholds; automatic recovery; primary
 * hand-over; and the Host cache forgotten on every transition.
 */
class SchoolDomainCheckTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    private const HOST = 'erp.northfield.org';

    private function dns(): FakeDomainDnsResolver
    {
        $dns = app(DomainDnsResolver::class);
        $this->assertInstanceOf(FakeDomainDnsResolver::class, $dns, 'the fake is bound in testing (double guard)');

        return $dns;
    }

    private function prober(): FakeDomainProber
    {
        $prober = app(DomainProber::class);
        $this->assertInstanceOf(FakeDomainProber::class, $prober);

        return $prober;
    }

    private function check(SchoolDomain $domain): DomainState
    {
        return app(SchoolDomainCheckService::class)->check($domain);
    }

    /** Publish everything a healthy domain needs. */
    private function healthy(SchoolDomain $domain): void
    {
        $this->dns()->publishTxt(OwnershipVerifier::recordName($domain->hostname), [OwnershipVerifier::recordValue((string) $domain->refresh()->challenge_token)]);
        $this->dns()->publishAddresses($domain->hostname, ['1.2.3.4']);
        $this->prober()->forget($domain->hostname);
    }

    private function claim(School $school, string $hostname = self::HOST): SchoolDomain
    {
        [$admin] = [$this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_MANAGE, SchoolDomainService::CAPABILITY_VIEW])];

        return app(SchoolDomainService::class)->claim($school, $admin, $hostname);
    }

    /** @return list<string> */
    private function events(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('school_id', $school->id)
            ->where('event_type', 'like', 'school.domain.%')->orderBy('occurred_at')->orderBy('id')->pluck('event_type')->all());
    }

    #[Test]
    public function a_healthy_domain_walks_from_claim_to_active_primary_on_evidence_alone(): void
    {
        $school = $this->createSchool();
        $domain = $this->claim($school);
        $this->healthy($domain);

        $this->assertSame(DomainState::Active, $this->check($domain));

        $domain->refresh();
        $this->assertTrue($domain->is_primary, 'the first active domain becomes primary');
        $this->assertSame(['match', 'pass', 'pass'], [$domain->ownership_outcome, $domain->routing_outcome, $domain->tls_outcome]);
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->activated_at);
        $this->assertTrue($domain->certificate_not_after->isFuture());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $domain->certificate_fingerprint);
        $this->assertSame([SchoolDomainAudit::CLAIMED, SchoolDomainAudit::VERIFIED, SchoolDomainAudit::TLS_PENDING, SchoolDomainAudit::ACTIVATED], $this->events($school));

        $second = $this->claim($school, 'portal.northfield.org');
        $this->healthy($second);
        $this->check($second);
        $this->assertFalse($second->refresh()->is_primary, 'later ones are aliases');
    }

    #[Test]
    public function each_missing_piece_of_evidence_stops_the_walk_where_it_is(): void
    {
        $school = $this->createSchool();
        $domain = $this->claim($school);

        // No TXT yet / a wrong value: still pending.
        $this->assertSame(DomainState::PendingVerification, $this->check($domain));
        $this->assertSame('absent', $domain->refresh()->ownership_outcome);
        $this->dns()->publishTxt(OwnershipVerifier::recordName(self::HOST), [OwnershipVerifier::recordValue('wrong-'.substr((string) $domain->challenge_token, 6))]);
        $this->assertSame(DomainState::PendingVerification, $this->check($domain));
        $this->assertSame('mismatch', $domain->refresh()->ownership_outcome);

        // Ownership alone is not routing: a pointer elsewhere stops at verified.
        $this->dns()->publishTxt(OwnershipVerifier::recordName(self::HOST), [OwnershipVerifier::recordValue((string) $domain->challenge_token)]);
        $this->dns()->publishAddresses(self::HOST, ['5.6.7.8']);
        $this->assertSame(DomainState::Verified, $this->check($domain));

        // A private target never activates, even via the edge CNAME.
        $this->dns()->publishAddresses(self::HOST, ['1.2.3.4', '10.0.0.8'], [self::HOST, 'edge.lycenza-local.invalid']);
        $this->assertSame(DomainState::Verified, $this->check($domain));

        // Routed, but the edge serves an untrusted certificate: tls_pending.
        $this->dns()->publishAddresses(self::HOST, ['1.2.3.4']);
        $this->prober()->script(self::HOST, ProbeResult::TLS_INVALID, 'untrusted');
        $this->assertSame(DomainState::TlsPending, $this->check($domain));
        $this->assertSame('tls_invalid', $domain->refresh()->tls_outcome);

        // The proof must come from THIS deployment.
        $this->prober()->script(self::HOST, ProbeResult::PROOF_MISMATCH, 'proof');
        $this->assertSame(DomainState::TlsPending, $this->check($domain));

        // Ownership must still hold at activation time.
        $this->prober()->forget(self::HOST);
        $this->dns()->fail('txt', OwnershipVerifier::recordName(self::HOST), DnsLookup::ABSENT, 'nxdomain');
        $this->assertSame(DomainState::TlsPending, $this->check($domain));

        // And without a probe key nothing can prove readiness.
        $this->healthy($domain);
        $key = config('domains.probe_key');
        config(['domains.probe_key' => null]);
        $this->assertSame(DomainState::TlsPending, $this->check($domain));
        config(['domains.probe_key' => $key]);
        $this->assertSame(DomainState::Active, $this->check($domain));
    }

    #[Test]
    public function a_challenge_expires_after_24_hours_and_a_regenerated_one_invalidates_the_old_value(): void
    {
        $school = $this->createSchool();
        $manager = $this->createUserWithCapabilities($school, [SchoolDomainService::CAPABILITY_MANAGE]);
        $domain = app(SchoolDomainService::class)->claim($school, $manager, self::HOST);
        $oldValue = OwnershipVerifier::recordValue((string) $domain->challenge_token);

        app(SchoolDomainService::class)->regenerateChallenge($school, $manager, $domain->id);
        $this->dns()->publishTxt(OwnershipVerifier::recordName(self::HOST), [$oldValue]);
        $this->assertSame(DomainState::PendingVerification, $this->check($domain), 'the previous generation can never verify');

        $expiring = app(SchoolDomainService::class)->claim($school, $manager, 'portal.northfield.org');
        $this->healthy($expiring);
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $this->assertSame(DomainState::Expired, $this->check($expiring), 'even a now-published record is too late');
        $this->assertContains(SchoolDomainAudit::EXPIRED, $this->events($school));
    }

    #[Test]
    public function a_non_active_school_is_never_activated_and_its_domains_are_left_alone(): void
    {
        $school = $this->createSchool();
        $domain = $this->createSchoolDomain($school, self::HOST, DomainState::TlsPending);
        $this->healthy($domain);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        $this->assertSame(DomainState::TlsPending, $this->check($domain));
        $this->assertNull($domain->refresh()->tls_checked_at, 'no checks at all for a suspended School');

        $live = $this->createSchoolDomain($this->createSchool(), 'erp.southfield.org');
        DB::table('schools')->where('id', $live->school_id)->update(['status' => 'suspended']);
        $this->dns()->fail('txt', OwnershipVerifier::recordName('erp.southfield.org'), DnsLookup::ABSENT, 'nxdomain');
        $this->travel(3)->days();
        $this->check($live);
        $this->assertSame(DomainState::Active, $live->refresh()->state, 'School lifecycle never suspends or revokes a domain');
    }

    #[Test]
    public function an_explicitly_changed_txt_value_suspends_on_the_second_result_at_least_an_hour_apart(): void
    {
        $school = $this->createSchool();
        $domain = $this->createSchoolDomain($school, self::HOST);
        $this->healthy($domain);
        $this->dns()->publishTxt(OwnershipVerifier::recordName(self::HOST), ['lycenza-domain-verification=someone-else-now']);

        $this->assertSame(DomainState::Active, $this->check($domain));
        $this->assertSame(1, $domain->refresh()->ownership_mismatch_count);
        $this->travel(59)->minutes();
        $this->assertSame(DomainState::Active, $this->check($domain), 'two results inside an hour are not enough');
        $this->travel(2)->minutes();
        $this->assertSame(DomainState::Suspended, $this->check($domain));

        $domain->refresh();
        $this->assertSame('ownership', $domain->suspension_reason);
        $this->assertFalse($domain->is_primary);
        $this->assertContains(SchoolDomainAudit::SUSPENDED, $this->events($school));
    }

    #[Test]
    public function a_missing_txt_record_suspends_only_after_three_results_spanning_48_hours(): void
    {
        $domain = $this->createSchoolDomain($this->createSchool(), self::HOST);
        $this->healthy($domain);
        $this->dns()->fail('txt', OwnershipVerifier::recordName(self::HOST), DnsLookup::ABSENT, 'nxdomain');

        $this->assertSame(DomainState::Active, $this->check($domain));
        $this->travel(24)->hours();
        $this->assertSame(DomainState::Active, $this->check($domain));
        $this->travel(23)->hours();
        $this->assertSame(DomainState::Active, $this->check($domain), 'three results but only 47 h');
        $this->travel(1)->hours();
        $this->assertSame(DomainState::Suspended, $this->check($domain));
        $this->assertSame('ownership', $domain->refresh()->suspension_reason);
    }

    #[Test]
    public function routing_and_tls_failures_suspend_on_the_second_result_an_hour_apart_and_a_pass_resets(): void
    {
        $routing = $this->createSchoolDomain($this->createSchool(), self::HOST);
        $this->healthy($routing);
        $this->dns()->publishAddresses(self::HOST, ['5.6.7.8']);
        $this->check($routing);
        $this->healthy($routing);
        $this->travel(2)->hours();
        $this->check($routing);
        $this->assertSame(0, $routing->refresh()->routing_fail_count, 'a pass resets the streak');
        $this->dns()->publishAddresses(self::HOST, ['5.6.7.8']);
        $this->check($routing);
        $this->travel(61)->minutes();
        $this->assertSame(DomainState::Suspended, $this->check($routing));
        $this->assertSame('routing', $routing->refresh()->suspension_reason);

        $tls = $this->createSchoolDomain($this->createSchool(), 'erp.southfield.org');
        $this->healthy($tls);
        $this->prober()->script('erp.southfield.org', ProbeResult::TLS_INVALID, 'expired');
        $this->check($tls);
        $this->travel(61)->minutes();
        $this->assertSame(DomainState::Suspended, $this->check($tls));
        $this->assertSame('tls', $tls->refresh()->suspension_reason);
    }

    #[Test]
    public function indeterminate_results_never_suspend_and_start_the_obs_30_clock(): void
    {
        $domain = $this->createSchoolDomain($this->createSchool(), self::HOST);
        $this->dns()->fail('txt', OwnershipVerifier::recordName(self::HOST), DnsLookup::INDETERMINATE, 'timeout');
        $this->dns()->fail('addr', self::HOST, DnsLookup::INDETERMINATE, 'servfail');

        $started = now()->toImmutable()->startOfSecond();
        foreach (range(1, 6) as $day) {
            $this->assertSame(DomainState::Active, $this->check($domain), "day {$day}");
            $this->travel(1)->days();
        }

        $domain->refresh();
        $this->assertSame(0, $domain->ownership_mismatch_count + $domain->ownership_absent_count + $domain->routing_fail_count + $domain->tls_fail_count);
        $this->assertEqualsWithDelta($started->timestamp, $domain->indeterminate_since->timestamp, 1, 'the streak started with the first indeterminate run');
        $this->assertGreaterThanOrEqual(3 * 86400, app(DomainSignals::class)->indeterminateMaxAgeSeconds());

        $this->healthy($domain);
        $this->check($domain);
        $this->assertNull($domain->refresh()->indeterminate_since, 'a conclusive run clears it');
    }

    #[Test]
    public function a_suspended_domain_recovers_on_the_same_row_only_when_everything_passes_and_the_school_is_active(): void
    {
        $school = $this->createSchool();
        $domain = $this->createSchoolDomain($school, self::HOST, DomainState::Suspended);
        $token = $domain->challenge_token;

        $this->dns()->fail('txt', OwnershipVerifier::recordName(self::HOST), DnsLookup::ABSENT, 'nxdomain');
        $this->dns()->publishAddresses(self::HOST, ['1.2.3.4']);
        $this->assertSame(DomainState::Suspended, $this->check($domain));

        $this->healthy($domain);
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);
        $this->assertSame(DomainState::Suspended, $this->check($domain));

        DB::table('schools')->where('id', $school->id)->update(['status' => 'active']);
        $this->assertSame(DomainState::Active, $this->check($domain));

        $domain->refresh();
        $this->assertSame($token, $domain->challenge_token, 'same row, same token');
        $this->assertTrue($domain->is_primary);
        $this->assertNull($domain->suspension_reason);
        $this->assertContains(SchoolDomainAudit::REACTIVATED, $this->events($school));

        $revoked = $this->createSchoolDomain($school, 'old.northfield.org', DomainState::Revoked);
        $this->healthy($revoked);
        $this->assertSame(DomainState::Revoked, $this->check($revoked), 'terminal rows never come back');
    }

    #[Test]
    public function suspending_the_primary_hands_it_to_the_oldest_active_alias(): void
    {
        $school = $this->createSchool();
        $primary = $this->createSchoolDomain($school, self::HOST);
        $alias = $this->createSchoolDomain($school, 'portal.northfield.org');
        $this->healthy($primary);
        $this->prober()->script(self::HOST, ProbeResult::TLS_INVALID, 'hostname_mismatch');

        $this->check($primary);
        $this->travel(61)->minutes();
        $this->check($primary);

        $this->assertSame(DomainState::Suspended, $primary->refresh()->state);
        $this->assertTrue($alias->refresh()->is_primary);
        $this->assertContains(SchoolDomainAudit::PRIMARY_CHANGED, $this->events($school));
    }

    #[Test]
    public function every_transition_forgets_the_host_cache(): void
    {
        $school = $this->createSchool();
        $domain = $this->createSchoolDomain($school, self::HOST);
        $directory = app(DomainDirectory::class);

        $this->assertSame('active', $directory->lookup(self::HOST)['state']);
        $this->assertSame('active', cache()->get('domains:host:'.self::HOST)['state'], 'cached positive lookup');

        $this->healthy($domain);
        $this->prober()->script(self::HOST, ProbeResult::PROOF_MISMATCH, 'proof');
        $this->check($domain);
        $this->travel(61)->minutes();
        $this->check($domain);

        $this->assertNull(cache()->get('domains:host:'.self::HOST), 'suspension forgot the entry');
        $this->assertSame('suspended', $directory->lookup(self::HOST)['state']);
    }

    #[Test]
    public function the_scheduler_queues_only_due_rows_bounded_per_run(): void
    {
        $school = $this->createSchool();
        $due = $this->createSchoolDomain($school, self::HOST);
        $later = $this->createSchoolDomain($school, 'portal.northfield.org');
        SchoolDomain::query()->whereKey($due->id)->update(['next_check_at' => now()->subMinute()]);
        SchoolDomain::query()->whereKey($later->id)->update(['next_check_at' => now()->addHour()]);
        $this->healthy($due);

        $this->assertSame(1, app(SchoolDomainCheckService::class)->dispatchDue(10));
        $this->assertNotNull($due->refresh()->last_checked_at, 'the (sync) job ran the check');
        $this->assertNull($later->refresh()->last_checked_at);
        $this->assertSame(0, app(SchoolDomainCheckService::class)->dispatchDue(10), 'nothing due any more');
    }
}
