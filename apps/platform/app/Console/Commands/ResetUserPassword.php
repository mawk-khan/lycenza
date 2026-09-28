<?php

namespace App\Console\Commands;

use App\Domain\Identity\Application\AccountRecovery\SecurityNoticeService;
use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * Phase 0O.10A (ADR 0056 sections 4.2 and 14): the operator's password reset
 * -- the ONLY recovery path for root (who has no public email recovery) and
 * the high-assurance path for anyone else. Operator console only: no HTTP
 * route exists.
 *
 * Interactive only: the User is named by exact canonical email (or id) and
 * confirmed; the new password is entered through a hidden prompt, twice,
 * and must pass the ONE policy (`Password::defaults()`). It is never printed,
 * logged, audited or queued. The change goes through the same
 * CredentialChangeService as self-service recovery: credential_version
 * (every session on every host ends), remember token, human personal access
 * tokens, elevation, outstanding recovery credentials. MFA is untouched --
 * lost MFA stays `platform.users.mfa.reset` (ADR 0037/0046). Platform-audited
 * as `auth.password_reset_by_operator`; a security notice follows.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'platform:user-password-reset {user : The account\'s exact email address or id}';

    protected $description = 'Set a new password for one account (ADR 0056; interactive operator console only, audited).';

    public function handle(CredentialChangeService $credentials, AuditRecorder $audit, SecurityNoticeService $notices): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Refused: a password reset is interactive only (the password is entered through a hidden prompt).');

            return self::FAILURE;
        }

        $identifier = trim((string) $this->argument('user'));
        $user = User::query()->where(fn ($q) => $q->where('email', EmailNormalizer::canonical($identifier))
            ->when(preg_match('/^[0-9a-f-]{36}$/i', $identifier) === 1, fn ($q) => $q->orWhere('id', $identifier)))
            ->first();

        if ($user === null) {
            $this->error('Refused: no account matches that exact email address or id.');

            return self::FAILURE;
        }

        try {
            $password = (string) $this->secret('New password (hidden)', false);
            $confirmation = (string) $this->secret('Confirm new password (hidden)', false);
        } catch (ConsoleRuntimeException) {
            $this->error('Refused: this terminal cannot hide input. Run the command from an interactive terminal.');

            return self::FAILURE;
        }

        $rules = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($rules->fails()) {
            $this->error('Refused: '.$rules->errors()->first('password').' Nothing changed.');

            return self::FAILURE;
        }

        $this->warn('Every session, API token and elevation of this account ends; MFA is unchanged.');
        $this->table(['Account id', 'Email'], [[$user->id, $user->email]]);

        if (! $this->confirm('Set this new password?')) {
            $this->info('Nothing changed.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($user, $password, $credentials, $audit) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $result = $credentials->setPassword($locked, $password);

            $audit->platform('auth.password_reset_by_operator', subject: $locked, metadata: [
                'revoked_personal_access_tokens' => $result->revokedPersonalAccessTokens,
                'ended_elevation' => $result->endedElevation,
            ]);

            return $result;
        });

        $notices->passwordChanged($user->fresh() ?? $user);

        $this->info("Password changed (audited as auth.password_reset_by_operator); {$result->revokedPersonalAccessTokens} API token(s) revoked.");

        return self::SUCCESS;
    }
}
