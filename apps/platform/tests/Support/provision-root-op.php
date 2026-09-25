<?php

use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PlatformRootProvisioningTest: one root
// provisioning in a GENUINELY separate OS process. The provisioning runs
// on the admin connection, so that connection is made the default here:
// HeldTransaction's outer (held) transaction and session name then wrap
// the same connection the service writes on.
//
// Usage: php provision-root-op.php <userEmail>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => PlatformRootProvisioningService::CONNECTION]);

[, $email] = $argv;

try {
    echo HeldTransaction::run(function () use ($app, $email) {
        $provisioning = $app->make(PlatformRootProvisioningService::class);

        return $provisioning->provision($provisioning->resolveTarget($email));
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
