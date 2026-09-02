<?php

use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for
// CurriculumDeliveryTransitionConcurrencyTest: one expected-status
// compare-and-swap transition in a genuinely separate OS process, so
// two real processes race the SAME delivery with the SAME
// expected_status against real PostgreSQL.
//
// Usage: php transition-curriculum-delivery.php <schoolId> <deliveryId> <expectedStatus> <newStatus> <completedOn> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $deliveryId, $expectedStatus, $newStatus, $completedOn, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $delivery = $app->make(CurriculumDeliveryService::class)->transition(
        $school,
        $deliveryId,
        $expectedStatus,
        $newStatus,
        $completedOn === '-' ? null : $completedOn,
        $actor,
    );

    echo 'transitioned:'.$delivery->status;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
