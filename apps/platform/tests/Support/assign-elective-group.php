<?php

use App\Domain\AcademicStructure\Application\ElectiveGroupService;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Phase 1F.3 configuration-vs-
// enrollment concurrency tests: run in a GENUINELY separate OS process
// (via Symfony\Process::start(), non-blocking) so a real, independent
// PHP process races ElectiveGroupService::assignOffering() against a
// concurrent enroll-subject-offering.php process for the SAME
// SubjectOffering against real PostgreSQL. Mirrors
// enroll-subject-offering.php's identical pattern.
//
// Usage: php assign-elective-group.php <schoolId> <electiveGroupId> <subjectOfferingId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $electiveGroupId, $subjectOfferingId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $group = ElectiveGroup::query()->findOrFail($electiveGroupId);
    $offering = SubjectOffering::query()->findOrFail($subjectOfferingId);
    $result = $app->make(ElectiveGroupService::class)->assignOffering($group, $offering);
    echo 'assigned:'.($result->elective_group_id ?? 'null');
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
