<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\MfaAuditActions;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use App\Support\Auth\Mfa\MfaFactorService;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 12/21/22: no MFA audit action may ever carry
 * the TOTP secret, a submitted code, a recovery code (plaintext or
 * hash), or an otpauth URI in its metadata.
 */
class MfaAuditPayloadSecurityTest extends TestCase
{
    use CreatesMfaFixtures;

    private const array FORBIDDEN_FRAGMENTS = ['otpauth://', 'secret', 'Secret'];

    #[Test]
    public function enrollment_started_and_enrolled_audit_events_never_carry_the_secret(): void
    {
        $user = User::factory()->create();
        $service = app(MfaEnrollmentService::class);
        $result = $service->begin($user);
        $service->confirm($user, $this->currentTotpCodeFor($result['secret']));

        $events = PlatformAuditEvent::query()
            ->whereIn('event_type', [MfaAuditActions::ENROLLMENT_STARTED, MfaAuditActions::ENROLLED])
            ->where('actor_user_id', $user->id)
            ->get();

        $this->assertGreaterThan(0, $events->count());

        foreach ($events as $event) {
            $metadataJson = json_encode($event->metadata);
            $this->assertStringNotContainsString($result['secret'], $metadataJson);

            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                $this->assertStringNotContainsString($fragment, $metadataJson, "event {$event->event_type} metadata must never contain '{$fragment}'.");
            }
        }
    }

    #[Test]
    public function disable_audit_event_never_carries_the_code_used_to_authorize_it(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);
        $code = $this->currentTotpCodeFor($secret);

        app(MfaFactorService::class)->disable($user, $code);

        $event = PlatformAuditEvent::query()
            ->where('event_type', MfaAuditActions::DISABLED)
            ->where('actor_user_id', $user->id)
            ->firstOrFail();

        $metadataJson = json_encode($event->metadata);
        $this->assertStringNotContainsString($code, $metadataJson);
    }

    #[Test]
    public function recovery_code_regeneration_audit_event_never_carries_any_plaintext_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->enrollActiveMfaFactor($user);
        $oldCodes = app(MfaRecoveryCodeService::class)->issue($user);

        $this->actingAs($user);
        $this->post('/app/account/security/password-confirmation', ['password' => 'correct-password'])->assertOk();
        $response = $this->post('/app/account/security/mfa/recovery-codes/regenerate')->assertOk();
        $newCodes = $response->json('recoveryCodes');

        $event = PlatformAuditEvent::query()
            ->where('event_type', MfaAuditActions::RECOVERY_CODES_REGENERATED)
            ->where('actor_user_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $metadataJson = json_encode($event->metadata);

        foreach (array_merge($oldCodes, $newCodes) as $code) {
            $this->assertStringNotContainsString($code, $metadataJson);
        }
    }
}
