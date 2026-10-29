<?php

namespace Tests\Feature\Organization;

use App\Http\Controllers\App\IntegrationHubController;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Integrations\IntegrationDefinition;
use App\Support\Integrations\IntegrationHubReadService;
use App\Support\Integrations\IntegrationReadiness;
use App\Support\Integrations\IntegrationReadinessProviders;
use App\Support\Integrations\IntegrationSection;
use App\Support\Integrations\IntegrationStatus;
use App\Support\Integrations\OrganizationIntegrationCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * ORG.12G (ADR 0080 §12) structural guards: the Hub observes integrations;
 * it never owns, stores, contacts or reveals them.
 */
class IntegrationHubArchitectureTest extends TestCase
{
    private function code(string $path): string
    {
        return implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all((string) file_get_contents($path)),
        ));
    }

    /** @return array<string, string> class => source (comments stripped) of every provider, the Hub service and controller */
    private function hubSources(): array
    {
        $classes = [IntegrationHubReadService::class, IntegrationHubController::class, OrganizationIntegrationCatalogue::class];
        $providers = app(IntegrationReadinessProviders::class);
        foreach ($providers->keys() as $key) {
            $classes[] = $providers->for($key)::class;
        }
        $sources = [];
        foreach (array_unique($classes) as $class) {
            $sources[$class] = $this->code((string) (new ReflectionClass($class))->getFileName());
        }

        return $sources;
    }

    #[Test]
    public function the_catalogue_is_code_owned_with_one_provider_per_available_family_and_none_for_unavailable_ones(): void
    {
        $catalogue = app(OrganizationIntegrationCatalogue::class);
        $keys = array_map(fn (IntegrationDefinition $d): string => $d->key, $catalogue->all());
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertEqualsCanonicalizing($catalogue->availableKeys(), app(IntegrationReadinessProviders::class)->keys());

        // The frozen ORG.12G catalogue: changing it is a reviewed change to this list (ADR 0080 §12.2).
        $this->assertSame([
            'communications.email', 'communications.sms', 'payments.gateway', 'attendance.biometric', 'attendance.rfid',
            'transport.rfid', 'transport.vts', 'integrations.webhooks', 'integrations.api_clients', 'platform.ai_gateway',
        ], $keys);
        $this->assertSame(['communications.email', 'integrations.webhooks', 'integrations.api_clients'], $catalogue->availableKeys());

        $capabilities = DB::table('capabilities')->pluck('key')->all();
        foreach ($catalogue->all() as $definition) {
            foreach ([...$definition->viewCapabilities, ...$definition->openCapabilities, ...$definition->manageCapabilities] as $capability) {
                $this->assertContains($capability, $capabilities, "{$definition->key} names unknown capability {$capability}");
                $this->assertFalse(CampusScopeCatalogue::isAdopted($capability), "{$definition->key} must not check a Campus-adopted key School-wide");
            }
            if ($definition->route !== null) {
                $this->assertTrue(Route::has($definition->route), "{$definition->key} links to a real route");
            }
        }
    }

    #[Test]
    public function definitions_refuse_inconsistent_shapes(): void
    {
        $row = ['key' => 'payments.gateway', 'title' => 'T', 'description' => 'D', 'section' => IntegrationSection::PAYMENTS, 'owner' => 'O', 'managedBy' => 'platform', 'order' => 1];
        foreach ([
            'unavailable without a gate' => [...$row, 'available' => false],
            'available with a gate' => [...$row, 'available' => true, 'gate' => 'G'],
            'unavailable with a page' => [...$row, 'available' => false, 'gate' => 'G', 'route' => 'app.dashboard', 'open' => ['school.settings.view']],
            'unavailable with a capability' => [...$row, 'available' => false, 'gate' => 'G', 'view' => ['school.settings.view']],
            'page without its capabilities' => [...$row, 'available' => true, 'route' => 'app.dashboard'],
            'tenant-shaped key' => [...$row, 'key' => 'School Gateway', 'available' => true],
            'unknown manager' => [...$row, 'available' => true, 'managedBy' => 'vendor'],
        ] as $case => $bad) {
            try {
                IntegrationDefinition::fromArray($bad);
                $this->fail("{$case} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $definition = IntegrationDefinition::fromArray([...$row, 'available' => true]);
        $this->expectException(\LogicException::class);
        new OrganizationIntegrationCatalogue([$definition, $definition]);
    }

    #[Test]
    public function a_provider_never_decides_entitlement_or_error_and_explains_briefly(): void
    {
        foreach ([IntegrationStatus::NOT_APPLICABLE, IntegrationStatus::ERROR] as $status) {
            try {
                new IntegrationReadiness($status, 'x');
                $this->fail("{$status->value} is decided by the Hub");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new IntegrationReadiness(IntegrationStatus::HEALTHY, str_repeat('x', IntegrationReadiness::MAX_EXPLANATION_LENGTH + 1));
    }

    #[Test]
    public function providers_and_the_hub_never_read_a_secret_or_identifying_column(): void
    {
        $forbidden = ['secret_hash', 'secret_encrypted', 'previous_secret', 'key_id', "'url'", '.url', 'recipient', 'subject', 'sealed_content', 'password', 'provider_message_id', "'name'", '.name', 'scopes', 'username', 'api_key', 'private_key'];
        foreach ($this->hubSources() as $class => $source) {
            foreach ($forbidden as $needle) {
                if ($class === IntegrationHubReadService::class && in_array($needle, ["'name'", '.name'], true)) {
                    continue; // the School's own display name, from the School model
                }
                $this->assertStringNotContainsString($needle, $source, "{$class} must not read {$needle}");
            }
        }
    }

    #[Test]
    public function providers_and_the_hub_never_contact_anything_outside_the_database_or_write(): void
    {
        $outbound = ['Http::', 'Illuminate\Http\Client', 'GuzzleHttp', 'curl_', 'fsockopen', 'stream_socket', 'dns_get_record', 'gethostbyname', 'checkdnsrr', "file_get_contents('http", 'Mail::', '->submit(', '->send(', 'DeliverWebhookJob', 'SsrfSafeUrlValidator', 'AiGatewayClient', 'dispatch('];
        $writes = ['->insert(', '->update(', '->delete(', '->upsert(', '->save(', '->create(', 'forceFill', 'AuditRecorder', 'Cache::', 'cache()', 'TenantCache', 'DB::statement', 'lockForUpdate', 'for update'];
        foreach ($this->hubSources() as $class => $source) {
            foreach ([...$outbound, ...$writes] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$class} must not use {$needle}");
            }
        }
    }

    #[Test]
    public function there_is_no_generic_integration_device_or_credential_table(): void
    {
        $tables = array_column(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and (table_name like '%integration%' or table_name like '%device%' or table_name like '%rfid%' or table_name like '%biometric%' or table_name like '%gps%' or table_name like '%telemetry%' or table_name like '%sms%' or table_name like '%provider_credential%' or table_name like '%vehicle_location%')"), 'table_name');
        $this->assertSame([], $tables, 'ORG.12G stores nothing: each owning domain keeps its own state');
    }

    #[Test]
    public function the_page_is_read_only_and_renders_no_markup_or_connection_test(): void
    {
        $page = (string) file_get_contents(resource_path('js/Pages/App/SchoolSetup/Integrations.vue'));
        foreach (['v-html', '<form', '<input', 'router.post', 'router.put', 'useForm', 'fetch(', 'axios', 'Test connection', 'Connect'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $page, "the Hub page must not contain {$forbidden}");
        }
        $route = Route::getRoutes()->getByName('app.school-setup.integrations');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('capability:'.IntegrationHubReadService::FLOOR_CAPABILITY, $route->gatherMiddleware());
        $this->assertFalse(CampusScopeCatalogue::isAdopted(IntegrationHubReadService::FLOOR_CAPABILITY));
        $this->assertSame([], array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'app/school-setup/integrations') && $r->methods() !== ['GET', 'HEAD']), 'no Hub write route');
    }
}
