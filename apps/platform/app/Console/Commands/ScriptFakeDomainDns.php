<?php

namespace App\Console\Commands;

use App\Models\SchoolDomain;
use App\Providers\DomainsServiceProvider;
use App\Support\Domains\Dns\DnsLookup;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Domains\HostnameNormalizer;
use App\Support\Domains\OwnershipVerifier;
use App\Support\Domains\Probe\DomainProber;
use App\Support\Domains\Probe\FakeDomainProber;
use App\Support\Domains\Probe\ProbeResult;
use Illuminate\Console\Command;

/**
 * LOCAL/TESTING ONLY (ADR 0054 section 11): scripts the fake DNS resolver
 * and fake TLS prober for one hostname, so a DDEV review can walk a domain
 * through every state without real DNS or TLS. Refuses to run unless the
 * fakes are bound (DOMAIN_FAKES_ENABLED AND local/testing) -- it can never
 * touch a real resolver or edge.
 *
 *   publish         TXT = the claiming row's current challenge, and the
 *                   hostname resolving to the first configured edge address
 *   txt-mismatch    TXT present with another value
 *   txt-absent      no TXT record
 *   route-elsewhere the hostname resolving away from the edge
 *   tls-invalid     the probe finds an untrusted certificate
 *   indeterminate   every DNS answer times out
 *   reset           forget everything scripted for the hostname
 */
class ScriptFakeDomainDns extends Command
{
    protected $signature = 'platform:domain-fake-dns {hostname} {action : publish|txt-mismatch|txt-absent|route-elsewhere|tls-invalid|indeterminate|reset}';

    protected $description = 'LOCAL ONLY: script the fake DNS/TLS answers for one custom hostname (ADR 0054).';

    public function handle(HostnameNormalizer $names): int
    {
        if (! DomainsServiceProvider::fakesEnabled($this->laravel)) {
            $this->error('The fake DNS resolver is not enabled here (DOMAIN_FAKES_ENABLED in local/testing only).');

            return self::FAILURE;
        }

        $hostname = $names->canonicalRequestHost((string) $this->argument('hostname'));
        if ($hostname === null) {
            $this->error('That is not a valid hostname.');

            return self::FAILURE;
        }

        /** @var FakeDomainDnsResolver $dns */
        $dns = app(DomainDnsResolver::class);
        /** @var FakeDomainProber $probe */
        $probe = app(DomainProber::class);
        $txtName = OwnershipVerifier::recordName($hostname);
        $edge = (string) (config('domains.edge.addresses')[0] ?? '1.2.3.4');

        switch ((string) $this->argument('action')) {
            case 'publish':
                $domain = SchoolDomain::query()->claiming()->where('hostname', $hostname)->first();
                if ($domain === null) {
                    $this->error('No claiming domain has that hostname.');

                    return self::FAILURE;
                }
                $dns->publishTxt($txtName, [OwnershipVerifier::recordValue((string) $domain->challenge_token)]);
                $dns->publishAddresses($hostname, [$edge]);
                $probe->forget($hostname);
                break;
            case 'txt-mismatch':
                $dns->publishTxt($txtName, [OwnershipVerifier::VALUE_PREFIX.str_repeat('x', 43)]);
                break;
            case 'txt-absent':
                $dns->fail('txt', $txtName, DnsLookup::ABSENT, 'nxdomain');
                break;
            case 'route-elsewhere':
                $dns->publishAddresses($hostname, ['1.2.3.99']);
                break;
            case 'tls-invalid':
                $probe->script($hostname, ProbeResult::TLS_INVALID, 'untrusted');
                break;
            case 'indeterminate':
                $dns->fail('txt', $txtName, DnsLookup::INDETERMINATE, 'timeout');
                $dns->fail('addr', $hostname, DnsLookup::INDETERMINATE, 'timeout');
                break;
            case 'reset':
                $dns->forget($txtName);
                $dns->forget($hostname);
                $probe->forget($hostname);
                break;
            default:
                $this->error('Unknown action.');

                return self::FAILURE;
        }

        $this->info('Scripted.');

        return self::SUCCESS;
    }
}
