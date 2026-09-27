<?php

use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Email\EmailSubmissionService;
use App\Support\Email\Events\EmailEventApplier;
use App\Support\Email\Events\EmailEventIngestion;
use App\Support\Email\Events\EmailEventType;
use App\Support\Email\Events\NormalizedEmailEvent;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concurrency\HeldTransaction;

// Phase 0O.9A (ADR 0055 sections 9.3, 11.3): one email-layer operation in a
// GENUINELY separate OS process, for EmailConcurrencyTest (the parent forces
// and verifies the overlap -- Tests\Concerns\ForcesConcurrentOverlap).
//
// Usage: php race-email-message.php <schoolId> <messageId> <operation> [<arg>]
//   claim | cancel | retry | ingest <eventKey> | apply <eventId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake(); // nothing this process does may dispatch real work

[, $schoolId, $messageId, $operation] = $argv;
$arg = $argv[4] ?? null;

$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    echo HeldTransaction::run(fn () => match ($operation) {
        'claim' => $app->make(EmailSubmissionService::class)->claim($messageId) !== null ? 'claimed' : 'skipped',
        'cancel' => $app->make(OutboundEmailGateway::class)->cancelForSource('guardian_account_invitation', (string) EmailMessage::query()->findOrFail($messageId)->source_id, 'source_revoked') ? 'cancelled' : 'not_cancelled',
        'retry' => $app->make(EmailSubmissionService::class)->retryNow(EmailMessage::query()->findOrFail($messageId)) ? 'retried' : 'not_retried',
        'ingest' => $app->make(EmailEventIngestion::class)->ingest('fake', [new NormalizedEmailEvent((string) $arg, EmailEventType::BouncePermanent, 'fake-race', null)])['new'] === 1 ? 'stored' : 'duplicate',
        'apply' => (function () use ($app, $context, $arg): string {
            $context->clear(); // the applier sets the School itself, from stored data
            $app->make(EmailEventApplier::class)->apply((string) $arg);

            return 'applied';
        })(),
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class;
} finally {
    $context->clearAll();
}
