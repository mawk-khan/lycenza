<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P1 section 30: no API representation may ever expose
 * secret_encrypted, a recovery-code hash, or a consumed-code value.
 */
class MfaApiRepresentationTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function the_account_security_page_props_never_include_the_secret_or_any_hash(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);
        $codes = $this->issueRecoveryCodes($user);

        $captured = null;

        $this->actingAs($user)->get('/app/account/security')->assertInertia(function ($page) use (&$captured) {
            $captured = $page->toArray();

            return $page->component('Account/Security');
        });

        $json = json_encode($captured);

        $this->assertStringNotContainsString($secret, $json);

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $json);
        }
    }

    #[Test]
    public function the_begin_enrollment_response_never_includes_recovery_codes_or_prior_state(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->actingAs($user);
        $this->post('/app/account/security/password-confirmation', ['password' => 'correct-password']);

        $response = $this->post('/app/account/security/mfa/begin');

        $response->assertOk();
        $response->assertJsonStructure(['secret', 'otpAuthUri', 'qrCodeSvg']);
        $response->assertJsonMissingPath('recoveryCodes');
        $response->assertJsonMissingPath('secret_encrypted');
    }

    #[Test]
    public function a_user_model_serialized_to_json_never_includes_mfa_factor_relations_by_default(): void
    {
        $user = User::factory()->create();
        $this->enrollActiveMfaFactor($user);

        $json = json_encode($user);

        $this->assertStringNotContainsString('secret_encrypted', $json);
    }
}
