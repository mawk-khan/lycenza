<?php

use App\Models\School;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C15: suspends a School in a separate OS process, held open by HeldTransaction when it is a
// race holder. The statements are SchoolLifecycleService::suspend()'s own, in its order: the School
// row FOR UPDATE, then the status. Its authority, MFA and audit are proven elsewhere
// (PayrollSchoolLifecycleSuspensionTest); here only the lock it takes matters.
//
// Usage: php suspend-school.php <schoolId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId] = $argv;

try {
    echo HeldTransaction::run(function () use ($schoolId): string {
        $locked = School::query()->whereKey($schoolId)->lockForUpdate()->firstOrFail();
        $locked->forceFill(['status' => SchoolStatus::Suspended->value])->save();

        return 'suspended';
    });
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
}
