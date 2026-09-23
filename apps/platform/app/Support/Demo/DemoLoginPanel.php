<?php

namespace App\Support\Demo;

use App\Models\User;
use Database\Seeders\Demo\DemoAccountCatalog;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoEnvironmentGuard;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * LOCAL DDEV DEMO ONLY: the "Demo accounts" shortcuts shown under the
 * login form (docs/development/DDEV-DEMO-REVIEW.md).
 *
 * Decided entirely server-side by the SAME fail-closed guard that
 * protects the fixed-password demo seeder
 * (DemoEnvironmentGuard::assertLocalDdev(): APP_ENV=local AND
 * IS_DDEV_PROJECT=true AND both database connections on DDEV's private
 * `db` database). Anywhere else -- production, staging, testing, an
 * unknown environment -- this returns null, so no demo email or password
 * is ever sent to the browser; the compiled JS bundle contains only the
 * rendering code, never credentials.
 *
 * Shortcuts only PREFILL the normal login form: authentication still
 * goes through LoginController::store() unchanged. Only accounts that
 * actually exist are listed (nothing before `ddev demo-reset`).
 */
final class DemoLoginPanel
{
    public function __construct(private readonly Application $app) {}

    /**
     * @return array{password: string, accounts: list<array{persona: string, email: string, hint: string, group: string}>}|null
     */
    public function forCurrentEnvironment(): ?array
    {
        $isDdevProject = getenv('IS_DDEV_PROJECT');

        try {
            DemoEnvironmentGuard::assertLocalDdev(
                (string) $this->app->environment(),
                $isDdevProject === false ? null : $isDdevProject,
                (array) config('database.connections'),
            );
        } catch (RuntimeException) {
            return null;
        }

        $shortcuts = DemoAccountCatalog::loginShortcuts();
        $seeded = User::query()->whereIn('email', array_column($shortcuts, 'email'))->pluck('email')->all();
        $accounts = array_values(array_filter($shortcuts, fn (array $account) => in_array($account['email'], $seeded, true)));

        if ($accounts === []) {
            return null;
        }

        return ['password' => DemoDataBuilder::DEMO_PASSWORD, 'accounts' => $accounts];
    }
}
