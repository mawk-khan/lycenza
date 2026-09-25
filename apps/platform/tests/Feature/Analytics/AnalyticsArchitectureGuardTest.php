<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Application\AnalyticsReadModel;
use App\Domain\Analytics\Application\AnalyticsReadModelRegistry;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- structural guards for the Analytics module (ADR
 * 0040), so the fail-closed rules cannot be bypassed quietly:
 *
 * - every AnalyticsReadModel is registered, and NO registered read
 *   model counts people while the person-cohort policy is undecided;
 * - read models run only through AnalyticsReadGate;
 * - Analytics reads source modules only through their Application
 *   read contracts -- never their Infrastructure models or raw tables;
 * - no cache, snapshot, outbox consumer, export or cross-School path.
 */
class AnalyticsArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function analyticsSources(): array
    {
        $files = [];
        foreach ([app_path('Domain/Analytics'), app_path('Http/Controllers/App/Analytics')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<class-string<AnalyticsReadModel>> */
    private function readModelClasses(): array
    {
        $classes = [];
        foreach ($this->analyticsSources() as $file) {
            if (preg_match('/namespace\s+([^;]+);.*?\bclass\s+(\w+)/s', $this->code($file), $m) === 1) {
                $class = $m[1].'\\'.$m[2];
                if (class_exists($class) && is_subclass_of($class, AnalyticsReadModel::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    #[Test]
    public function every_read_model_is_registered_and_the_registry_lists_only_real_read_models(): void
    {
        $found = $this->readModelClasses();
        $this->assertNotEmpty($found);
        $this->assertEqualsCanonicalizing($found, AnalyticsReadModelRegistry::READ_MODELS,
            'Every AnalyticsReadModel must be listed in AnalyticsReadModelRegistry::READ_MODELS, and nothing else may be.');
    }

    #[Test]
    public function no_registered_read_model_counts_people_while_the_cohort_policy_is_undecided(): void
    {
        $this->assertNull(config('analytics.minimum_person_cohort_size'));

        foreach (AnalyticsReadModelRegistry::READ_MODELS as $class) {
            $declaration = app($class)->declaration();
            $this->assertFalse($declaration->countsPeople,
                "{$class} counts people. No minimum person-cohort size or suppression mode has been approved "
                .'(docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md); person-counting Analytics must not be registered.');
        }
    }

    #[Test]
    public function read_models_are_executed_only_through_the_gate(): void
    {
        $gate = app_path('Domain/Analytics/Application/AnalyticsReadGate.php');

        foreach ($this->analyticsSources() as $file) {
            if ($file === $gate) {
                continue;
            }
            $this->assertStringNotContainsString('->compute(', $this->code($file),
                basename($file).' must call AnalyticsReadGate::read(), never a read model\'s compute() directly.');
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_starts_with($file->getPathname(), app_path('Domain/Analytics'))) {
                $code = $this->code($file->getPathname());
                if (str_contains($code, 'CurriculumCoverageReadModel')) {
                    $this->assertStringNotContainsString('->compute(', $code, $file->getFilename());
                }
            }
        }
    }

    #[Test]
    public function analytics_reads_source_modules_only_through_their_application_contracts(): void
    {
        foreach ($this->analyticsSources() as $file) {
            $code = $this->code($file);

            $this->assertDoesNotMatchRegularExpression('/App\\\\Domain\\\\(?!Analytics\\\\)\w+\\\\Infrastructure/', $code,
                basename($file).' must not use another module\'s Infrastructure (Eloquent) classes -- ADR 0040 §3.');
            $this->assertDoesNotMatchRegularExpression('/App\\\\Models\\\\(?!School\b|User\b)/', $code,
                basename($file).' must not read platform models other than the School/User it is handed.');

            foreach (['DB::', 'Illuminate\\Support\\Facades\\DB', '->table(', 'selectRaw', 'Schema::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file).' must not query tables directly.');
            }
        }
    }

    #[Test]
    public function analytics_has_no_cache_snapshot_outbox_or_cross_school_path(): void
    {
        foreach ($this->analyticsSources() as $file) {
            $code = $this->code($file);

            foreach ([
                'Cache::', 'TenantCache', 'cache(', 'remember(',
                'EventConsumer', 'domain_event_outbox', 'DomainEvent', 'dispatch(',
                'withoutGlobalScope', 'BYPASSRLS', 'pgsql_admin', 'analytics.platform.view', 'analytics.export',
                'Snapshot', 'snapshot',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file).' must not contain '.$forbidden.' (ADR 0040 §4, §7-§10; no export in 0L.2-1).');
            }
        }
    }

    #[Test]
    public function no_layer_0_to_4_code_depends_on_analytics(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || $file->getExtension() !== 'php'
                || str_starts_with($path, app_path('Domain/Analytics'))
                || str_starts_with($path, app_path('Http/Controllers/App/Analytics'))
                // Layer 6 (Multi-School Management) above Analytics: the Group
                // report calls Analytics' Group-safe gate (ADR 0048 section 7).
                || str_starts_with($path, app_path('Domain/Platform/Application/Groups/Reporting'))) {
                continue;
            }

            $this->assertStringNotContainsString('App\\Domain\\Analytics', $this->code($path),
                $file->getFilename().' must not depend on Analytics -- Layers 0-4 work with Analytics absent (ADR 0040 §1).');
        }
    }

    #[Test]
    public function the_analytics_route_surface_is_exactly_one_read_only_page(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'analytics') && ! str_contains($r->uri(), 'communications'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->sort()->values()->all();

        $this->assertSame(['GET /app/analytics/curriculum-coverage'], $routes,
            'One read-only web page; no export, no API and no platform route in Phase 0L.2-1.');
    }
}
