<?php

namespace Tests\Feature\Auth\Mfa\Concerns;

use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Test-only helper: enrolls a User with an ACTIVE totp factor directly
 * (bypassing the HTTP enrollment flow, which is exercised by its own
 * dedicated tests) so other suites can set up "a User who already has
 * MFA enabled" concisely.
 */
trait CreatesMfaFixtures
{
    protected function enrollActiveMfaFactor(User $user, ?string $secret = null): UserMfaFactor
    {
        $secret ??= app(Google2FA::class)->generateSecretKey();

        return UserMfaFactor::create([
            'user_id' => $user->id,
            'type' => 'totp',
            'secret_encrypted' => $secret,
            'status' => 'active',
            'confirmed_at' => now(),
        ]);
    }

    /** @return list<string> plaintext codes */
    protected function issueRecoveryCodes(User $user, int $count = 3): array
    {
        $plaintext = [];

        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(5)));
            $plaintext[] = $code;

            UserMfaRecoveryCode::create([
                'user_id' => $user->id,
                'code_hash' => $code,
            ]);
        }

        return $plaintext;
    }

    protected function currentTotpCodeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
