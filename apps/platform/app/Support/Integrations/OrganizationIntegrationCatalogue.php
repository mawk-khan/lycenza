<?php

namespace App\Support\Integrations;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Support\Integrations\IntegrationSection as Sec;
use App\Support\Setup\OrganizationSetupRegistry;

/**
 * ORG.12G (ADR 0080 §12): the ONE code-owned catalogue of integration and
 * device families the Integration & Device Readiness Hub reports on.
 *
 * - Only real or explicitly represented families. An AVAILABLE family is
 *   implemented in this repository and has one read-only provider in its
 *   owning module. An unavailable family is one a recorded decision names
 *   (deferred by ADR 0057, documented module non-scope, legal gate) and is
 *   shown as NOT_AVAILABLE with that decision -- never evaluated, never a
 *   device or provider record, never configurable. A family nobody has
 *   decided about is not listed.
 * - Never a table, a setting, request input or runtime registration; no
 *   tenant-defined integration types.
 * - The Organization stores nothing here: no credential, endpoint, device
 *   or provider row. Each owning domain keeps its own state.
 */
final class OrganizationIntegrationCatalogue
{
    public const array DEFINITIONS = [
        ['key' => 'communications.email', 'title' => 'Email delivery', 'description' => 'Invitations, account notices and School communications sent by email. Lycenza operates the email provider; the School has nothing to connect.', 'section' => Sec::COMMUNICATIONS, 'owner' => 'Lycenza platform', 'available' => true, 'managedBy' => 'platform', 'order' => 10, 'reference' => []],
        ['key' => 'communications.sms', 'title' => 'SMS', 'description' => 'Text messages to Guardians and staff.', 'section' => Sec::COMMUNICATIONS, 'owner' => 'Communications', 'available' => false, 'gate' => 'Deferred from this release (ADR 0057 §5). A future SMS provider needs its own contract and a legal and provider review.', 'managedBy' => 'platform', 'order' => 20, 'reference' => ['RF-24']],
        ['key' => 'payments.gateway', 'title' => 'Online payment gateway', 'description' => 'Collecting fees online through a payment processor. Payments the School has already received are recorded manually in Fees & Payments.', 'section' => Sec::PAYMENTS, 'owner' => 'Fees & Payments', 'available' => false, 'gate' => 'Deferred from this release (ADR 0057 §2). A gateway needs its own contract covering the provider, merchant scope, jurisdiction and PCI review.', 'managedBy' => 'platform', 'order' => 30, 'reference' => ['RF-25']],
        ['key' => 'attendance.biometric', 'title' => 'Biometric attendance devices', 'description' => 'Fingerprint or face devices that record staff or student attendance.', 'section' => Sec::ATTENDANCE_DEVICES, 'owner' => 'Attendance', 'available' => false, 'gate' => 'Not part of this release: biometric data stays behind legal gates (HRX-L1/L2). Device ingestion would need its own domain programme and privacy review.', 'managedBy' => 'school', 'order' => 40, 'reference' => ['RF-26', 'RF-29']],
        ['key' => 'attendance.rfid', 'title' => 'RFID attendance and access', 'description' => 'Card readers that record attendance or entry.', 'section' => Sec::ATTENDANCE_DEVICES, 'owner' => 'Attendance', 'available' => false, 'gate' => 'Not part of this release (documented Attendance non-scope). Any scanning needs a privacy review first.', 'managedBy' => 'school', 'order' => 50, 'reference' => ['RF-27']],
        ['key' => 'transport.rfid', 'title' => 'Transport boarding RFID', 'description' => 'Card readers on vehicles that record Students boarding.', 'section' => Sec::TRANSPORT_TRACKING, 'owner' => 'Transport', 'available' => false, 'gate' => 'Not part of this release (documented Transport non-scope). Any scanning needs a privacy review first.', 'managedBy' => 'school', 'order' => 60, 'reference' => ['RF-27']],
        ['key' => 'transport.vts', 'title' => 'Vehicle tracking (GPS)', 'description' => 'Live vehicle location and route tracking.', 'section' => Sec::TRANSPORT_TRACKING, 'owner' => 'Transport', 'available' => false, 'gate' => 'Not part of this release (documented Transport non-scope). Live location of people needs its own privacy review.', 'managedBy' => 'school', 'order' => 70, 'reference' => ['RF-28']],
        ['key' => 'integrations.webhooks', 'title' => 'Outbound webhooks', 'description' => 'Signed event notifications Lycenza sends to endpoints the School registers. Managed through the School API.', 'section' => Sec::PLATFORM_OTHER, 'owner' => 'Integrations', 'available' => true, 'managedBy' => 'school', 'entitlement' => ['integrations'], 'view' => ['integrations.webhooks.view'], 'manage' => ['integrations.webhooks.manage'], 'setupArea' => 'integrations.integrations', 'order' => 80, 'reference' => []],
        ['key' => 'integrations.api_clients', 'title' => 'Partner API clients', 'description' => 'Credentials a partner system uses to read this School\'s data through the partner API.', 'section' => Sec::PLATFORM_OTHER, 'owner' => 'Integrations', 'available' => true, 'managedBy' => 'school', 'entitlement' => ['integrations'], 'view' => ['integrations.api_clients.view'], 'route' => 'app.integrations.api-clients.index', 'open' => ['integrations.api_clients.view'], 'manage' => ['integrations.api_clients.manage'], 'setupArea' => 'integrations.integrations', 'order' => 90, 'reference' => [], 'sensitiveRead' => true],
        ['key' => 'platform.ai_gateway', 'title' => 'AI assistance', 'description' => 'Lycenza\'s AI service. It is never an authority and holds no School data access of its own.', 'section' => Sec::PLATFORM_OTHER, 'owner' => 'Lycenza platform', 'available' => false, 'gate' => 'No School-facing AI feature is enabled in this release. The AI service is operated by Lycenza and has no School configuration.', 'managedBy' => 'platform', 'order' => 100, 'reference' => []],
    ];

