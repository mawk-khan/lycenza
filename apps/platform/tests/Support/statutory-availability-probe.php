<?php

use App\Domain\Payroll\Statutory\Application\Admin\StatutoryRuleStatusReadService;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollUnavailableException;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollAvailability;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script for StatutoryPayrollEnvironmentOverrideTest
// (HR-PG1/PG2-Q1-C, finding F1): boots the CONSOLE kernel in a genuinely
// separate OS process, exactly as `php artisan <command> --env=<value>` does,
// with the deployment's APP_ENV set as a real process variable by the caller.
// It reports both environments and what the statutory gate decided, and tries
// one statutory service operation directly when the gate is closed. It changes
// no data and needs no database: the gate refuses before any query, and the
// query count proves it. (Like any boot, it may create Laravel's git-ignored
// package-discovery cache files if they are missing.)
//
// Usage: php statutory-availability-probe.php [--env=<value> | --env <value>]

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$queries = 0;
DB::listen(function () use (&$queries): void {
    $queries++;
});

$result = [
    'application' => $app->environment(),
    'deployment' => Env::get('APP_ENV'),
    'config' => config('app.env'),
    'available' => StatutoryPayrollAvailability::isAvailable(),
];

try {
    StatutoryPayrollAvailability::assertAvailable();
    $result['gate'] = 'passed';
} catch (StatutoryPayrollUnavailableException) {
    $result['gate'] = 'refused';
}

if ($result['available'] === false) {
    try {
        $app->make(StatutoryRuleStatusReadService::class)->activeRuleVersions(new School, new User);
        $result['service'] = 'ran';
    } catch (StatutoryPayrollUnavailableException) {
        $result['service'] = 'refused';
    } catch (Throwable $e) {
        $result['service'] = $e::class;
    }
}

$result['queries'] = $queries;

fwrite(STDOUT, (string) json_encode($result));
