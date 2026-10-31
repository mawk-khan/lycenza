<?php

use App\Domain\Students\Application\Exceptions\StudentException;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentHouseConcurrencyTest (SIS 11.7): run
// in a GENUINELY separate OS process so two real PHP processes race
// StudentHouseService against real PostgreSQL.
//
// Usage: php house-op.php assign <schoolId> <studentId> <actorId> <houseId> <reason|-> <version>
//        php house-op.php responsibility <schoolId> <studentId> <actorId> <responsibility> <version>
//        php house-op.php deactivate <schoolId> <houseId> <actorId>   (expects House version 1)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$op = $argv[1];
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($argv[2]);
$actor = User::query()->findOrFail($argv[4]);
$service = $app->make(StudentHouseService::class);

try {
    $row = HeldTransaction::run(fn () => match ($op) {
        'assign' => $service->assign($school, $actor, $argv[3], $argv[5], $argv[6] === '-' ? null : HouseMembershipReason::from($argv[6]), $argv[7]),
        'responsibility' => $service->assignResponsibility($school, $actor, $argv[3], HouseResponsibility::from($argv[5]), $argv[6]),
        'deactivate' => $app->make(HouseAdministrationService::class)->setStatus($school, $actor, $argv[3], 'inactive', 1),
    });
    echo 'done:'.$row->getKey();
} catch (StudentException $e) {
    echo 'refused:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
