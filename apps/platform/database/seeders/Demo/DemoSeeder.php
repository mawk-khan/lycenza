<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * LOCAL DDEV DEMO ONLY -- run exclusively by `ddev demo-reset`
 * (.ddev/commands/web/demo-reset), never by DatabaseSeeder, never in CI,
 * never in any shared or production environment.
 *
 * Creates the "Lycenza Demo School" dataset and fixed-password demo
 * accounts documented in docs/development/DDEV-DEMO-REVIEW.md.
 * DemoEnvironmentGuard::assertLocalDdev() refuses to proceed unless
 * APP_ENV=local AND the process is inside DDEV AND both database
 * connections point at DDEV's private `db` database.
 */
class DemoSeeder extends Seeder
{
    public function run(DemoDataBuilder $builder): void
    {
        DemoEnvironmentGuard::assertLocalDdev(
            (string) app()->environment(),
            getenv('IS_DDEV_PROJECT') === false ? null : (string) getenv('IS_DDEV_PROJECT'),
            (array) config('database.connections'),
        );

        $result = $builder->build();

        $this->command->newLine();
        $this->command->warn('LOCAL DEMO CREDENTIALS -- NEVER USE IN PRODUCTION. Password for every account: '.DemoDataBuilder::DEMO_PASSWORD);
        $this->command->table(
            ['Persona', 'Email', 'School', 'Access'],
            array_map(fn (array $account) => [
                $account['persona'],
                $account['email'],
                $account['school'],
                $account['access'],
            ], $result->accounts),
        );

        foreach ($result->notes as $note) {
            $this->command->line('  - '.$note);
        }
    }
}
