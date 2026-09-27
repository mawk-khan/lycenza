<?php

namespace Tests\Unit\Domains;

use App\Support\Domains\Dns\DnsLookup;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\HostnameNormalizer;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Domains\PublicAddress;
use App\Support\Domains\RoutingVerifier;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 4.2, 4.5, 5): the frozen TXT ownership
 * matching rule and the separate routing check, over a scripted resolver
 * (no network). The production resolver's wire behaviour is proven in
 * NetDns2DomainResolverTest against a local DNS responder.
 */
class DnsVerificationTest extends TestCase
{
    private const TOKEN = 'k7Qm0bY4n2XcV9sLp1aZr8tWd3eFh6gJu5iOy0PqRsT';

    /** @param array<string, DnsLookup> $txt @param array<string, DnsLookup> $addresses */
    private function resolver(array $txt = [], array $addresses = []): DomainDnsResolver
    {
        return new class($txt, $addresses) implements DomainDnsResolver
        {
            /** @param array<string, DnsLookup> $txt @param array<string, DnsLookup> $addresses */
            public function __construct(private array $txt, private array $addresses) {}

            public function txt(string $name): DnsLookup
            {
                return $this->txt[$name] ?? DnsLookup::absent();
            }

            public function addresses(string $hostname): DnsLookup
            {
                return $this->addresses[$hostname] ?? DnsLookup::absent();
            }
        };
    }

    private function ownership(DnsLookup $answer): string
    {
        return (new OwnershipVerifier($this->resolver(['_lycenza-verification.erp.northfield.org' => $answer])))->check('erp.northfield.org', self::TOKEN)['outcome'];
    }

    #[Test]
    public function the_record_name_and_value_are_frozen(): void
    {
        $this->assertSame('_lycenza-verification.erp.northfield.org', OwnershipVerifier::recordName('erp.northfield.org'));
        $this->assertSame('lycenza-domain-verification='.self::TOKEN, OwnershipVerifier::recordValue(self::TOKEN));
        $this->assertSame(43, strlen(self::TOKEN));
    }

    #[Test]
    public function only_an_exact_rr_value_matches(): void
    {
        $value = 'lycenza-domain-verification='.self::TOKEN;

        $this->assertSame('match', $this->ownership(DnsLookup::txtRecords([$value])));
        $this->assertSame('match', $this->ownership(DnsLookup::txtRecords(['v=spf1 -all', 'google-site-verification=abc', $value])), 'other TXT records are ignored');
        $this->assertSame('match', $this->ownership(DnsLookup::txtRecords([implode('', ['lycenza-domain-', 'verification=', self::TOKEN])])), 'split character-strings arrive joined');

        foreach ([
            'wrong token' => 'lycenza-domain-verification='.strrev(self::TOKEN),
            'old token (regenerated)' => 'lycenza-domain-verification=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'prefix' => 'x'.$value,
            'suffix' => $value.'x',
            'embedded' => 'note '.$value.' end',
            'leading space' => ' '.$value,
            'trailing space' => $value.' ',
            'case' => strtoupper($value),
            'token only' => self::TOKEN,
            'no token' => 'lycenza-domain-verification=',
        ] as $case => $record) {
            $this->assertSame('mismatch', $this->ownership(DnsLookup::txtRecords([$record])), $case);
        }
    }

    #[Test]
    public function absent_and_indeterminate_are_kept_apart(): void
    {
        $this->assertSame('absent', $this->ownership(DnsLookup::absent('nxdomain')));
        $this->assertSame('absent', $this->ownership(DnsLookup::absent('nodata')));

        foreach (['timeout', 'servfail', 'refused', 'truncated', 'malformed', 'cname_loop', 'cname_depth', 'too_many_records', 'not_configured'] as $reason) {
            $this->assertSame('indeterminate', $this->ownership(DnsLookup::indeterminate($reason)), "{$reason} is never 'absent'");
        }
    }

    /** @param array<string, mixed> $edge */
    private function routing(DnsLookup $answer, array $edge = ['cname_target' => 'edge.lycenza-cdn.net', 'addresses' => ['1.2.3.4', '2606:4700::6810:84e5']]): array
    {
        $config = new Repository(['domains' => ['edge' => $edge]]);

        return (new RoutingVerifier($this->resolver(addresses: ['erp.northfield.org' => $answer]), $config, new HostnameNormalizer))->check('erp.northfield.org');
    }

