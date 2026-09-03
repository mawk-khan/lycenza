<?php

use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for GradeScaleConcurrencyTest: one
// GradeBand removal attempt run in a genuinely separate OS process,
// raced against a concurrent activation of the same parent
// GradeScale. Mirrors tests/Support/transition-curriculum-delivery.php's
// structure.
//
// Usage: php remove-grade-band.php <schoolId> <gradeScaleId> <gradeBandId> <actorId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $gradeScaleId, $gradeBandId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $scale = GradeScale::query()->findOrFail($gradeScaleId);
    $band = GradeBand::query()->findOrFail($gradeBandId);
    $app->make(GradeScaleService::class)->removeBand($school, $scale, $band, $actor);

    echo 'removed';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
