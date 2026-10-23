<?php

use App\Domain\Transport\Application\TransportRiderImport;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for TransportRiderImportConcurrencyTest
// (ORG.9C-4, ADR 0076 §72): runs a whole-School rider import (parse,
// preview, commit) in a GENUINELY separate OS process, so two batches -- or
// a batch and a single assignment -- race against real PostgreSQL.
//
// Usage: php import-transport-riders.php <schoolId> <actorUserId> <base64 CSV>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $actorId, $csv] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $import = $app->make(TransportRiderImport::class);
    $rows = $import->parse((string) base64_decode($csv));
    $preview = $import->preview($school, CampusScope::wholeSchool(), $actor, $rows);
    $result = $import->commit($school, CampusScope::wholeSchool(), $actor, $rows, $preview['issuedAt'], $preview['signature']);
    echo 'committed:'.$result['created'].':'.$result['alreadyAssigned'];
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
