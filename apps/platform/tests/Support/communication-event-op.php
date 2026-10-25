<?php

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Events\CommunicationEventService;
use App\Domain\Communications\Application\Events\CommunicationEventTerms;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for CommunicationEventConcurrencyTest (ORG.9D-3,
// ADR 0076 §75): one Event lifecycle step in a GENUINELY separate OS process,
// optionally held open (Tests\Support\Concurrency\HeldTransaction) so the
// contender is observed blocked on the Event row lock.
//
// Usage: php communication-event-op.php <schoolId> <eventId> <actorUserId> <publish|cancel|edit|approve|withdraw>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $eventId, $actorId, $op] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $result = HeldTransaction::run(function () use ($app, $school, $eventId, $actorId, $op) {
        $actor = User::query()->findOrFail($actorId);
        $event = CommunicationEvent::query()->findOrFail($eventId);
        $events = $app->make(CommunicationEventService::class);

        return match ($op) {
            'publish' => $events->publish($event, $actor)->status,
            'cancel' => $events->cancel($event, $actor)->status,
            'withdraw' => $events->withdraw($event, $actor)->status,
            'edit' => $events->update($event, $actor, CommunicationEventTerms::fromInput($school, [
                'title' => 'Edited in a race', 'body' => $event->body, 'location' => $event->location, 'date_mode' => 'all_day',
                'starts_on' => $event->starts_on->format('Y-m-d'), 'ends_on' => $event->ends_on->format('Y-m-d'), 'audience_type' => $event->audience_type,
            ]))->status,
            'approve' => $app->make(CommunicationApprovalService::class)->approve(
                CommunicationApprovalRequest::query()->where('event_id', $eventId)->where('status', 'pending')->firstOrFail(), $actor)->status,
        };
    });
    echo 'ok:'.$result;
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}
