<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Models\FeatureFlag;
use App\Models\FeatureFlagSchoolOverride;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §42 -- default-off School feature flag, using the
 * existing FeatureFlagResolver infrastructure (never a substitute for
 * capability/mfa authorization -- see the resolver's own docblock).
 */
class ProcessingAuthorizationFeatureFlagTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    #[Test]
    public function the_flag_exists_in_the_catalog_default_off(): void
    {
        $flag = FeatureFlag::query()->find('students.processing_authorizations');

        $this->assertNotNull($flag);
        $this->assertFalse($flag->default_enabled);
    }

    #[Test]
    public function a_school_with_no_override_is_disabled(): void
    {
        $school = $this->createSchool();
        $resolver = app(FeatureFlagResolver::class);

        $this->assertFalse($resolver->isEnabledForSchool('students.processing_authorizations', $school));
    }

    #[Test]
    public function a_school_with_an_enabled_override_is_enabled(): void
    {
        // A separate School from the "no override" test above --
        // FeatureFlagResolver caches per (School, flag) for 60s
        // (TenantCache), so reusing the same School across both
        // assertions in one test would read a stale cached value.
        $school = $this->createSchool();
        $resolver = app(FeatureFlagResolver::class);

        app(TenantContext::class)->withSchool($school, fn () => FeatureFlagSchoolOverride::query()->create([
            'school_id' => $school->id,
            'feature_flag_key' => 'students.processing_authorizations',
            'enabled' => true,
        ]));

        $this->assertTrue($resolver->isEnabledForSchool('students.processing_authorizations', $school));
    }
}
