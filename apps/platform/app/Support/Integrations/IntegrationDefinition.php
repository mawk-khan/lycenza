<?php

namespace App\Support\Integrations;

/**
 * ORG.12G (ADR 0080 §12): the fixed contract of one integration family.
 * Built only from OrganizationIntegrationCatalogue's code-owned catalogue --
 * never from a table, a request or tenant input. There are no
 * tenant-defined integration types.
 */
final readonly class IntegrationDefinition
{
    public const string KEY_PATTERN = '/^[a-z_]+\.[a-z_]+$/';

    /**
     * @param  string  $key  stable `<area>.<subject>` key; never renamed or reused once released
     * @param  string  $owner  display label of the owning module or service
     * @param  bool  $available  whether Lycenza implements this integration at all; false = NOT_AVAILABLE, never evaluated
     * @param  ?string  $gate  for an unavailable family: the documented decision that keeps it unavailable
     * @param  string  $managedBy  `school` (the School configures it) or `platform` (Lycenza operates it)
     * @param  list<string>  $entitlementModules  optional modules that must ALL be entitled; [] = always applicable
     * @param  list<string>  $viewCapabilities  School capabilities required, besides the Hub floor, to see this family's status
     * @param  ?string  $route  route name of the owning domain's web page (a link, never authority); null = no web page
     * @param  list<string>  $openCapabilities  any-of: the capabilities that page itself checks
     * @param  list<string>  $manageCapabilities  every capability that page checks to change it (informational)
     * @param  ?string  $setupArea  the ORG.12A setup key this family contributes to, if any
     * @param  list<string>  $referenceFamilies  ORG-12 reference capability map family ids (RF-nn)
     * @param  bool  $sensitiveRead  the owning page needs current MFA assurance to show this family's records
     *                               (SensitiveReadAssurance); the Hub then needs it too before showing a status
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public IntegrationSection $section,
        public string $owner,
        public bool $available,
        public ?string $gate,
        public string $managedBy,
        public array $entitlementModules,
        public array $viewCapabilities,
        public ?string $route,
        public array $openCapabilities,
        public array $manageCapabilities,
        public ?string $setupArea,
        public int $order,
        public array $referenceFamilies,
        public bool $sensitiveRead = false,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException("Invalid integration key {$key}.");
        }
        if (trim($title) === '' || trim($description) === '' || trim($owner) === '' || $order < 1) {
            throw new \InvalidArgumentException("{$key} needs a title, a description, an owner and a positive order.");
        }
        if (! in_array($managedBy, ['school', 'platform'], true)) {
            throw new \InvalidArgumentException("{$key} is managed by the School or the platform.");
        }
        if ($available === ($gate !== null)) {
            throw new \InvalidArgumentException("{$key} names its gate exactly when it is not available.");
        }
        if (! $available && ($route !== null || $openCapabilities !== [] || $manageCapabilities !== [] || $viewCapabilities !== [] || $setupArea !== null)) {
            throw new \InvalidArgumentException("{$key} is not available, so it has no page, capability or setup area.");
        }
        if ($sensitiveRead && ! $available) {
            throw new \InvalidArgumentException("{$key} is not available, so it has nothing to protect.");
        }
        if (($route === null) !== ($openCapabilities === [])) {
            throw new \InvalidArgumentException("{$key} names the capabilities its page checks exactly when it has a page.");
        }
    }

    /**
     * @param  array<string, mixed>  $row  one OrganizationIntegrationCatalogue::DEFINITIONS row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            key: $row['key'],
            title: $row['title'],
            description: $row['description'],
            section: $row['section'],
            owner: $row['owner'],
            available: $row['available'],
            gate: $row['gate'] ?? null,
            managedBy: $row['managedBy'],
            entitlementModules: $row['entitlement'] ?? [],
            viewCapabilities: $row['view'] ?? [],
            route: $row['route'] ?? null,
            openCapabilities: $row['open'] ?? [],
            manageCapabilities: $row['manage'] ?? [],
            setupArea: $row['setupArea'] ?? null,
            order: $row['order'],
            referenceFamilies: $row['reference'] ?? [],
            sensitiveRead: $row['sensitiveRead'] ?? false,
        );
    }
}
