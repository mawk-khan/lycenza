<?php

namespace App\Support\Domains\Dns;

use App\Support\Domains\PublicAddress;
use NetDNS2\Exception as DnsException;
use NetDNS2\Packet\Response;
use NetDNS2\Resolver;
use NetDNS2\RR;

/**
 * Production DomainDnsResolver (ADR 0054 section 4.5) on mikepultz/netdns2
 * (pure PHP, no shelling out, exposes the response code). It queries only
 * the configured public recursive resolvers (DOMAIN_DNS_RESOLVERS, IP
 * addresses), never the host's own resolv.conf.
 *
 * Bounds: each query waits at most 2 s, is tried at most twice (the second
 * attempt goes to the next configured resolver), and one check (txt() or
 * addresses(), whose A and AAAA queries share it) never runs past 10 s in
 * total. Responses are plain DNS over UDP (512 bytes) with the library's
 * automatic TCP retry on truncation (a TCP message is at most 65 535 bytes
 * by construction). CNAME chains are followed inside the answer only, at
 * most 8 deep; a loop, a longer chain, more than 32 TXT RRs or more than 32
 * addresses is `indeterminate`, never a partial answer.
 *
 * Outcome mapping: NXDOMAIN -> absent; NOERROR with no record of the type
 * (NODATA) -> absent; SERVFAIL, REFUSED, any other rcode, a timeout, a
 * socket error or a malformed packet -> indeterminate.
 */
final class NetDns2DomainResolver implements DomainDnsResolver
{
    private float $deadline = 0.0;

    /**
     * @param  list<string>  $servers  resolver IP addresses
     */
    public function __construct(private readonly array $servers, private readonly int $port = 53) {}

    public function txt(string $name): DnsLookup
    {
        $this->deadline = microtime(true) + self::BUDGET_SECONDS;
        $response = $this->query($name, 'TXT');

        if ($response instanceof DnsLookup) {
            return $response;
        }

        $chain = $this->chain($name, $response);
        if ($chain instanceof DnsLookup) {
            return $chain;
        }

        $records = [];
        foreach ($response->answer as $rr) {
            if ($rr->type->label() === 'TXT' && in_array($this->owner($rr), $chain, true)) {
                $records[] = implode('', array_map(fn ($text) => $text->value(), (array) $rr->__get('text')));
            }
        }

        if (count($records) > self::MAX_TXT_RECORDS) {
            return DnsLookup::indeterminate('too_many_records');
        }

        return $records === [] ? DnsLookup::absent('nodata') : DnsLookup::txtRecords($records);
    }

    public function addresses(string $hostname): DnsLookup
    {
        $this->deadline = microtime(true) + self::BUDGET_SECONDS;
        $chain = [$hostname];
        $addresses = [];
        $absent = 0;

        foreach (['A', 'AAAA'] as $type) {
            $response = $this->query($hostname, $type);

            if ($response instanceof DnsLookup) {
                if ($response->isIndeterminate()) {
                    return $response;
                }
                $absent++;

                continue;
            }

            $typeChain = $this->chain($hostname, $response);
            if ($typeChain instanceof DnsLookup) {
                return $typeChain;
            }
            $chain = count($typeChain) > count($chain) ? $typeChain : $chain;

            foreach ($response->answer as $rr) {
                if ($rr->type->label() === $type && in_array($this->owner($rr), $typeChain, true)) {
                    $raw = strtolower($rr->__get('address')->value());
                    $addresses[] = PublicAddress::canonical($raw) ?? $raw;
                }
            }
        }

        $addresses = array_values(array_unique($addresses));

        if (count($addresses) > self::MAX_ADDRESSES) {
            return DnsLookup::indeterminate('too_many_records');
        }

        if ($addresses === [] && count($chain) === 1) {
            return DnsLookup::absent($absent === 2 ? 'nxdomain' : 'nodata');
        }

        return DnsLookup::resolved($chain, $addresses);
    }

    private function query(string $name, string $type): Response|DnsLookup
    {
        if ($this->servers === []) {
            return DnsLookup::indeterminate('not_configured');
        }

        $reason = 'network';

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 0.05) {
                return DnsLookup::indeterminate('budget');
            }

            try {
                $resolver = new Resolver([
                    'nameservers' => [$this->servers[$attempt % count($this->servers)]],
                    'dns_port' => $this->port,
                    'timeout' => min(self::QUERY_TIMEOUT_SECONDS, $remaining),
                    'use_resolv_options' => false,
                ]);

                return $resolver->query($name, $type);
            } catch (DnsException $e) {
                $code = (int) $e->getCode();

                if ($code === 3) {
                    return DnsLookup::absent('nxdomain');
                }

                $reason = match (true) {
                    $code === 2 => 'servfail',
                    $code === 5 => 'refused',
                    $code > 0 && $code < 3841 => 'rcode',
                    str_contains(strtolower($e->getMessage()), 'timeout') || str_contains(strtolower($e->getMessage()), 'timed out') => 'timeout',
                    $code === 3841 || $code === 3842 => 'malformed',
                    default => 'network',
                };
            } catch (\Throwable) {
                $reason = 'malformed';
            }
        }

        return DnsLookup::indeterminate($reason);
    }

    /**
     * The CNAME chain from $name inside this answer, bounded.
     *
     * @return list<string>|DnsLookup
     */
    private function chain(string $name, Response $response): array|DnsLookup
    {
        $cnames = [];
        foreach ($response->answer as $rr) {
            if ($rr->type->label() === 'CNAME') {
                $cnames[$this->owner($rr)] = strtolower(rtrim($rr->__get('cname')->value(), '.'));
            }
        }

        $chain = [strtolower($name)];
        $current = strtolower($name);

        while (isset($cnames[$current])) {
            $current = $cnames[$current];

            if (in_array($current, $chain, true)) {
                return DnsLookup::indeterminate('cname_loop');
            }

            $chain[] = $current;

            if (count($chain) - 1 > self::MAX_CNAME_DEPTH) {
                return DnsLookup::indeterminate('cname_depth');
            }
        }

        return $chain;
    }

    private function owner(RR $rr): string
    {
        return strtolower(rtrim($rr->name->value(), '.'));
    }
}
