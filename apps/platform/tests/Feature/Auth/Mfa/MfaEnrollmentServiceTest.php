<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use App\Support\Auth\Mfa\Exceptions\MfaAlreadyEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaEnrollmentNotPendingException;
use App\Support\Auth\Mfa\Exceptions\MfaInvalidCodeException;
use App\Support\Auth\Mfa\MfaAuditActions;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

class MfaEnrollmentServiceTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function begin_creates_a_pending_factor_and_audits_it(): void
    {
        $user = User::factory()->create();

        $result = app(MfaEnrollmentService::class)->begin($user);

        $this->assertSame('pending', $result['factor']->status);
        $this->assertNotEmpty($result['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $result['otpAuthUri']);
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::ENROLLMENT_STARTED)->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function begin_supersedes_a_stale_pending_factor_rather_than_accumulating(): void
    {
        $user = User::factory()->create();
        $service = app(MfaEnrollmentService::class);

        $service->begin($user);
        $service->begin($user);

        $this->assertSame(1, UserMfaFactor::query()->where('user_id', $user->id)->where('status', 'pending')->count());
    }

    #[Test]
    public function begin_refuses_when_an_active_factor_already_exists(): void
    {
        $user = User::factory()->create();
        $this->enrollActiveMfaFactor($user);

        $this->expectException(MfaAlreadyEnrolledException::class);

        app(MfaEnrollmentService::class)->begin($user);
    }

    #[Test]
    public function confirm_with_a_valid_code_activates_the_factor_and_issues_recovery_codes(): void
    {
        $user = User::factory()->create();
        $result = app(MfaEnrollmentService::class)->begin($user);

        $codes = app(MfaEnrollmentService::class)->confirm($user, $this->currentTotpCodeFor($result['secret']));

        $this->assertCount(10, $codes);
        $result['factor']->refresh();
        $this->assertSame('active', $result['factor']->status);
        $this->assertNotNull($result['factor']->confirmed_at);
        $this->assertSame(10, UserMfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->count());
        $this->assertSame(
            1,
            PlatformAuditEvent::query()->where('event_type', MfaAuditActions::ENROLLED)->where('actor_user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function confirm_with_an_incorrect_code_does_not_activate_the_factor(): void
    {
        $user = User::factory()->create();
        $result = app(MfaEnrollmentService::class)->begin($user);

        try {
            app(MfaEnrollmentService::class)->confirm($user, '000000');
            $this->fail('Expected MfaInvalidCodeException.');
        } catch (MfaInvalidCodeException) {
            // expected
        }

        $result['factor']->refresh();
        $this->assertSame('pending', $result['factor']->status);
    }

    #[Test]
    public function confirm_with_no_pending_factor_is_refused(): void
    {
        $user = User::factory()->create();

        $this->expectException(MfaEnrollmentNotPendingException::class);

        app(MfaEnrollmentService::class)->confirm($user, '000000');
    }

    #[Test]
    public function the_secret_is_never_returned_by_the_qr_code_url_in_plaintext_form_that_would_be_logged(): void
    {
        // Structural proof that begin()'s only external output channel
        // for the secret is the in-memory return array, never a log
        // call -- see MfaAuditPayloadSecurityTest for the audit-side
        // proof.
        $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents(app_path('Support/Auth/Mfa/MfaEnrollmentService.php')));

        $this->assertStringNotContainsString('Log::', $source);
        $this->assertStringNotContainsString('logger(', $source);
    }
}
