<?php

namespace Tests\Unit\Support\Http;

use App\Support\Http\TrustedProxyList;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 2): TRUSTED_PROXIES accepts explicit
 * addresses and CIDRs only; anything trust-all, DNS-based or malformed
 * fails configuration loading, and the error never echoes the value.
 */
class TrustedProxyListTest extends TestCase
{
    #[Test]
    public function empty_means_trust_nobody(): void
    {
        $this->assertSame([], TrustedProxyList::parse(null));
        $this->assertSame([], TrustedProxyList::parse(''));
        $this->assertSame([], TrustedProxyList::parse('   '));
    }

    #[Test]
    public function explicit_addresses_and_cidrs_are_accepted_normalized_and_deduplicated(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.0/8', '192.168.10.0/24', '2001:db8::/32', 'fd00::1', '172.16.0.0/12'],
            TrustedProxyList::parse(' 10.0.0.1, 10.0.0.0/8,192.168.10.0/24 , 2001:DB8::/32, FD00::1, 172.16.0.0/12, 10.0.0.1 '),
        );
        $this->assertSame(['127.0.0.1/32'], TrustedProxyList::parse('127.0.0.1/32'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'star' => ['*'],
            'double star' => ['**'],
            'star among others' => ['10.0.0.1,*'],
            'ipv4 any' => ['0.0.0.0/0'],
            'ipv6 any' => ['::/0'],
            'ipv4 too broad' => ['10.0.0.0/7'],
            'ipv6 too broad' => ['2001::/16'],
            'ipv4-mapped ipv6 space' => ['::ffff:0:0/96'],
            'hostname' => ['proxy.internal'],
            'hostname cidr' => ['proxy.internal/24'],
            'malformed address' => ['10.0.0.300'],
            'malformed prefix' => ['10.0.0.0/abc'],
            'prefix too long v4' => ['10.0.0.0/33'],
            'prefix too long v6' => ['fd00::/129'],
            'negative prefix' => ['10.0.0.0/-1'],
            'empty entry' => ['10.0.0.1,,10.0.0.2'],
            'trailing comma' => ['10.0.0.1,'],
            'url' => ['https://10.0.0.1'],
            'port' => ['10.0.0.1:443'],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function unsafe_or_malformed_values_are_refused_without_echoing_them(string $value): void
    {
        try {
            TrustedProxyList::parse($value);
            $this->fail("TRUSTED_PROXIES={$value} must be refused");
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('TRUSTED_PROXIES', $e->getMessage());
            foreach (explode(',', $value) as $entry) {
                if (strlen(trim($entry)) > 2) {
                    $this->assertStringNotContainsString(trim($entry), $e->getMessage());
                }
            }
        }
    }
}
