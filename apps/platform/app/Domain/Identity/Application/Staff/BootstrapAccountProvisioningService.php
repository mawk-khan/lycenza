<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Infrastructure\AccountActivationCredential;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 0O.12B (ADR 0059 section 5): flow A -- the platform-assisted
 * bootstrap account, reached ONLY from the interactive operator console
 * (`platform:provision-school-admin-account`); there is no HTTP route.
 *
 * - provision(): creates ONE credential-less User (`password` NULL, ADR 0059
 *   section 4) and its one-time activation credential. No membership, no
 *   School role, no Employee, no platform or Group grant: School authority
 *   still comes only from root through the ADR 0047 create/replace path.
 * - reissue(): a new credential for a still credential-less User; the
 *   previous open one ends (`superseded`) in the same transaction, under the
 *   User row lock (one open credential per User, database-enforced).
 *
 * The optional School is audit context only and must be `provisioning`
 * (a bootstrap target). The link -- canonical platform origin, secret in the
 * URL FRAGMENT -- exists only in the returned IssuedActivation. Platform
 * audit: `platform.account.provisioned` / `platform.account.activation_reissued`
 * (actor null, `method: console`, ids only).
 */
final class BootstrapAccountProvisioningService
{
    public const DEFAULT_LIFETIME_HOURS = 24;

    public const MAX_LIFETIME_HOURS = 72;

    public const PROVISIONED = 'platform.account.provisioned';

    public const REISSUED = 'platform.account.activation_reissued';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CanonicalOrigin $origins,
    ) {}

    public function existingAccount(string $email): ?User
    {
        return User::query()->where('email', EmailNormalizer::canonical($email))->first();
    }

    /**
     * @throws BootstrapAccountRefusedException
     */
    public function assertBootstrapSchool(?School $school): void
    {
        if ($school !== null && ! $school->isProvisioning()) {
            throw new BootstrapAccountRefusedException('school_not_provisioning', 'That School is not provisioning: its bootstrap administrator can no longer be set (ADR 0047).');
        }
    }

    /**
     * @throws BootstrapAccountRefusedException
     */
    public function provision(string $email, string $name, ?School $school, int $lifetimeHours = self::DEFAULT_LIFETIME_HOURS): IssuedActivation
    {
        $email = EmailNormalizer::canonical($email);
        $name = trim($name);
        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'string', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new BootstrapAccountRefusedException('invalid_input', $validator->errors()->first());
        }

        $this->assertBootstrapSchool($school);
        $hours = $this->lifetime($lifetimeHours);

        try {
            return DB::transaction(function () use ($email, $name, $school, $hours): IssuedActivation {
                $existing = User::query()->where('email', $email)->lockForUpdate()->first();

                if ($existing !== null) {
                    $this->refuseExisting($existing);

                    return $this->issue($existing, $school, $hours, reissued: true);
                }

                $created = User::query()->create(['name' => $name, 'email' => $email, 'password' => null]);
                // Re-read under lock: the row's database defaults (credential_version).
                $user = User::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();

                return $this->issue($user, $school, $hours, reissued: false);
            });
        } catch (UniqueConstraintViolationException) {
            throw new BootstrapAccountRefusedException('account_exists', 'An account with that email was created at the same moment. Run the command again.');
        }
    }

    /**
     * @throws BootstrapAccountRefusedException
     */
    public function reissue(User $user, ?School $school, int $lifetimeHours = self::DEFAULT_LIFETIME_HOURS): IssuedActivation
    {
        $this->assertBootstrapSchool($school);
        $hours = $this->lifetime($lifetimeHours);

        return DB::transaction(function () use ($user, $school, $hours): IssuedActivation {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->refuseExisting($locked);

            return $this->issue($locked, $school, $hours, reissued: true);
        });
    }

    /**
     * @throws BootstrapAccountRefusedException
     */
    private function refuseExisting(User $user): void
    {
        if ($user->isDisabled()) {
            throw new BootstrapAccountRefusedException('account_disabled', 'That account is disabled. Re-enabling an account is not part of this command.');
        }

        if ($user->hasLocalCredential()) {
            throw new BootstrapAccountRefusedException('account_has_credential', 'That account already has a password: name it directly as the School\'s bootstrap administrator.');
        }
    }

    private function issue(User $lockedUser, ?School $school, int $hours, bool $reissued): IssuedActivation
    {
        AccountActivationCredential::query()
            ->where('user_id', $lockedUser->id)
            ->whereNull('consumed_at')->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidation_reason' => 'superseded']);

        $credential = OneTimeCredential::generate();
        $now = now();
        $expiresAt = $now->copy()->addHours($hours);

        $row = AccountActivationCredential::query()->create([
            'selector' => $credential->selector,
            'user_id' => $lockedUser->id,
            'secret_hash' => OneTimeCredential::hash($credential->secret),
            'credential_version' => $lockedUser->credential_version,
            'created_via' => 'console',
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->platform($reissued ? self::REISSUED : self::PROVISIONED, subject: $lockedUser, metadata: array_filter([
            'user_id' => $lockedUser->id,
            'activation_credential_id' => $row->id,
            'method' => 'console',
            'school_id' => $school?->id,
        ], fn ($v) => $v !== null));

        return new IssuedActivation(
            $lockedUser,
            $this->origins->platformUrl("account-activation/{$credential->selector}").'#'.$credential->secret,
            $expiresAt,
            $reissued,
        );
    }

    private function lifetime(int $hours): int
    {
        if ($hours < 1 || $hours > self::MAX_LIFETIME_HOURS) {
            throw new BootstrapAccountRefusedException('invalid_lifetime', 'The activation link lifetime must be between 1 and '.self::MAX_LIFETIME_HOURS.' hours.');
        }

        return $hours;
    }
}
