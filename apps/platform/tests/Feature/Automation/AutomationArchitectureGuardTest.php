<?php

namespace Tests\Feature\Automation;

use App\Domain\Automation\Application\Catalog\AcademicYearSetupReviewRule;
use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 0L.6 -- ADR 0043's boundary checked on the source: Automation
 * reaches other modules only through their contracts, writes only its own
 * tables, never sends anything, uses no service identity, has no
 * cross-School path, and runs only catalogued tier 0 rule types; no other
 * domain depends on it.
 */
class AutomationArchitectureGuardTest extends TestCase
{
    private const DIR = 'app/Domain/Automation';

    /** The only App\ classes Automation may import outside its own namespace. */
    private const ALLOWED_FOREIGN_IMPORTS = [
        'App\\Jobs\\RunAutomationExecutionJob',
        'App\\Models\\DomainEventOutbox',
        'App\\Models\\School',
        'App\\Models\\User',
        'App\\Support\\Audit\\AuditRecorder',
        'App\\Support\\Authorization\\AuthorizesCapability',
        'App\\Support\\Authorization\\CapabilityResolver',
        'App\\Support\\Events\\EventConsumer',
        'App\\Support\\FeatureFlags\\FeatureFlagResolver',
        'App\\Support\\Identifiers\\GeneratesUuidV7',
        // Phase 0O.5A (ADR 0051 §11): bounded execution-outcome counters.
        'App\\Support\\Observability\\MetricsRecorder',
        'App\\Support\\Observability\\QueueName',
        'App\\Support\\Tenancy\\BelongsToSchool',
        // Phase 0N.9 (ADR 0047 section 8): the execution-time School
        // lifecycle check -- tenancy infrastructure, not another module.
        'App\\Support\\Tenancy\\SchoolOperationalGuard',
        'App\\Support\\Tenancy\\TenantContext',
    ];

    /** @return array<string, string> */
    private function sources(): array
    {
        $sources = [];
        foreach (Finder::create()->files()->name('*.php')->in(base_path(self::DIR)) as $file) {
            $sources[$file->getRelativePathname()] = $file->getContents();
        }

        return $sources;
    }

    #[Test]
    public function automation_imports_only_its_own_code_and_approved_platform_contracts(): void
    {
        foreach ($this->sources() as $path => $source) {
            preg_match_all('/^use (App\\\\[^;\s]+)/m', $source, $matches);
            foreach ($matches[1] as $import) {
                if (str_starts_with($import, 'App\\Domain\\Automation\\')) {
                    continue;
                }
                $this->assertContains($import, self::ALLOWED_FOREIGN_IMPORTS, "{$path} imports {$import}");
            }
        }
    }

    #[Test]
    public function automation_never_sends_touches_foreign_tables_or_uses_a_service_identity(): void
    {
        foreach ($this->sources() as $path => $source) {
            foreach ([
                'NotificationDispatcher', 'Communications\\', 'Mail::', 'Notification::', 'Http::',
                'ServiceIdentity', 'DB::table(', 'DB::statement(', 'DB::insert(', 'DB::update(', 'DB::delete(',
                'BYPASSRLS', 'Schema::', 'AiGatewayClient', 'AnalyticsReadGate',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$path} contains {$needle}");
            }
        }
    }

    #[Test]
    public function no_other_domain_depends_on_automation(): void
    {
        $allowed = [
            base_path(self::DIR),
            base_path('app/Http/Controllers/App/Automation'),
            base_path('app/Jobs/RunAutomationExecutionJob.php'),
            base_path('app/Console/Commands/RedispatchDueAutomationExecutions.php'),
            base_path('app/Providers/PlatformServiceProvider.php'),
        ];

        foreach (Finder::create()->files()->name('*.php')->in(base_path('app')) as $file) {
            if (collect($allowed)->contains(fn (string $p) => str_starts_with($file->getPathname(), $p))) {
                continue;
            }
            $this->assertStringNotContainsString('App\\Domain\\Automation', $file->getContents(), $file->getRelativePathname().' depends on Automation');
        }
    }

    #[Test]
    public function only_the_registered_tier_0_rule_type_exists(): void
    {
        $this->assertSame([AcademicYearSetupReviewRule::class], AutomationRuleCatalog::RULE_TYPES);

        foreach ((new AutomationRuleCatalog)->all() as $type) {
            $this->assertSame(0, $type->tier(), $type->key().' must be tier 0 in v1');
            $this->assertContains('automation.manage', $type->requiredCapabilities());
        }

        $implementations = [];
        foreach ($this->sources() as $path => $source) {
            if (preg_match('/class (\w+)\s+implements\s+AutomationRuleType/', $source, $m)) {
                $implementations[] = $m[1];
            }
        }
        $this->assertSame(['AcademicYearSetupReviewRule'], $implementations, 'No rule type outside the catalog.');
    }

    #[Test]
    public function every_query_is_school_scoped_and_there_is_no_cross_school_path(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertStringNotContainsString('withoutGlobalScope', $source, $path);
            $this->assertStringNotContainsString('platform.', $source, "{$path} references a platform capability");
        }
    }
}
