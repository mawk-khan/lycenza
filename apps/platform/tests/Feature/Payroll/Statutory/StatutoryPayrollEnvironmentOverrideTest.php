<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Application\StatutoryPayrollAvailability;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * HR-PG1/PG2-Q1-C, finding F1 (rule 153, ADR 0036 amendment): a console `--env`
 * override changes Laravel's application environment but never the deployment's
 * real APP_ENV, and statutory Payroll needs BOTH to be a development environment.
 *
 * The decision is pinned as a pure matrix, then proven end to end in genuinely
 * separate OS processes that boot the console kernel exactly as
 * `php artisan <command> --env=<value>` does, with APP_ENV set as a real process
 * variable (as the hardened images set it). The child process changes nothing
 * outside itself: no host variable, no file, no database row.
 */
class StatutoryPayrollEnvironmentOverrideTest extends TestCase
{
    #[Test]
    public function both_the_application_and_the_deployment_environment_must_be_development(): void
    {
        $matrix = [
            // genuine development deployments
            ['local', 'local', true],
            ['testing', 'testing', true],
            ['local', 'testing', true],
            ['testing', 'local', true],
            // genuine hardened deployments
            ['staging', 'staging', false],
            ['production', 'production', false],
            // a console --env override on a hardened deployment
            ['local', 'production', false],
            ['testing', 'production', false],
            ['local', 'staging', false],
            ['testing', 'staging', false],
            // a hardened application environment on a development deployment
            ['production', 'local', false],
            ['staging', 'testing', false],
            // unset, unknown, malformed or non-string deployment values
            ['local', null, false],
            ['testing', null, false],
            ['local', '', false],
            ['local', 'qa', false],
            ['local', 'Local', false],
            ['local', 'local ', false],
            ['local', true, false],
            ['local', false, false],
            // unset, unknown or malformed application values
            ['', 'local', false],
            ['qa', 'local', false],
            ['Production', 'local', false],
            ['TESTING', 'testing', false],
        ];

        foreach ($matrix as [$application, $deployment, $available]) {
            $this->assertSame(
                $available,
                StatutoryPayrollAvailability::isAvailableFor($application, $deployment),
                'application '.var_export($application, true).', deployment '.var_export($deployment, true),
            );
        }
    }

    #[Test]
    public function a_production_deployment_stays_closed_under_a_console_env_local_override(): void
    {
        foreach ([['--env=local'], ['--env', 'local']] as $override) {
            $probe = $this->probe('production', $override);
            $label = 'production + '.implode(' ', $override);

            // The override really took effect on the application environment (no vacuous probe)...
            $this->assertSame('local', $probe['application'], "{$label}: --env set the application environment");
            $this->assertSame('production', $probe['deployment'], "{$label}: the real APP_ENV is untouched");
            $this->assertSame('production', $probe['config'], "{$label}: configuration still says production");
            // ...and the gate still refuses, at the gate and at a direct service call, before any query.
            $this->assertRefused($probe, $label);
        }
    }

    #[Test]
    public function a_staging_deployment_stays_closed_under_a_console_env_testing_override(): void
    {
        $probe = $this->probe('staging', ['--env=testing']);

        $this->assertSame('testing', $probe['application'], 'staging + --env=testing: --env set the application environment');
        $this->assertSame('staging', $probe['deployment'], 'staging + --env=testing: the real APP_ENV is untouched');
        $this->assertRefused($probe, 'staging + --env=testing');
    }

    #[Test]
    public function genuine_local_and_testing_deployments_stay_available(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $probe = $this->probe($environment, []);

            $this->assertSame($environment, $probe['application'], "{$environment}: application environment");
            $this->assertSame($environment, $probe['deployment'], "{$environment}: deployment environment");
            $this->assertTrue($probe['available'], "{$environment}: statutory Payroll stays available for development");
            $this->assertSame('passed', $probe['gate'], "{$environment}: the gate lets it through");
        }
    }

    /** @param array<string, mixed> $probe */
    private function assertRefused(array $probe, string $label): void
    {
        $this->assertFalse($probe['available'], "{$label}: unavailable");
        $this->assertSame('refused', $probe['gate'], "{$label}: the gate refuses");
        $this->assertSame('refused', $probe['service'] ?? null, "{$label}: a direct service call is refused");
        $this->assertSame(0, $probe['queries'], "{$label}: refused before any query");
    }

    /**
     * Boot the console kernel in a separate process with APP_ENV as a real process variable.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function probe(string $deploymentEnvironment, array $arguments): array
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/statutory-availability-probe.php'), ...$arguments],
            base_path(),
            ['APP_ENV' => $deploymentEnvironment],
            null,
            60,
        );
        $process->run();

        $this->assertTrue($process->isSuccessful(), "probe ({$deploymentEnvironment} ".implode(' ', $arguments).') failed: '.$process->getErrorOutput().$process->getOutput());
        $decoded = json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, 'probe output: '.$process->getOutput());

        return $decoded;
    }
}
