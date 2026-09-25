<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Roles\PlatformRootProvisioningRefusedException;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use Illuminate\Console\Command;

/**
 * Phase 0O.1 (ADR 0046 section 2): the operator-only path that provisions
 * the root platform role for one existing, enabled account. Console only;
 * see PlatformRootProvisioningService for the guarantees.
 *
 * Interactive by default: it shows the resolved account and requires the
 * operator to type that account's exact email address. `--force` skips the
 * prompt for trusted operator automation only; without it, a
 * non-interactive run refuses.
 */
class ProvisionPlatformRoot extends Command
{
    protected $signature = 'platform:provision-root
        {user : The exact email address or user id of an existing, enabled account}
        {--force : Skip the interactive confirmation (trusted operator automation only)}';

    protected $description = 'Provision the root platform role for one existing account (operator console only).';

    public function handle(PlatformRootProvisioningService $provisioning): int
    {
        try {
            $provisioning->assertOperatorConnection();
            $target = $provisioning->resolveTarget((string) $this->argument('user'));
            $root = $provisioning->rootRole();
        } catch (PlatformRootProvisioningRefusedException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($provisioning->isProvisioned($target, $root)) {
            $this->info('Already provisioned: that account already holds the root platform role. Nothing changed.');

            return self::SUCCESS;
        }

        $this->warn('This grants the ROOT platform role (all platform authority, including governing other platform roles).');
        $this->table(['User id', 'Email', 'Name'], [[$target->id, $target->email, $target->name]]);

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refused: confirmation is required. Run interactively, or pass --force from trusted operator automation.');

                return self::FAILURE;
            }

            $typed = trim((string) $this->ask('Type the account\'s email address exactly to confirm'));

            if ($typed !== $target->email) {
                $this->error('Confirmation did not match. Nothing changed.');

                return self::FAILURE;
            }
        }

        $outcome = $provisioning->provision($target);

        if ($outcome === 'already_provisioned') {
            $this->info('Already provisioned: that account already holds the root platform role. Nothing changed.');

            return self::SUCCESS;
        }

        $this->info('Provisioned the root platform role (audited as '.PlatformRootProvisioningService::EVENT.').');

        return self::SUCCESS;
    }
}
