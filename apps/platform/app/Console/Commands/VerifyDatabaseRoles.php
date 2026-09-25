<?php

namespace App\Console\Commands;

use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Operations\RendersCheckResults;
use Illuminate\Console\Command;

/**
 * Phase 0O.4A (ADR 0050 sections 6-7): read-only verification of the
 * production database role model on the RUNTIME connection -- PostgreSQL
 * 16, the fixed runtime role `school_os_app` and its attributes, no
 * membership of the owner role, schema/table access, restricted deletes,
 * default privileges for the migration role, forced RLS, and the platform
 * root boundary. Codes only; mutates nothing; needs no admin credential.
 */
class VerifyDatabaseRoles extends Command
{
    use RendersCheckResults;

    protected $signature = 'platform:verify-database';

    protected $description = 'Verify the production database role model (read-only, codes only).';

    public function handle(DatabaseRoleVerifier $verifier): int
    {
        return $this->renderResults($verifier->verify());
    }
}
