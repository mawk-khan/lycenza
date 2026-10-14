<?php

namespace Tests\Feature\Configuration;

use App\Domain\Examinations\Application\Marks\StudentMarkAvailability;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAvailability;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Portal\PortalAvailability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PPR.1 (ADR 0074 section 26.5): finding F-2 happened because each security
 * control tested the literal `production`, so `staging` silently ran
 * without them. This guard keeps the classification in ONE place:
 *
 *  1. no production code tests `production`/`staging` literally --
 *     hardening asks EnvironmentPolicy::isHardened();
 *  2. every remaining framework environment check names development values
 *     only (local/testing), so nothing can quietly widen a development
 *     convenience to a hardened environment;
 *  3. the real-production/staging distinction (isProduction()/isStaging())
 *     appears only at the reviewed STAGING_DIFFERENCES sites.
 */
class EnvironmentArchitectureGuardTest extends TestCase
{
    /** The only files that may decide a listed staging difference. */
    private const STAGING_DIFFERENCE_SITES = [
        'app/Support/Configuration/ProductionConfigurationGuard.php',
        'app/Support/Email/Providers/EmailProviderResolver.php',
    ];

    /** @return array<string, string> relative path => source */
    private function sources(): array
    {
        $files = [];
        foreach (['app', 'config', 'routes', 'bootstrap'] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() !== 'php' || str_contains($path, '/bootstrap/cache/')) {
                    continue;
                }
                $files[substr($path, strlen(base_path()) + 1)] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    #[Test]
    public function no_code_tests_production_or_staging_literally(): void
    {
        $patterns = [
            '/->isProduction\(\)/',
            '/environment\(\s*[\'"](production|staging)[\'"]/',
            '/[\'"](production|staging)[\'"]\s*===|===\s*[\'"](production|staging)[\'"]/',
            '/env\(\s*[\'"]APP_ENV[\'"]\s*\)\s*===/',
        ];
        $hits = [];
        foreach ($this->sources() as $path => $source) {
            if ($path === 'app/Support/Configuration/EnvironmentPolicy.php') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source, $m) === 1) {
                    $hits[] = "{$path}: {$m[0]}";
                }
            }
        }

        $this->assertSame([], $hits, 'decide hardening through EnvironmentPolicy::isHardened(), never a literal environment name');
    }

    #[Test]
    public function every_framework_environment_check_names_development_values_only(): void
    {
        $hits = [];
        foreach ($this->sources() as $path => $source) {
            if (preg_match_all('/environment\(([^)]*)\)/', $source, $calls) === 0) {
                continue;
            }
            foreach ($calls[1] as $arguments) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arguments, $names);
                foreach ($names[1] as $name) {
                    if (! in_array($name, EnvironmentPolicy::DEVELOPMENT, true)) {
                        $hits[] = "{$path}: environment({$arguments})";
                    }
                }
            }
        }

        $this->assertSame([], $hits);

        // The environment lists passed by constant are development-only too.
        foreach ([PortalAvailability::ENVIRONMENTS, StudentMarkAvailability::ENVIRONMENTS, TeacherStudentMarkAvailability::ENVIRONMENTS, PartnerScopeRegistry::PROBE_ENVIRONMENTS] as $list) {
            $this->assertSame([], array_diff($list, EnvironmentPolicy::DEVELOPMENT), json_encode($list));
        }
    }

    #[Test]
    public function the_production_staging_distinction_appears_only_at_the_listed_difference_sites(): void
    {
        $sites = [];
        foreach ($this->sources() as $path => $source) {
            if ($path !== 'app/Support/Configuration/EnvironmentPolicy.php' && preg_match('/EnvironmentPolicy::(isProduction|isStaging)\(/', $source) === 1) {
                $sites[] = $path;
            }
        }
        sort($sites);

        $this->assertSame(self::STAGING_DIFFERENCE_SITES, $sites, 'a new production/staging distinction is an ADR 0074 STAGING_DIFFERENCES amendment');
    }

    #[Test]
    public function the_security_controls_found_in_f2_now_ask_the_policy(): void
    {
        foreach ([
            'app/Providers/AppServiceProvider.php',
            'app/Http/Middleware/ApplySecurityHeaders.php',
            'app/Http/Middleware/ClassifyRequestHost.php',
            'app/Support/Operations/DatabaseRoleVerifier.php',
            'app/Support/Email/Providers/SendGridConfiguration.php',
            'app/Support/Email/Events/EmailEventAdapterResolver.php',
            'app/Support/Email/Providers/EmailProviderResolver.php',
            'config/observability.php',
        ] as $path) {
            $this->assertStringContainsString('EnvironmentPolicy::', (string) file_get_contents(base_path($path)), $path);
        }
    }
}