    #[Test]
    public function routing_passes_only_through_the_configured_edge(): void
    {
        $host = 'erp.northfield.org';

        $this->assertSame('pass', $this->routing(DnsLookup::resolved([$host, 'edge.lycenza-cdn.net'], ['9.9.9.9']))['outcome'], 'CNAME to the edge target');
        $this->assertSame('pass', $this->routing(DnsLookup::resolved([$host, 'school-proxy.northfield.org', 'edge.lycenza-cdn.net', 'x.provider.net'], ['9.9.9.9']))['outcome'], 'the chain passes through the target');
        $this->assertSame('pass', $this->routing(DnsLookup::resolved([$host], ['1.2.3.4']))['outcome'], 'apex A inside the edge set');
        $this->assertSame('pass', $this->routing(DnsLookup::resolved([$host], ['1.2.3.4', '2606:4700:0::6810:84e5']))['outcome'], 'addresses compared canonically');
        $this->assertSame(['1.2.3.4'], $this->routing(DnsLookup::resolved([$host], ['1.2.3.4']))['addresses'], 'the probe is pinned to these');

        $this->assertSame('fail', $this->routing(DnsLookup::resolved([$host], ['1.2.3.4', '5.6.7.8']))['outcome'], 'one address outside the edge set');
        $this->assertSame('fail', $this->routing(DnsLookup::resolved([$host, 'other-cdn.net'], ['5.6.7.8']))['outcome'], 'CNAME elsewhere');
        $this->assertSame('fail', $this->routing(DnsLookup::resolved([$host, 'edge.lycenza-cdn.net.evil.net'], ['5.6.7.8']))['outcome'], 'no suffix trickery');
        $this->assertSame('fail', $this->routing(DnsLookup::absent())['outcome']);
        $this->assertSame('fail', $this->routing(DnsLookup::resolved([$host, 'edge.lycenza-cdn.net'], []))['outcome'], 'a chain without addresses routes nowhere');
        $this->assertSame('indeterminate', $this->routing(DnsLookup::indeterminate('timeout'))['outcome']);
        $this->assertSame('fail', $this->routing(DnsLookup::resolved([$host], ['1.2.3.4']), ['cname_target' => null, 'addresses' => []])['outcome'], 'no edge configured: nothing passes');
    }

    #[Test]
    public function any_non_public_answer_blocks_routing_even_through_the_edge_target(): void
    {
        foreach (['10.1.2.3', '127.0.0.1', '169.254.169.254', '100.64.0.1', '172.16.5.5', '192.168.1.1', '192.0.2.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '0.0.0.0', '255.255.255.255', '::1', 'fc00::1', 'fe80::1', '2001:db8::1', '::ffff:10.0.0.1'] as $private) {
            $result = $this->routing(DnsLookup::resolved(['erp.northfield.org', 'edge.lycenza-cdn.net'], ['1.2.3.4', $private]), ['cname_target' => 'edge.lycenza-cdn.net', 'addresses' => ['1.2.3.4', $private]]);
            $this->assertSame('fail', $result['outcome'], $private);
            $this->assertSame('non_public_address', $result['reason'], $private);
        }
    }

    #[Test]
    public function public_address_classification(): void
    {
        foreach (['1.2.3.4', '8.8.8.8', '9.9.9.9', '2606:4700::6810:84e5', '2620:fe::fe'] as $public) {
            $this->assertTrue(PublicAddress::isPublic($public), $public);
        }
        foreach (['10.0.0.1', '100.100.100.100', '198.18.0.1', '240.0.0.1', '192.88.99.1', '64:ff9b::1', '2002::1', 'not-an-ip', ''] as $other) {
            $this->assertFalse(PublicAddress::isPublic($other), $other);
        }
        $this->assertSame('2606:4700::6810:84e5', PublicAddress::canonical('2606:4700:0:0::6810:84E5'));
        $this->assertNull(PublicAddress::canonical('edge.example'));
    }
}
