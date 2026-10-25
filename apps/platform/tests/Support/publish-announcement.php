<?php

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for FeeReminderConcurrencyTest (ORG.9D-2, ADR
// 0076 §74): run in a GENUINELY separate OS process so two real PHP
// processes race to publish the SAME approved announcement against real
// PostgreSQL. With CONCURRENCY_HOLD_DIR set, the publish runs inside a held
// transaction (Tests\Support\Concurrency\HeldTransaction), so the claim is
// uncommitted while the contender is observed blocked on it.
//
// Usage: php publish-announcement.php <schoolId> <announcementId> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $announcementId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $result = HeldTransaction::run(function () use ($app, $announcementId, $actorId) {
        $announcement = CommunicationAnnouncement::query()->findOrFail($announcementId);

        return $app->make(AnnouncementService::class)->publish($announcement, User::query()->findOrFail($actorId));
    });
    echo 'published:'.$result->message_id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
