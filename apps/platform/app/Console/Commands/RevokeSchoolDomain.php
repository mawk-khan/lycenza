<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Support\Domains\HostnameNormalizer;
use Illuminate\Console\Command;

/**
 * ADR 0054 section 7.4: an operator revokes a stuck or disputed custom
 * domain from the console, so its legitimate owner can claim it. Terminal;
 * audited on the platform ledger (`platform.school_domain.revoked`) and the
 * School's. Requires typing the hostname again (or --force when non-
 * interactive).
 */
class RevokeSchoolDomain extends Command
{
    protected $signature = 'platform:domain-revoke {hostname : The canonical custom hostname} {--force : Skip the confirmation}';

    protected $description = 'Revoke one custom School domain (ADR 0054; terminal, audited).';

    public function handle(HostnameNormalizer $names, SchoolDomainService $domains): int
    {
        $hostname = $names->canonicalRequestHost((string) $this->argument('hostname'));

        if ($hostname === null) {
            $this->error('That is not a valid hostname.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->ask("Type {$hostname} to revoke it") !== $hostname) {
            $this->error('Not confirmed; nothing changed.');

            return self::FAILURE;
        }

        $domain = $domains->revokeByOperator($hostname);

        if ($domain === null) {
            $this->error('No claiming domain has that hostname.');

            return self::FAILURE;
        }

        $this->info("Revoked domain_id={$domain->id}.");

        return self::SUCCESS;
    }
}
