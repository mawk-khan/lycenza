<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Models\FeatureFlag;
use App\Support\FeatureFlags\FeatureFlagResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §42 seeded a default-off `students.processing_authorizations`
 * flag that no code ever read (ADR 0038 drift note). ORG.5 (ADR 0076 §13,
 * §31) retired it: the registry stays gated by its capabilities plus MFA,
 * and its availability is the core `student_records` module.
 */
class ProcessingAuthorizationFeatureFlagTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    #[Test]
    public function the_dead_flag_is_retired_and_its_capabilities_remain(): void
    {
        $this->assertNull(FeatureFlag::query()->find('students.processing_authorizations'));
        $this->assertFalse(app(FeatureFlagResolver::class)->isEnabledForSchool('students.processing_authorizations', $this->createSchool()));

        $this->assertSame(
            ['students.processing_authorizations.manage', 'students.processing_authorizations.view'],
            DB::table('capabilities')->where('key', 'like', 'students.processing_authorizations.%')->orderBy('key')->pluck('key')->all(),
        );
    }

    #[Test]
    public function no_application_code_reads_the_retired_flag(): void
    {
        foreach (Finder::create()->files()->in([app_path(), resource_path('js')]) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/isEnabledForSchool\\(\\s*'students\\.processing_authorizations'/",
                $file->getContents(),
                $file->getRelativePathname().' reads the retired flag',
            );
        }
    }
}
