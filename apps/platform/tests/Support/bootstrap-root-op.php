<?php

use App\Domain\Platform\Application\Roles\PlatformRootProvisioningRefusedException;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PlatformRootBootstrapTest: one first-boot
// root bootstrap in a GENUINELY separate OS process, on the admin
// connection made the default (see provision-root-op.php).
//
// Usage: php bootstrap-root-op.php <email>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => PlatformRootProvisioningService::CONNECTION]);

[, $email] = $argv;

try {
    echo HeldTransaction::run(function () use ($app, $email) {
        $app->make(PlatformRootProvisioningService::class)->bootstrapFirstRoot('Race Operator', $email, 'Race-Bootstrap-Passw0rd');

        return 'bootstrapped';
    });
} catch (PlatformRootProvisioningRefusedException $e) {
    echo 'refused:'.$e->reason;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
