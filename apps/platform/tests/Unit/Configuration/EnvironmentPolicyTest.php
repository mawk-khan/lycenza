<?php

namespace Tests\Unit\Configuration;

use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Configuration\UnsupportedEnvironmentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PPR.1 (ADR 0074 section 26, OD-PPR-1 option B): the authoritative
 * environment classification. Pins the supported set, which environments
 * are hardened and which are development, and the closed staging
 * difference list -- changing any of these is a reviewed ADR amendment.
 */
class EnvironmentPolicyTest extends TestCase
{
    #[Test]
    public function exactly_four_environments_are_supported(): void
    {
        $this->assertSame(['production', 'staging', 'local', 'testing'], EnvironmentPolicy::SUPPORTED);

        foreach (EnvironmentPolicy::SUPPORTED as $environment) {
            EnvironmentPolicy::assertSupported($environment);
            $this->assertTrue(EnvironmentPolicy::isSupported($environment));
        }

        foreach (['stage', 'preprod', 'prod2', 'prod', 'Production', 'STAGING', 'dev', 'development', 'build', 'qa', 'uat', '', ' production'] as $unsupported) {
            $this->assertFalse(EnvironmentPolicy::isSupported($unsupported), $unsupported);
            try {
                EnvironmentPolicy::assertSupported($unsupported);
                $this->fail("'{$unsupported}' must be refused");
            } catch (UnsupportedEnvironmentException $e) {
                $this->assertStringContainsString('production, staging, local, testing', $e->getMessage());
                // The value is never echoed (checked where it cannot coincide with the supported list itself).
                if ($unsupported !== '' && ! str_contains('Supported values: production, staging, local, testing.', $unsupported)) {
                    $this->assertStringNotContainsString($unsupported, $e->getMessage(), 'the value is never echoed');
                }
            }
        }
    }

    #[Test]
    public function staging_is_hardened_like_production_and_never_development(): void
    {
        $this->assertSame(['production', 'staging'], EnvironmentPolicy::HARDENED);
        $this->assertSame(['local', 'testing'], EnvironmentPolicy::DEVELOPMENT);
        $this->assertSame([], array_intersect(EnvironmentPolicy::HARDENED, EnvironmentPolicy::DEVELOPMENT), 'no environment is both');
        $this->assertSame(EnvironmentPolicy::SUPPORTED, [...EnvironmentPolicy::HARDENED, ...EnvironmentPolicy::DEVELOPMENT], 'every supported environment is classified');

        $this->assertTrue(EnvironmentPolicy::isHardened('staging'));
        $this->assertTrue(EnvironmentPolicy::isHardened('production'));
        $this->assertFalse(EnvironmentPolicy::isHardened('local'));
        $this->assertFalse(EnvironmentPolicy::isHardened('testing'));

        $this->assertFalse(EnvironmentPolicy::isDevelopment('staging'));
        $this->assertTrue(EnvironmentPolicy::isDevelopment('local'));

        $this->assertTrue(EnvironmentPolicy::isProduction('production'));
        $this->assertFalse(EnvironmentPolicy::isProduction('staging'), 'staging is never the real production');
        $this->assertTrue(EnvironmentPolicy::isStaging('staging'));
        $this->assertFalse(EnvironmentPolicy::isStaging('production'));
    }

    #[Test]
    public function the_staging_differences_are_a_closed_reviewed_list(): void
    {
        $this->assertSame(
            ['mail_staging_capture', 'mail_sending_verified_not_required_for_capture'],
            array_keys(EnvironmentPolicy::STAGING_DIFFERENCES),
            'a new staging difference is an ADR 0074 amendment, never a convenience',
        );
    }
}
