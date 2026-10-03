<?php

namespace App\Domain\Identity\Application\Minimization;

use App\Domain\Identity\Application\Credentials\CredentialChangeService;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * E21.4 (E21-L1 project-adopted, India-aligned development position,
 * pending qualified ratification): the ONE writer of a minimized User.
 * Reached only from a reviewed, approved platform erasure case
 * (UserErasureAdapter), never from a request or a schedule, and only after
 * the caller has locked the User FOR UPDATE and proved, under that lock,
 * that no current purpose and no hold remains.
 *
 * Minimization keeps the `users` row (the stable, non-login actor every
 * retained audit event, grant, membership, Employee link and Finance or
 * Payroll actor column still references; nothing there is rewritten or
 * nulled) and removes the person's identity and every credential:
 * - name -> "Former user"; email -> `minimized-<id>@users.invalid` (derived
 *   from the id only, never from the old address); verification time and
 *   remember token cleared;
 * - password replaced by the hash of a random secret nobody sees, the
 *   credential version bumped (every session on every host ends), human
 *   personal access tokens deleted, an active elevation ended
 *   (CredentialChangeService::retire);
 * - MFA factors and recovery codes, account-recovery and activation
 *   credentials, and stored sessions deleted;
 * - disabled, with `minimized_at`.
 *
 * The database enforces the tombstone's shape, makes it immutable and
 * refuses it as a current principal again (migration 2026_11_16_090000).
 * The removed values are copied nowhere: the audit event carries no
 * metadata.
 */
final class UserMinimizationService
{
    public const FORMER_USER_NAME = 'Former user';

    public function __construct(
        private readonly CredentialChangeService $credentials,
        private readonly AuditRecorder $audit,
    ) {}

    /** The non-routable address of a minimized User (RFC 2606 `.invalid`), derived from its id alone. */
    public static function tombstoneEmail(string $userId): string
    {
        return 'minimized-'.strtolower($userId).'@users.invalid';
    }

    /** Minimizes the locked User; a User already minimized is left exactly as it is. */
    public function minimizeLocked(User $lockedUser): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Minimization runs inside the caller\'s transaction, with the User locked.');
        }
        if ($lockedUser->isMinimized()) {
            return;
        }

        DB::table('user_mfa_recovery_codes')->where('user_id', $lockedUser->id)->delete();
        DB::table('user_mfa_factors')->where('user_id', $lockedUser->id)->delete();
        DB::table('account_recovery_requests')->where('user_id', $lockedUser->id)->delete();
        DB::table('account_activation_credentials')->where('user_id', $lockedUser->id)->delete();
        DB::table('sessions')->where('user_id', $lockedUser->id)->delete();

        $this->credentials->retire($lockedUser);

        $now = now();
        $lockedUser->forceFill([
            'name' => self::FORMER_USER_NAME,
            'email' => self::tombstoneEmail($lockedUser->id),
            'email_verified_at' => null,
            'remember_token' => null,
            'is_disabled' => true,
            'disabled_at' => $lockedUser->disabled_at ?? $now,
            'minimized_at' => $now,
        ])->save();

        $this->audit->platform('platform.user.minimized', subject: $lockedUser);
    }
}
