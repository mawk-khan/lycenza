<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §5): the fixed contract of one setup area. Built only
 * from OrganizationSetupRegistry's code-owned catalogue -- never from a
 * table, a request or tenant input.
 */
final readonly class SetupDefinition
{
    public const string KEY_PATTERN = '/^[a-z_]+\.[a-z_]+$/';

    /**
     * @param  string  $key  stable `<area>.<subject>` key; never renamed or reused once released
     * @param  string  $ownerModule  the ModuleCatalogue key of the module that owns this configuration
     * @param  list<string>  $entitlementModules  optional modules that must ALL be entitled; [] = core area
     * @param  string  $route  route name of the owning domain's configuration page (a link, never authority)
     * @param  list<string>  $viewCapabilities  School capabilities required, besides the setup floor, to see this area's readiness
     * @param  list<string>  $manageCapabilities  the capabilities the owning page itself checks to change it (informational)
     * @param  list<string>  $dependsOn  setup keys that should be configured first (informational, never authorization)
     * @param  int  $order  explicit, unique display and evaluation order; every dependency has a lower order
     * @param  list<string>  $referenceFamilies  ORG-12 reference capability map family ids (RF-nn)
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $ownerModule,
        public SetupCategory $category,
        public SetupScopeLevel $scopeLevel,
        public array $entitlementModules,
        public string $route,
        public array $viewCapabilities,
        public array $manageCapabilities,
        public array $dependsOn,
        public int $order,
        public array $referenceFamilies,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException("Invalid setup key {$key}.");
        }
        if (trim($title) === '' || trim($description) === '' || trim($route) === '') {
            throw new \InvalidArgumentException("{$key} needs a title, a description and a route.");
        }
        if ($order < 1) {
            throw new \InvalidArgumentException("{$key} needs a positive order.");
        }
    }

    /** A core area is always applicable; an optional one only while its modules are entitled. */
    public function isCore(): bool
    {
        return $this->entitlementModules === [];
    }

    /**
     * @param  array<string, mixed>  $row  one OrganizationSetupRegistry::DEFINITIONS row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            key: $row['key'],
            title: $row['title'],
            description: $row['description'],
            ownerModule: $row['owner'],
            category: $row['category'],
            scopeLevel: $row['scope'],
            entitlementModules: $row['entitlement'],
            route: $row['route'],
            viewCapabilities: $row['view'],
            manageCapabilities: $row['manage'],
            dependsOn: $row['dependsOn'],
            order: $row['order'],
            referenceFamilies: $row['reference'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'ownerModule' => $this->ownerModule,
            'category' => $this->category->value,
            'scopeLevel' => $this->scopeLevel->value,
            'core' => $this->isCore(),
            'route' => $this->route,
            'dependsOn' => $this->dependsOn,
            'order' => $this->order,
        ];
    }
}
