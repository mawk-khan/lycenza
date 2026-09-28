<?php

use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryResetService;
use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Concurrency\HeldTransaction;

// Phase 0O.10A (ADR 0056 section 10.2): one credential operation in a
// GENUINELY separate OS process, for AccountRecoveryConcurrencyTest (the
// parent forces and verifies the overlap -- Tests\Concerns\ForcesConcurrentOverlap).
// Identity-level: no School context.
//
// Usage: php race-account-recovery.php <operation> <args...>
//   reset <selector> <secret> <password>
//   disable <userId>
//   email <userId> <newEmail>
//   operator <userId> <password>
//   pat <plainToken>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Queue::fake(); // the post-commit security notice is not this race's subject

$operation = $argv[1];
$args = array_slice($argv, 2);

try {
    echo HeldTransaction::run(fn () => match ($operation) {
        'reset' => $app->make(AccountRecoveryResetService::class)->reset($args[0], $args[1], $args[2], $args[2])->outcome,
        'disable' => DB::transaction(function () use ($args): string {
            User::query()->whereKey($args[0])->lockForUpdate()->firstOrFail()->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

            return 'disabled';
        }),
        'email' => DB::transaction(function () use ($args): string {
            User::query()->whereKey($args[0])->lockForUpdate()->firstOrFail()->forceFill(['email' => $args[1]])->save();

            return 'email_changed';
        }),
        'operator' => DB::transaction(function () use ($app, $args): string {
            $app->make(CredentialChangeService::class)->setPassword(User::query()->whereKey($args[0])->lockForUpdate()->firstOrFail(), $args[1]);

            return 'operator_reset';
        }),
        // Sanctum's own use of a token: find it, then record its use.
        'pat' => (function () use ($args): string {
            $token = PersonalAccessToken::findToken($args[0]);
            if ($token === null) {
                return 'token_gone';
            }

            return DB::table('personal_access_tokens')->where('id', $token->id)->update(['last_used_at' => now()]) === 1 ? 'token_used' : 'token_gone';
        })(),
    });
} catch (Throwable $e) {
    echo 'error:'.$e::class;
}
