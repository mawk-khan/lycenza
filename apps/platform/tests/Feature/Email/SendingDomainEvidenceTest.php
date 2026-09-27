<?php

namespace Tests\Feature\Email;

use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Email\SendingDomainEvidence;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 17): `platform:mail-verify-domain` reads
 * DNS evidence only (the fake resolver here; never a DNS change) and never
 * claims what it cannot prove -- provider verification and the 14-day
 * aligned-DKIM history stay deployment evidence.
 */
class SendingDomainEvidenceTest extends TestCase
{
    private function dns(): FakeDomainDnsResolver
    {
        $dns = app(DomainDnsResolver::class);
        $this->assertInstanceOf(FakeDomainDnsResolver::class, $dns);

        return $dns;
    }

    private function dkimKey(int $bits): string
    {
        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = openssl_pkey_get_details($key)['key'];

        return str_replace(["\n", '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $pem);
    }

    private function publishSound(string $dmarc = 'v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:dmarc@lycenza-suite.test'): void
    {
        config(['email.sending_domain' => 'notify.lycenza-mail.org', 'email.dns.return_path_domain' => 'bounce.notify.lycenza-mail.org', 'email.dns.dkim_selector' => 's2026']);
        $this->dns()->publishTxt('bounce.notify.lycenza-mail.org', ['v=spf1 include:spf.provider.example -all']);
        $this->dns()->publishTxt('s2026._domainkey.notify.lycenza-mail.org', ['v=DKIM1; k=rsa; p='.$this->dkimKey(2048)]);
        $this->dns()->publishTxt('_dmarc.notify.lycenza-mail.org', [$dmarc]);
    }

    #[Test]
    public function sound_evidence_is_ready_and_the_unprovable_is_reported_as_deployment_evidence(): void
    {
        $this->publishSound();
        $evidence = app(SendingDomainEvidence::class)->evaluate();

        $this->assertSame('pass', $evidence['spf']['result']);
        $this->assertSame('pass', $evidence['dkim']['result']);
        $this->assertSame('quarantine', $evidence['dmarc']['result']);
        $this->assertSame('deployment_evidence', $evidence['provider_domain_verification']['result']);
        $this->assertSame('deployment_evidence', $evidence['dkim_alignment_history']['result']);
        $this->assertSame('not_attested', $evidence['operator_attestation']['result']);
        $this->assertTrue(SendingDomainEvidence::dnsReady($evidence));
    }

    #[Test]
    public function dmarc_none_or_partial_enforcement_is_not_ready(): void
    {
        $this->publishSound('v=DMARC1; p=none; rua=mailto:dmarc@lycenza-suite.test');
        $this->assertSame('none', ($e = app(SendingDomainEvidence::class)->evaluate())['dmarc']['result']);
        $this->assertFalse(SendingDomainEvidence::dnsReady($e));

        $this->dns()->publishTxt('_dmarc.notify.lycenza-mail.org', ['v=DMARC1; p=reject; pct=50']);
        $this->assertSame('partial_reject', app(SendingDomainEvidence::class)->evaluate()['dmarc']['result']);
    }

    #[Test]
    public function the_organizational_dmarc_record_applies_its_subdomain_policy(): void
    {
        $this->publishSound();
        $this->dns()->forget('_dmarc.notify.lycenza-mail.org');
        $this->dns()->publishTxt('_dmarc.lycenza-mail.org', ['v=DMARC1; p=reject; sp=none']);

        $this->assertSame('none', app(SendingDomainEvidence::class)->evaluate()['dmarc']['result'], 'sp= governs the sending subdomain');
    }

    #[Test]
    public function weak_permissive_misaligned_duplicate_and_revoked_records_fail(): void
    {
        $this->publishSound();
        $evidence = fn () => app(SendingDomainEvidence::class)->evaluate();

        $this->dns()->publishTxt('bounce.notify.lycenza-mail.org', ['v=spf1 +all']);
        $this->assertSame('permissive', $evidence()['spf']['result']);
        $this->dns()->publishTxt('bounce.notify.lycenza-mail.org', ['v=spf1 -all', 'v=spf1 ~all']);
        $this->assertSame('multiple_records', $evidence()['spf']['result']);

        config(['email.dns.return_path_domain' => 'bounce.some-other-domain.org']);
        $this->assertSame('not_aligned', $evidence()['spf']['result']);

        $this->dns()->publishTxt('s2026._domainkey.notify.lycenza-mail.org', ['v=DKIM1; k=rsa; p='.$this->dkimKey(1024)]);
        $this->assertSame('weak_key', $evidence()['dkim']['result']);
        $this->dns()->publishTxt('s2026._domainkey.notify.lycenza-mail.org', ['v=DKIM1; p=']);
        $this->assertSame('revoked', $evidence()['dkim']['result']);
    }

    #[Test]
    public function a_dns_timeout_is_indeterminate_never_absent(): void
    {
        $this->publishSound();
        $this->dns()->fail('txt', '_dmarc.notify.lycenza-mail.org', 'indeterminate', 'timeout');

        $this->assertSame(['indeterminate', 'timeout'], array_values(app(SendingDomainEvidence::class)->evaluate()['dmarc']));
    }

    #[Test]
    public function the_command_is_read_only_and_exits_non_zero_until_ready(): void
    {
        $this->publishSound('v=DMARC1; p=none');
        $this->artisan('platform:mail-verify-domain')->expectsOutputToContain('DNS evidence: NOT READY')->assertExitCode(1);

        // The sending domain must also be one no School can claim.
        $this->publishSound();
        config(['domains.reserved_suffixes' => ['lycenza-mail.org']]);
        $this->artisan('platform:mail-verify-domain')->expectsOutputToContain('DNS evidence: READY')->assertExitCode(0);
    }
}