    /** @var list<IntegrationDefinition> */
    private readonly array $definitions;

    /**
     * @param  list<IntegrationDefinition>|null  $definitions  tests only; production always uses DEFINITIONS
     */
    public function __construct(?array $definitions = null)
    {
        $definitions ??= array_map(IntegrationDefinition::fromArray(...), self::DEFINITIONS);
        self::validate($definitions);
        usort($definitions, fn (IntegrationDefinition $a, IntegrationDefinition $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        $this->definitions = $definitions;
    }

    /** @return list<IntegrationDefinition> every definition, in explicit order */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return list<string> */
    public function availableKeys(): array
    {
        return array_values(array_map(fn (IntegrationDefinition $d): string => $d->key, array_filter($this->definitions, fn (IntegrationDefinition $d): bool => $d->available)));
    }

    /**
     * @param  list<IntegrationDefinition>  $definitions
     */
    public static function validate(array $definitions): void
    {
        $keys = [];
        $orders = [];
        foreach ($definitions as $definition) {
            if (isset($keys[$definition->key])) {
                throw new \LogicException("Integration key {$definition->key} is defined twice.");
            }
            if (isset($orders[$definition->order])) {
                throw new \LogicException("Integration order {$definition->order} is used by both {$orders[$definition->order]} and {$definition->key}.");
            }
            $keys[$definition->key] = true;
            $orders[$definition->order] = $definition->key;

            foreach ($definition->entitlementModules as $module) {
                if (! ModuleCatalogue::exists($module) || ModuleCatalogue::isCore($module)) {
                    throw new \LogicException("{$definition->key} entitlement module {$module} must be an optional catalogue module.");
                }
            }
            if ($definition->setupArea !== null && ! in_array($definition->setupArea, array_column(OrganizationSetupRegistry::DEFINITIONS, 'key'), true)) {
                throw new \LogicException("{$definition->key} names unknown setup area {$definition->setupArea}.");
            }
        }
    }
}
