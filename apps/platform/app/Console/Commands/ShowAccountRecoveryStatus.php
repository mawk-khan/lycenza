<?php

namespace App\Console\Commands;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Models\EmailMessage;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\PlatformEmailScope;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Console\Command;

/**
 * Phase 0O.10A (ADR 0056 section 14): an operator's METADATA-ONLY view of one
 * account's recovery requests -- request id, timestamps, state, closed
 * reason and the recovery email's transport state. Never a secret, hash,
 * link or password. Platform-audited as a review.
 */
class ShowAccountRecoveryStatus extends Command
{
    protected $signature = 'platform:account-recovery-status {user : The account\'s exact email address or id}';

    protected $description = 'Show one account\'s password-recovery requests, metadata only (ADR 0056; audited).';

    public function handle(AuditRecorder $audit, PlatformEmailScope $scope): int
    {
        $identifier = trim((string) $this->argument('user'));
        $user = User::query()->where(fn ($q) => $q->where('email', EmailNormalizer::canonical($identifier))
            ->when(preg_match('/^[0-9a-f-]{36}$/i', $identifier) === 1, fn ($q) => $q->orWhere('id', $identifier)))
            ->first();

        if ($user === null) {
            $this->error('No account matches that exact email address or id.');

            return self::FAILURE;
        }

        $requests = AccountRecoveryRequest::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(20)->get();
        $emailStates = $scope->run(fn () => EmailMessage::query()->whereIn('id', $requests->pluck('email_message_id')->filter()->all())->get()->mapWithKeys(fn ($m) => [$m->id => $m->status->value])->all());

        $this->table(['Request', 'Created', 'Expires', 'State', 'Reason', 'Email'], $requests->map(fn (AccountRecoveryRequest $r) => [
            $r->id,
            $r->created_at->toIso8601String(),
            $r->expires_at->toIso8601String(),
            match (true) {
                $r->consumed_at !== null => 'consumed',
                $r->invalidated_at !== null => 'invalidated',
                $r->expires_at->isPast() => 'expired',
                default => 'open',
            },
            $r->invalidation_reason ?? '',
            $emailStates[$r->email_message_id] ?? 'none',
        ])->all());

        $audit->platform('auth.account_recovery_status_viewed', subject: $user, metadata: ['result_count' => $requests->count()]);

        return self::SUCCESS;
    }
}
