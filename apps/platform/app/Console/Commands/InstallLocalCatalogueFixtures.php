<?php

namespace App\Console\Commands;

use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Console\Command;
use LogicException;

/**
 * SR.1 (ADR 0071 §11): installs the local/testing catalogue fixture seam
 * (`local_fixtures`) for the guarded DDEV demo. Refused outside local/testing;
 * `platform:verify-database` fails if the schema exists anywhere else.
 */
class InstallLocalCatalogueFixtures extends Command
{
    protected $signature = 'platform:install-local-fixtures';

    protected $description = 'Install the local/testing role-catalogue fixture seam (refused outside local/testing).';

    public function handle(): int
    {
        try {
            LocalCatalogueFixtures::install();
        } catch (LogicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Local catalogue fixture seam installed.');

        return self::SUCCESS;
    }
}
