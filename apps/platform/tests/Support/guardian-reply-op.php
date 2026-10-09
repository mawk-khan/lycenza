<?php

use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Portal\GuardianConversationService;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// POR.4 (ADR 0070 §27.6): one operation in a GENUINELY separate OS process,
// for GuardianConversationConcurrencyTest.
//
//   php guardian-reply-op.php reply <schoolId> <userId> <threadId> <key> <body>
//   php guardian-reply-op.php offboard <schoolId> <actorId> <guardianId>
//   php guardian-reply-op.php remove-participant <schoolId> <participantId>
//   php guardian-reply-op.php close-thread <schoolId> <threadId>
//
// With CONCURRENCY_HOLD_DIR set, the operation runs inside a held
// transaction (HeldTransaction): it acts, touches `acted`, and commits only
// on `release`.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);

        return match ($operation) {
            'reply' => (function () use ($app, $school, $args): string {
                $user = User::query()->findOrFail($args[1]);
                $guardian = $app->make(ActingGuardianResolver::class)->require($user, $school);
                $result = $app->make(GuardianConversationService::class)->reply($school, $guardian, $user, $args[2], $args[4], $args[3]);

                return 'replied:'.$result->messageId.':'.($result->replayed ? 'replayed' : 'new');
            })(),
            'offboard' => (function () use ($app, $school, $args, $context): string {
                $actor = User::query()->findOrFail($args[1]);
                $guardian = $context->withSchool($school, fn () => Guardian::query()->findOrFail($args[2]));
                $app->make(GuardianOffboardingService::class)->offboard($school, $actor, $guardian);

                return 'offboarded';
            })(),
            'remove-participant' => $context->withSchool($school, function () use ($app, $args): string {
                $app->make(CommunicationThreadService::class)->removeParticipant(CommunicationThreadParticipant::query()->findOrFail($args[1]));

                return 'removed';
            }),
            'close-thread' => $context->withSchool($school, function () use ($args): string {
                DB::table('communication_threads')->where('id', $args[1])->update(['status' => 'closed', 'updated_at' => now()]);

                return 'closed';
            }),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
