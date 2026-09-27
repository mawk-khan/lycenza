<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use App\Models\SchoolDomain;
use App\Support\Domains\HostnameNormalizer;
use Illuminate\Console\Command;

/**
 * ADR 0054 sections 6.2, 10.1: an operator runs the checks for one custom
 * domain now (the same pipeline as the scheduler: ownership, routing, the
 * TLS probe, and any transition the evidence allows). Prints the domain id,
 * state and closed outcome codes only -- never a token or raw DNS/TLS data.
 */
class ProbeSchoolDomain extends Command
{
    protected $signature = 'platform:domain-probe {hostname : The canonical custom hostname}';

    protected $description = 'Run the ownership, routing and TLS checks for one custom domain now (ADR 0054).';

    public function handle(HostnameNormalizer $names, SchoolDomainCheckService $checks): int
    {
        $hostname = $names->canonicalRequestHost((string) $this->argument('hostname'));
        $domain = $hostname !== null ? SchoolDomain::query()->claiming()->where('hostname', $hostname)->first() : null;

        if ($domain === null) {
            $this->error('No claiming domain has that hostname.');

            return self::FAILURE;
        }

        $state = $checks->check($domain);
        $domain->refresh();

        $this->line("domain_id={$domain->id} state={$state->value} ownership={$domain->ownership_outcome} routing={$domain->routing_outcome} tls={$domain->tls_outcome}"
            .($domain->certificate_not_after !== null ? ' certificate_not_after='.$domain->certificate_not_after->toIso8601ZuluString() : ''));

        return self::SUCCESS;
    }
}
