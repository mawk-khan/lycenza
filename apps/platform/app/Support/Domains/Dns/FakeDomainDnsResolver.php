<?php

namespace App\Support\Domains\Dns;

use Illuminate\Contracts\Cache\Repository;

/**
 * DDEV/test DomainDnsResolver (ADR 0054 section 11). Bound ONLY when
 * `domains.fakes` is true AND the environment is local/testing
 * (DomainsServiceProvider; the production guard refuses the flag). No real
 * DNS is ever queried: answers are records an operator or a test
 * "publishes" into the cache store (Redis in DDEV, so the CLI and the web
 * process agree; array in tests). A name with nothing published is absent.
 */
final class FakeDomainDnsResolver implements DomainDnsResolver
{
    private const PREFIX = 'domains-fake-dns:';

    public function __construct(private readonly Repository $store) {}

    public function txt(string $name): DnsLookup
    {
        return $this->answer('txt', $name, fn (array $s) => DnsLookup::txtRecords(array_values(array_map('strval', (array) ($s['txt'] ?? [])))));
    }

    public function addresses(string $hostname): DnsLookup
    {
        return $this->answer('addr', $hostname, fn (array $s) => DnsLookup::resolved(
            array_values(array_map('strval', (array) ($s['chain'] ?? [$hostname]))),
            array_values(array_map('strval', (array) ($s['addresses'] ?? []))),
        ));
    }

    /** @param list<string> $records each already joined */
    public function publishTxt(string $name, array $records): void
    {
        $this->put('txt', $name, ['status' => DnsLookup::OK, 'txt' => $records]);
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>|null  $chain  defaults to the hostname alone
     */
    public function publishAddresses(string $hostname, array $addresses, ?array $chain = null): void
    {
        $this->put('addr', $hostname, ['status' => DnsLookup::OK, 'chain' => $chain ?? [$hostname], 'addresses' => $addresses]);
    }

    /** @param 'txt'|'addr' $kind */
    public function fail(string $kind, string $name, string $status, string $reason): void
    {
        $this->put($kind, $name, ['status' => $status, 'reason' => $reason]);
    }

    public function forget(string $name): void
    {
        $this->store->forget(self::PREFIX.'txt:'.$name);
        $this->store->forget(self::PREFIX.'addr:'.$name);
    }

    /** @param array<string, mixed> $scenario */
    private function put(string $kind, string $name, array $scenario): void
    {
        $this->store->forever(self::PREFIX.$kind.':'.strtolower($name), $scenario);
    }

    /** @param \Closure(array<string, mixed>): DnsLookup $ok */
    private function answer(string $kind, string $name, \Closure $ok): DnsLookup
    {
        $scenario = $this->store->get(self::PREFIX.$kind.':'.strtolower($name));

        if (! is_array($scenario)) {
            return DnsLookup::absent('nxdomain');
        }

        return match ($scenario['status'] ?? null) {
            DnsLookup::OK => $ok($scenario),
            DnsLookup::ABSENT => DnsLookup::absent((string) ($scenario['reason'] ?? 'nxdomain')),
            default => DnsLookup::indeterminate((string) ($scenario['reason'] ?? 'timeout')),
        };
    }
}
