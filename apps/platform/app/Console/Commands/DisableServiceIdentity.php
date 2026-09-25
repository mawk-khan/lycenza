<?php

namespace App\Console\Commands;

use App\Models\ServiceIdentity;
use App\Support\ServiceIdentities\ServiceIdentityIssuer;
use Illuminate\Console\Command;

/**
 * Phase 0O.1: the operator path for disabling a service identity (a
 * suspected-compromised credential, or a decommissioned caller). Its
 * requests fail from the next call; the row and its history stay.
 * Audited by ServiceIdentityIssuer (`platform.service_identity.disabled`).
 * Idempotent: an already-disabled identity changes nothing and records
 * nothing. Console only; re-enabling is not offered here.
 */
class DisableServiceIdentity extends Command
{
    protected $signature = 'platform:service-identity-disable
        {slug : The exact slug of an existing service identity}
        {--force : Skip the interactive confirmation (trusted operator automation only)}';

    protected $description = 'Disable a service identity (operator console only).';

    public function handle(ServiceIdentityIssuer $issuer): int
    {
        $identity = ServiceIdentity::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($identity === null) {
            $this->error('Refused: no service identity has exactly that slug.');

            return self::FAILURE;
        }

        if (! $identity->enabled) {
            $this->info('Already disabled. Nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refused: confirmation is required. Run interactively, or pass --force from trusted operator automation.');

                return self::FAILURE;
            }

            if (! $this->confirm("Disable service identity [{$identity->slug}]? Its calls fail immediately.")) {
                $this->info('Nothing changed.');

                return self::FAILURE;
            }
        }

        $issuer->disable($identity);
        $this->info("Disabled service identity [{$identity->slug}].");

        return self::SUCCESS;
    }
}
