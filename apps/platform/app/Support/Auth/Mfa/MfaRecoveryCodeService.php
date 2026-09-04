<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 0H.4D-P1 sections 4/10/11. Recovery codes are single-use,
 * hashed at rest (never encrypted -- see UserMfaRecoveryCode's
 * docblock), and returned in plaintext exactly once by the caller
 * (MfaEnrollmentService::confirm() / regenerate() below) -- this
 * service itself never logs or returns a previously-issued plaintext
 * code, because it never persists one.
 */
class MfaRecoveryCodeService
{
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L -- human-enterable

    /**
     * Issues a fresh set of config('mfa.recovery_codes_count') codes,
     * invalidating (consumed_at = now()) every previously-unused code
     * for this User in the SAME transaction -- regeneration policy
     * documented on the migration and ADR 0037: a code is "no longer
     * usable" via consumed_at, whether by legitimate use or by
     * superseding regeneration, never by deletion.
     *
     * @return list<string> the plaintext codes -- caller must return
     *                      these to the User exactly once and never
     *                      persist/log them.
     */
    public function issue(User $user): array
    {
        return DB::transaction(function () use ($user) {
            UserMfaRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $count = (int) config('mfa.recovery_codes_count');
            $plaintextCodes = [];

            for ($i = 0; $i < $count; $i++) {
                $code = $this->generateCode();
                $plaintextCodes[] = $code;

                UserMfaRecoveryCode::create([
                    'user_id' => $user->id,
                    'code_hash' => $code,
                ]);
            }

            return $plaintextCodes;
        });
    }

    /**
     * Atomic single-use consumption (Phase 0H.4D-P1 section 11):
     * `WHERE consumed_at IS NULL` on the actual UPDATE, never a
     * separate check-then-update -- two concurrent callers may both
     * find the same row via the read below, but only one of their
     * conditional UPDATEs can affect a row. See
     * Tests\Feature\Auth\Mfa\MfaRecoveryCodeConcurrencyTest for the
     * real two-process proof.
     */
    public function consume(User $user, string $rawCode): bool
    {
        $candidates = UserMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (! Hash::check($rawCode, $candidate->code_hash)) {
                continue;
            }

            $affected = UserMfaRecoveryCode::query()
                ->where('id', $candidate->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return $affected === 1;
        }

        return false;
    }

    public function remainingCount(User $user): int
    {
        return UserMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->count();
    }

    private function generateCode(): string
    {
        $raw = '';
        for ($i = 0; $i < 10; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return Str::substr($raw, 0, 5).'-'.Str::substr($raw, 5, 5);
    }
}
