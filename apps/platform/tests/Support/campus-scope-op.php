<?php

use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for CampusScopedRoleGrantConcurrencyTest
// (ORG.7A): one Campus-scope operation in a GENUINELY separate OS process.
//
// Usage:
//   php campus-scope-op.php inactivate <schoolId> <campusId>
//   php campus-scope-op.php grant <actorId> <schoolId> <membershipId> <roleKey> <campusId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args) {
        switch ($operation) {
            case 'inactivate':
                [$schoolId, $campusId] = $args;
                $school = School::query()->findOrFail($schoolId);
                $app->make(TenantContext::class)->withSchool($school, fn () => Campus::query()->findOrFail($campusId)->forceFill(['status' => 'inactive'])->save());

                return 'inactivated';
            case 'grant':
                [$actorId, $schoolId, $membershipId, $roleKey, $campusId] = $args;
                $app->make(StaffAccessService::class)->grantRole(School::query()->findOrFail($schoolId), User::query()->findOrFail($actorId), $membershipId, $roleKey, $campusId);

                return 'granted';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    });
} catch (StaffAccountException $e) {
    echo 'rejected:'.$e->outcome;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
