<?php

namespace App\Console\Commands;

use App\Support\Testing\Exceptions\UnsafeTestDatabaseException;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Console\Command;

/**
 * Phase 0C.3A: the ONE canonical, safe way to reset the testing
 * database -- never a raw `migrate:fresh`/`db:seed` pair typed by hand
 * (see CLAUDE.md and TestDatabaseGuard's docblock for the incident
 * this replaces). Does not redefine Laravel's `migrate:fresh` --
 * delegates to it, through the approved `pgsql_admin` connection
 * (ADR 0021), only after independently re-confirming the same
 * fail-closed identity check every other boot already runs.
 *
 * Usage (from apps/platform, with the test database's real
 * credentials -- see phpunit.xml for the exact values):
 *
 *   APP_ENV=testing \
 *   DB_HOST=postgres DB_DATABASE=school_os_test \
 *   DB_USERNAME=school_os_app DB_PASSWORD=school_os_app_local_only_password \
 *   DB_ADMIN_USERNAME=school_os DB_ADMIN_PASSWORD=school_os \
 *   php artisan platform:test-db-reset --force
 */
class ResetTestDatabase extends Command
{
    protected $signature = 'platform:test-db-reset {--force : Required -- makes the destructive intent explicit, matching migrate:fresh convention}';

    protected $description = 'Safely resets the testing database: verifies test-database identity, migrates fresh via pgsql_admin, and runs the full canonical testing seed set.';

    public function handle(TestDatabaseGuard $guard): int
    {
        if (! app()->environment('testing')) {
            $this->error('platform:test-db-reset refuses to run outside APP_ENV=testing.');

            return self::FAILURE;
        }

        // Redundant with the AppServiceProvider::register() boot-time
        // check by construction (both call the same guard) -- kept
        // here too so this command's OWN safety does not silently
        // depend on that registration surviving future refactors, and
        // so the failure message a developer sees is this command's
        // own, not an unrelated provider-boot stack trace.
        try {
            $guard->assertSafe();
        } catch (UnsafeTestDatabaseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Pass --force to confirm: this destroys and recreates the testing database.');

            return self::FAILURE;
        }

        $database = config('database.testing_database');
        $this->info("Resetting testing database '{$database}'...");

        $this->call('migrate:fresh', ['--database' => 'pgsql_admin', '--force' => true]);

        // The FULL canonical testing seed set (Database\Seeders\
        // DatabaseSeeder), not just CapabilityAndRoleSeeder alone --
        // the Phase 0C.3 incident this closes left AiToolControllerTest/
        // AiAuditControllerTest/AiGatewayClientTest failing because
        // ServiceIdentitySeeder never ran. See section 9 of the 0C.3A
        // brief.
        $this->call('db:seed', ['--force' => true]);

        $this->info('Testing database reset complete.');

        return self::SUCCESS;
    }
}
