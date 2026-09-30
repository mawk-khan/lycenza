<?php

use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Application\Exceptions\CurriculumDeliveryException;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for TeacherDeliveryConcurrencyTest (TCH.3,
// ADR 0063 section 20): one OWNED teacher Curriculum Delivery write in a
// GENUINELY separate OS process -- CurriculumDeliveryService with the
// TeacherDeliveryGuard, exactly as the /my/ endpoints call it. Mirrors
// acting-employee-op.php; the ineligibility side reuses acting-employee-op.php,
// staff-account-op.php and teaching-assignment-op.php.
//
// Usage:
//   php teacher-delivery-op.php start    <schoolId> <userId> <sectionId> <offeringId> <unitId> <startedOn>
//   php teacher-delivery-op.php complete <schoolId> <userId> <deliveryId> <completedOn>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args): string {
        $service = $app->make(CurriculumDeliveryService::class);
        $access = $app->make(TeacherDeliveryAccess::class);

        switch ($operation) {
            case 'start':
                [$schoolId, $userId, $sectionId, $offeringId, $unitId, $startedOn] = $args;
                $user = User::query()->findOrFail($userId);
                $delivery = $service->start(School::query()->findOrFail($schoolId), $offeringId, $sectionId, $unitId, $startedOn, $user, $access->guard($user));

                return 'started:'.$delivery->id;
            case 'complete':
                [$schoolId, $userId, $deliveryId, $completedOn] = $args;
                $user = User::query()->findOrFail($userId);
                $service->transition(School::query()->findOrFail($schoolId), $deliveryId, CurriculumDelivery::STATUS_IN_PROGRESS, CurriculumDelivery::STATUS_COMPLETED, $completedOn, $user, $access->guard($user));

                return 'completed';
        }

        return 'unknown';
    });
} catch (ActingEmployeeUnavailableException $e) {
    echo 'denied:'.$e->reason;
} catch (CurriculumDeliveryException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (ModelNotFoundException) {
    echo 'not_found';
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
} finally {
    $context->clearAll();
}
