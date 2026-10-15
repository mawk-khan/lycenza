<?php

namespace Tests\Feature\Corrections;

use App\Models\User;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use App\Support\ProductIdentity;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * X-03 (OD-V11-7, ADR 0075 section 12): a new authenticator enrollment is
 * labelled with the product name (ProductIdentity), not APP_NAME's legacy
 * "School OS". The label is the ONLY change: secret, algorithm, digits and
 * period are untouched, so existing factors keep working.
 */
class MfaAuthenticatorBrandingTest extends TestCase
{
    use CreatesMfaFixtures;

    #[Test]
    public function a_new_enrollment_uses_the_product_name_and_unchanged_totp_parameters(): void
    {
        config(['app.name' => 'School OS']); // the legacy APP_NAME must no longer leak
        $user = User::factory()->create(['email' => 'teacher@example.test']);

        $result = app(MfaEnrollmentService::class)->begin($user);
        $uri = $result['otpAuthUri'];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $label = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        $this->assertSame('Lycenza', ProductIdentity::NAME);
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertSame('/Lycenza:teacher@example.test', $label);
        $this->assertSame('Lycenza', $query['issuer']);
        $this->assertStringNotContainsString('School OS', rawurldecode($uri));
        $this->assertStringNotContainsString('School%20OS', $uri);
        $this->assertSame($result['secret'], $query['secret']);
        $this->assertSame(['SHA1', '6', '30'], [$query['algorithm'], $query['digits'], $query['period']]);
    }

    #[Test]
    public function an_enrollment_still_confirms_with_a_code_from_its_secret(): void
    {
        $user = User::factory()->create();
        $service = app(MfaEnrollmentService::class);

        $secret = $service->begin($user)['secret'];
        $codes = $service->confirm($user, $this->currentTotpCodeFor($secret));

        $this->assertNotEmpty($codes);
        $this->assertSame('active', $user->mfaFactors()->latest('created_at')->first()->status);
    }

    #[Test]
    public function an_existing_factor_keeps_verifying_after_the_label_change(): void
    {
        $user = User::factory()->create();
        $factor = $this->enrollActiveMfaFactor($user);

        $this->assertSame('active', $factor->status);
        $this->assertTrue(app(Google2FA::class)->verifyKey(
            (string) $factor->secret_encrypted,
            $this->currentTotpCodeFor((string) $factor->secret_encrypted),
        ));
    }
}
