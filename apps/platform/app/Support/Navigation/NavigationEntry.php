<?php

namespace App\Support\Navigation;

/**
 * SWC.1 (ADR 0073 section 6.1): one destination in the staff navigation
 * catalogue. Its `scope` decides which capability set the requirement is
 * evaluated against:
 *   - school:   the actor's capabilities in the active School (TenantContext);
 *   - platform: the actor's platform capabilities (platform host only);
 *   - group:    any School Group grant holding the capability (platform host only);
 *   - account:  any signed-in account (no capability).
 *
 * `any` and `all` hold EXISTING capability keys only; navigation never
 * introduces a capability of its own and never names a role.
 */
final readonly class NavigationEntry
{
    public const SCOPE_SCHOOL = 'school';

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_GROUP = 'group';

    public const SCOPE_ACCOUNT = 'account';

    /**
     * @param  list<string>  $any  at least one must be held (empty: no any-of requirement)
     * @param  list<string>  $all  every one must be held
     * @param  list<string>  $activeWhen  route-name patterns (Str::is) that mark this entry active; empty: the route itself and its children
     * @param  string|null  $availability  a named availability guard (NavigationBuilder::AVAILABILITY_*)
     * @param  bool  $platformHostOnly  never emitted on a School custom host (ADR 0054 section 8.2)
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $routeName,
        public string $scope = self::SCOPE_SCHOOL,
        public array $any = [],
        public array $all = [],
        public ?string $section = null,
        public array $activeWhen = [],
        public ?string $availability = null,
        public bool $platformHostOnly = false,
    ) {}

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_values(array_unique([...$this->any, ...$this->all]));
    }

    /** @return list<string> */
    public function activePatterns(): array
    {
        if ($this->activeWhen !== []) {
            return $this->activeWhen;
        }

        $base = str_ends_with($this->routeName, '.index') ? substr($this->routeName, 0, -strlen('.index')) : $this->routeName;

        return [$this->routeName, $base.'.*'];
    }
}
