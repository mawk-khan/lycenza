<?php

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// SIS 2.1 GuardianContactConcurrencyTest: one contact correction, reactivation
// or deactivation in a GENUINELY separate OS process, run through
// HeldTransaction so the parent can force and observe the overlap. A
// correction reads the contact's version FIRST, like a form opened before the
// other operation.
//
// Usage: php guardian-contact-op.php correct <schoolId> <contactId> <value>
//        php guardian-contact-op.php reactivate <schoolId> <contactId>
//        php guardian-contact-op.php deactivate <schoolId> <contactId>
//        php guardian-contact-op.php primary <schoolId> <contactId>      (SIS 2.2)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $op, $schoolId, $id] = $argv;

$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    $contact = GuardianContact::query()->findOrFail($id);
    $service = $app->make(GuardianContactService::class);
    if ($op === 'correct') {
        $version = GuardianContactService::version($contact);
        HeldTransaction::run(fn () => $service->correct($contact, $argv[4], $contact->label, $version));
        echo 'corrected';
    } elseif ($op === 'primary') {
        HeldTransaction::run(fn () => $service->setPrimary($contact));
        echo 'primary';
    } elseif ($op === 'reactivate') {
        HeldTransaction::run(fn () => $service->reactivate($contact));
        echo 'reactivated';
    } else {
        HeldTransaction::run(fn () => $service->deactivate($contact));
        echo 'deactivated';
    }
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.':'.(method_exists($e, 'errorCode') ? $e->errorCode() : '');
} finally {
    $context->clearAll();
}
