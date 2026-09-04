<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use App\Support\Auth\Mfa\Exceptions\MfaInvalidCodeException;
use App\Support\Auth\Mfa\Exceptions\MfaNotEnrolledException;
use App\Support\Auth\Mfa\MfaAuditActions;
use App\Support\Auth\Mfa\MfaFactorService;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

class MfaFactorServiceTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function disable_with_a_valid_totp_code_revokes_the_factor_and_expires_recovery_codes(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $factor = $this->enrollActiveMfaFactor($user, $secret);
        app(MfaRecoveryCodeService::class)->issue($user);

        app(MfaFactorService::class)->disable($user, $this->currentTotpCodeFor($secret));

        $factor->refresh();
        $this->assertSame('revoked', $factor->status);
        $this->assertSame(0, UserMfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->count());
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::DISABLED)->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function disable_with_a_valid_recovery_code_also_succeeds(): void
    {
        $user = User::factory()->create();
        $this->enrollActiveMfaFactor($user);
        $codes = $this->issueRecoveryCodes($user);

        app(MfaFactorService::class)->disable($user, $codes[0]);

        $this->assertSame(0, UserMfaFactor::query()->where('user_id', $user->id)->where('status', 'active')->count());
    }

    #[Test]
    public function disable_with_an_invalid_code_is_refused_and_leaves_the_factor_active(): void
    {
        $user = User::factory()->create();
        $factor = $this->enrollActiveMfaFactor($user);

        try {
            app(MfaFactorService::class)->disable($user, '000000');
            $this->fail('Expected MfaInvalidCodeException.');
        } catch (MfaInvalidCodeException) {
            // expected
        }

        $factor->refresh();
        $this->assertSame('active', $factor->status);
    }

    #[Test]
    public function disable_when_no_active_factor_exists_is_refused(): void
    {
        $user = User::factory()->create();

        $this->expectException(MfaNotEnrolledException::class);

        app(MfaFactorService::class)->disable($user, '000000');
    }
}
