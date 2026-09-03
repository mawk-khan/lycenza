<?php

use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for GradeScaleConcurrencyTest: one
// activation attempt (draft/inactive -> active) run in a genuinely
// separate OS process, so two real processes can race the SAME
// GradeScale against real PostgreSQL. Mirrors
// tests/Support/transition-curriculum-delivery.php's structure.
//
// Usage: php activate-grade-scale.php <schoolId> <gradeScaleId> <actorId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $gradeScaleId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $scale = GradeScale::query()->findOrFail($gradeScaleId);
    $updated = $app->make(GradeScaleService::class)->update($school, $scale, ['status' => 'active'], $actor);

    echo 'activated:'.$updated->status;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
