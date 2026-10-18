<?php

namespace Tests\Support\Authorization;

/**
 * ORG.2 (ADR 0076 §28): one School route's declared authorization contract.
 *
 * C1 (route `capability:` middleware) is read from the route itself and is
 * never declared here. Every other School route declares exactly one of:
 *
 *   C2 gate()     -- the action (or a helper it calls unconditionally) runs
 *                    the canonical `capability` Gate for these keys before
 *                    protected work;
 *   C3 service()  -- the action delegates to this Application/Domain method,
 *                    which authorizes the actor itself;
 *   C4 owned()    -- a narrower scoped authority (teacher ownership, acting
 *                    employee, guardian scope, own membership, creator) is
 *                    the authority, plus any base capability it also needs;
 *   C5 special()  -- context establishment / token acceptance: ordinary
 *                    School capability coverage cannot apply. Needs the
 *                    governing ADR/invariant, the reason and a test.
 *
 * Evidence is checked mechanically by CoverageEvidence against the code,
 * never against comments.
 */
final class Coverage
{
    public const GATE = 'C2';

    public const SERVICE = 'C3';

    public const OWNED = 'C4';

    public const SPECIAL = 'C5';

    /**
     * @param  list<string>  $capabilities  keys the action itself must gate on (C2, and C4's base capability)
     * @param  list<array{0: class-string, 1: string}>  $authorities  methods that carry the authority (C3/C4)
     */
    private function __construct(
        public readonly string $route,
        public readonly string $class,
        public readonly array $capabilities = [],
        public readonly array $authorities = [],
        public readonly ?string $scope = null,
        public readonly ?string $governedBy = null,
        public readonly ?string $reason = null,
        public readonly ?string $evidence = null,
        public readonly bool $validatesFirst = false,
        public readonly bool $everyMember = false,
    ) {}

    /** @param  string|list<string>  $capabilities */
    public static function gate(string $route, string|array $capabilities): self
    {
        return new self($route, self::GATE, capabilities: (array) $capabilities);
    }

    /** @param  class-string  $service */
    public static function service(string $route, string $service, string $method): self
    {
        return new self($route, self::SERVICE, authorities: [[$service, $method]]);
    }

    /**
     * @param  class-string  $authority
     * @param  string|list<string>  $capabilities  base capability the action also gates on, if any
     */
    public static function owned(string $route, string $authority, string $method, string $scope, string|array $capabilities = []): self
    {
        return new self($route, self::OWNED, capabilities: (array) $capabilities, authorities: [[$authority, $method]], scope: $scope);
    }

    /** @param  class-string  $evidence  the test class that proves the contract */
    public static function special(string $route, string $governedBy, string $reason, string $evidence): self
    {
        return new self($route, self::SPECIAL, governedBy: $governedBy, reason: $reason, evidence: $evidence);
    }

    /**
     * The action validates its input before the authority runs, so an
     * unauthorized request with an empty payload sees a validation error
     * first (existing behaviour, kept). Only C3/C4 authority may do this,
     * or a C2 gate that sits after validation inside the action.
     */
    public function validatesFirst(): self
    {
        return new self($this->route, $this->class, $this->capabilities, $this->authorities, $this->scope, $this->governedBy, $this->reason, $this->evidence, true, $this->everyMember);
    }

    /**
     * Every active member passes by design: the resource is the actor's own
     * (their membership's preferences) or a data-free notice page. Only C4
     * own-membership and C5 entries may say so.
     */
    public function forEveryMember(): self
    {
        return new self($this->route, $this->class, $this->capabilities, $this->authorities, $this->scope, $this->governedBy, $this->reason, $this->evidence, $this->validatesFirst, true);
    }
}
