<?php

namespace Tests\Feature\Organization;

use App\Domain\Platform\Application\Groups\NetworkReportReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Http\Controllers\App\Groups\NetworkReportController;
use App\Http\Controllers\App\OrganizationReportController;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Reports\OrganizationReportCatalogue;
use App\Support\Reports\SchoolControlPlaneReport;
use App\Support\Setup\OrganizationSetupRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.12H (ADR 0080 §13) structural guards: Organization reports are fixed,
 * read-only projections; Network reports never touch tenant data; School
 * reports reuse the existing owners; no export, snapshot or report engine.
 */
class OrganizationReportArchitectureTest extends TestCase
{
    private function code(string $class): string
    {
        return implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all((string) file_get_contents((string) (new ReflectionClass($class))->getFileName())),
        ));
    }

    #[Test]
    public function the_report_catalogue_is_code_owned_with_explicit_authority(): void
    {
        $capabilities = DB::table('capabilities')->pluck('key')->all();
        foreach (OrganizationReportCatalogue::REPORTS as $key => $report) {
            $this->assertMatchesRegularExpression('/^(organization|network)\.[a-z_]+$/', $key);
            $this->assertContains($report['audience'], [OrganizationReportCatalogue::SCHOOL, OrganizationReportCatalogue::NETWORK]);
            $this->assertNotEmpty($report['authority'], "{$key} has explicit authority");
            $this->assertSame(str_starts_with($key, 'network.'), $report['audience'] === OrganizationReportCatalogue::NETWORK);
            foreach ($report['authority'] as $authority) {
                if ($report['audience'] === OrganizationReportCatalogue::NETWORK) {
                    $this->assertSame(OrganizationReportCatalogue::NETWORK_SCOPE, $authority, 'Network reports are scoped by GroupGovernanceAccess only');
                } else {
                    $this->assertContains($authority, $capabilities);
                    $this->assertFalse(CampusScopeCatalogue::isAdopted($authority), "{$key} must not check a Campus-adopted key School-wide");
                }
            }
            $this->assertNotEmpty($report['reference']);
        }
    }

    #[Test]
    public function network_reports_never_reference_tenant_data_or_school_context(): void
    {
        $forbidden = ['students', 'guardians', 'employees', 'employments', 'payroll', 'charges', 'payments', 'attendance', 'campuses', 'admission', 'library_', 'inventory_', 'transport_', 'communication', 'school_memberships', 'membership_role_assignments', 'email_messages', 'webhook_', 'api_client', 'TenantContext', 'withSchool', 'SchoolScope', 'GroupSafeReportGate', 'Cache::', 'cache(', 'route(\'app.school', '/elevation', '->insert(', '->update(', '->delete('];
        foreach ([NetworkReportReadService::class, SchoolGroupDirectoryReadService::class, NetworkReportController::class] as $class) {
            $source = $this->code($class);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$class} must not use {$needle}");
            }
        }
        $controller = $this->code(NetworkReportController::class);
        $this->assertStringContainsString('->viewScope(', $controller, 'the Network report is scoped by GroupGovernanceAccess');
        $this->assertStringContainsString('abort_if($scope === null, 404)', $controller);
    }

    #[Test]
    public function the_school_report_reuses_the_existing_owners_and_holds_no_second_catalogue(): void
    {
        $source = $this->code(SchoolControlPlaneReport::class);
        foreach (['OrganizationSetupCenter', 'IntegrationHubReadService', 'BrandResolver', 'NavigationPresentation', 'SensitiveDataCatalogue'] as $owner) {
            $this->assertStringContainsString($owner, $source, "the report reads {$owner}");
        }
        foreach (array_column(OrganizationSetupRegistry::DEFINITIONS, 'key') as $setupKey) {
            $this->assertStringNotContainsString("'{$setupKey}'", $source, 'no setup area is hard-coded in the report');
        }
        foreach (['ModuleCatalogue::MODULES', 'SetupReadinessProviders', 'IntegrationReadinessProviders', '->insert(', '->update(', 'Cache::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('requireSchool()', $this->code(OrganizationReportController::class), 'the School report runs in the selected School context');
    }

    #[Test]
    public function report_routes_are_get_only_and_no_export_or_report_engine_exists(): void
    {
        foreach (['app.school-setup.organization-report' => 'capability:school.profile.view', 'app.groups.reports.network' => null] as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            if ($middleware !== null) {
                $this->assertContains($middleware, $route->gatherMiddleware());
            }
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (str_contains($uri, 'organization-report') || str_contains($uri, 'reports/network')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$uri} is read-only");
                $this->assertDoesNotMatchRegularExpression('/export|download|csv|pdf|xlsx/i', $uri);
            }
        }
        $classes = array_map(fn ($f) => $f->getFilename(), iterator_to_array((new Finder)->files()->in(app_path('Support/Reports'))->name('*.php'), false));
        $this->assertEqualsCanonicalizing(['OrganizationReportCatalogue.php', 'SchoolControlPlaneReport.php'], $classes, 'no report builder, query language or exporter');
        $this->assertSame([], array_column(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and table_name ~ '(report_snapshot|report_definition|^mis_|_mis$)'"), 'table_name'), 'no persisted report snapshots or definitions');
    }

    #[Test]
    public function the_report_pages_render_no_markup_and_offer_no_download(): void
    {
        foreach (['Pages/App/SchoolSetup/OrganizationReport.vue', 'Pages/App/Groups/NetworkReport.vue'] as $file) {
            $page = (string) file_get_contents(resource_path("js/{$file}"));
            foreach (['v-html', 'download', 'Export', '.csv', 'useForm', 'router.post', 'router.put', 'router.delete'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $page, "{$file} must not contain {$forbidden}");
            }
        }
    }
}
