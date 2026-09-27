<?php

namespace App\Console\Commands;

use App\Models\SchoolDomain;
use App\Support\Domains\DomainState;
use Illuminate\Console\Command;

/**
 * ADR 0054 section 6.1: the edge's desired state, read-only. Deployment
 * automation provisions a certificate and routing for exactly the listed
 * hostnames and removes any other custom hostname it holds (a revoked or
 * expired domain simply disappears from this list). `tls_pending` is the
 * provisioning signal; `active` and `suspended` stay provisioned (a
 * suspended domain can recover on the same row). Canonical hostnames and
 * states only -- never a School, a token, a key or any certificate
 * material; no provider API is called.
 */
class ShowDomainsEdgeDesired extends Command
{
    public const MAX_HOSTS = 10000;

    protected $signature = 'platform:domains-edge-desired {--json : Machine-readable output}';

    protected $description = 'Print the custom hostnames the deployment edge must serve (ADR 0054; read-only).';

    public function handle(): int
    {
        $hosts = SchoolDomain::query()
            ->whereIn('state', DomainState::PROBE_ELIGIBLE)
            ->orderBy('hostname')
            ->limit(self::MAX_HOSTS)
            ->get(['hostname', 'state'])
            ->map(fn (SchoolDomain $d) => ['hostname' => $d->hostname, 'state' => $d->state->value])
            ->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601ZuluString(),
                'enabled' => (bool) config('domains.enabled'),
                'hosts' => $hosts,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Custom domains enabled: '.(config('domains.enabled') ? 'yes' : 'no'));
        $this->line('Desired edge hosts: '.count($hosts));
        foreach ($hosts as $host) {
            $this->line("  {$host['hostname']}  {$host['state']}");
        }

        return self::SUCCESS;
    }
}
