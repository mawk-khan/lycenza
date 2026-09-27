<?php

namespace Tests\Feature\CustomDomains;

use App\Http\Middleware\ClassifyRequestHost;
use App\Support\Domains\DomainState;
use App\Support\Domains\Probe\ProbeProof;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 6.1-6.2, 7.4, 10.1): the public probe
 * endpoint -- a bounded HMAC answer for a tls_pending/active/suspended Host
 * and nothing else -- and the operator console commands.
 */
class DomainProbeAndOperatorTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    #[Test]
    public function the_probe_answers_only_the_hmac_for_an_eligible_host_and_a_fresh_nonce(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'tls.northfield.org', DomainState::TlsPending);
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->createSchoolDomain($this->createSchool(), 'held.southfield.org', DomainState::Suspended);
        $nonce = ProbeProof::nonce();

        foreach (['tls.northfield.org', 'erp.northfield.org', 'held.southfield.org'] as $host) {
            $response = $this->get("http://{$host}/.well-known/lycenza-domain-probe?n={$nonce}");
            $response->assertOk();
            $this->assertSame(hash_hmac('sha256', "{$host}|{$nonce}", (string) config('domains.probe_key')), $response->getContent(), $host);
            $this->assertSame([], $response->headers->getCookies(), 'no session, no cookie');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString((string) config('domains.probe_key'), $response->getContent());
        }

        $other = ProbeProof::nonce();
        $this->assertNotSame(
            $this->get("http://tls.northfield.org/.well-known/lycenza-domain-probe?n={$nonce}")->getContent(),
            $this->get("http://tls.northfield.org/.well-known/lycenza-domain-probe?n={$other}")->getContent(),
            'never a static, reusable answer',
        );

        // A tls_pending host serves the probe path and NOTHING else.
        foreach (['/login', '/app', '/', '/api/v1/me', '/api/health/live', '/session/handoff?ticket='.str_repeat('a', 43)] as $path) {
            $this->assertContains($this->get("http://tls.northfield.org{$path}")->getStatusCode(), [404, 421], $path);
        }
        $this->assertSame(421, $this->get('http://tls.northfield.org/login')->getStatusCode());
    }

    #[Test]
    public function the_probe_proves_nothing_for_anyone_else(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'pending.northfield.org', DomainState::PendingVerification);
        $this->createSchoolDomain($school, 'tls.northfield.org', DomainState::TlsPending);
        $nonce = ProbeProof::nonce();

        $this->assertSame(421, $this->get("http://pending.northfield.org/.well-known/lycenza-domain-probe?n={$nonce}")->getStatusCode());
        $this->assertSame(421, $this->get("http://unknown.northfield.org/.well-known/lycenza-domain-probe?n={$nonce}")->getStatusCode());
        $this->assertSame(ClassifyRequestHost::NOT_FOUND_BODY, $this->get("http://localhost/.well-known/lycenza-domain-probe?n={$nonce}")->assertNotFound()->getContent(), 'the platform host proves nothing');

        foreach (['', 'short', str_repeat('a', 44), str_repeat('!', 43)] as $bad) {
            $this->get('http://tls.northfield.org/.well-known/lycenza-domain-probe?n='.urlencode($bad))->assertNotFound();
        }

        config(['domains.probe_key' => null]);
        $this->get("http://tls.northfield.org/.well-known/lycenza-domain-probe?n={$nonce}")->assertNotFound();
    }

    #[Test]
    public function the_edge_desired_state_lists_canonical_hostnames_and_states_only(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'tls.northfield.org', DomainState::TlsPending);
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->createSchoolDomain($this->createSchool(), 'held.southfield.org', DomainState::Suspended);
        $pending = $this->createSchoolDomain($this->createSchool(), 'pending.westfield.org', DomainState::PendingVerification);
        $this->createSchoolDomain($this->createSchool(), 'gone.eastfield.org', DomainState::Revoked);

        $this->assertSame(0, Artisan::call('platform:domains-edge-desired', ['--json' => true]));
        $output = json_decode(Artisan::output(), true);

        $this->assertTrue($output['enabled']);
        $this->assertSame([
            ['hostname' => 'erp.northfield.org', 'state' => 'active'],
            ['hostname' => 'held.southfield.org', 'state' => 'suspended'],
            ['hostname' => 'tls.northfield.org', 'state' => 'tls_pending'],
        ], $output['hosts']);
        $this->assertStringNotContainsString((string) $pending->challenge_token, json_encode($output));
        $this->assertStringNotContainsString($school->id, json_encode($output), 'no School identifiers');
    }

    #[Test]
    public function the_operator_probe_prints_closed_codes_and_never_the_challenge(): void
    {
        $domain = $this->createSchoolDomain($this->createSchool(), 'erp.northfield.org', DomainState::TlsPending);

        $this->assertSame(0, Artisan::call('platform:domain-probe', ['hostname' => 'ERP.northfield.org.']));
        $output = Artisan::output();

        $this->assertStringContainsString("domain_id={$domain->id} state=tls_pending ownership=absent routing=fail tls=indeterminate", $output);
        $this->assertStringNotContainsString((string) $domain->challenge_token, $output);
        $this->assertSame(1, Artisan::call('platform:domain-probe', ['hostname' => 'nothing.northfield.org']));
    }

    #[Test]
    public function the_scheduler_run_is_a_no_op_while_custom_domains_are_disabled(): void
    {
        $domain = $this->createSchoolDomain($this->createSchool(), 'erp.northfield.org', DomainState::PendingVerification);
        config(['domains.enabled' => false]);

        $this->assertSame(0, Artisan::call('platform:domains-check'));
        $this->assertNull($domain->refresh()->last_checked_at);
    }
}
