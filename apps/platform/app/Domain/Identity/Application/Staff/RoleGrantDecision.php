<?php

namespace App\Domain\Identity\Application\Staff;

/**
 * SR.2 (ADR 0071 §6.2, §13): the outcome of one role-grant (or revoke)
 * authority decision.
 *
 * `refusal` is null when allowed, otherwise one closed code (REFUSALS).
 * `grantRights` are the grant rights the issuer USED (sorted), and
 * `coveredClasses` the classes of the capabilities those rights covered --
 * audit metadata for a sensitive grant. `uncoveredClasses` are the classes
 * of the capabilities the issuer neither holds nor covers -- audit metadata
 * for a refusal. Never a capability list: classes only.
 */
final class RoleGrantDecision
{
    /** The closed refusal codes (`school.membership.role_grant_refused` metadata `refusal`). */
    public const REFUSALS = [
        'inactive_issuer',      // disabled, or no ACTIVE membership in this School (incl. another School's admin)
        'not_role_manager',     // lacks school.roles.manage (or the staff-administration capabilities the operation needs)
        'self_administration',  // the issuer's own membership
        'retired',              // the role is retired: no NEW grant
        'empty_role',           // the role carries no capability
        'not_covered',          // a capability neither held nor covered by a held grant right
        'database_backstop',    // the database grantor trigger refused after the application allowed (never expected)
    ];

    /**
     * @param  list<string>  $uncoveredClasses
     * @param  list<string>  $grantRights
     * @param  list<string>  $coveredClasses
     */
    private function __construct(
        public readonly ?string $refusal,
        public readonly array $uncoveredClasses = [],
        public readonly array $grantRights = [],
        public readonly array $coveredClasses = [],
    ) {}

    /**
     * @param  list<string>  $grantRights
     * @param  list<string>  $coveredClasses
     */
    public static function allow(array $grantRights = [], array $coveredClasses = []): self
    {
        return new self(null, [], $grantRights, $coveredClasses);
    }

    /** @param  list<string>  $uncoveredClasses */
    public static function refuse(string $refusal, array $uncoveredClasses = []): self
    {
        return new self($refusal, $uncoveredClasses);
    }

    public function allowed(): bool
    {
        return $this->refusal === null;
    }

    /**
     * The sensitive-grant audit metadata for `role_assigned`: empty for an
     * ordinary grant that used no grant right (kept lightweight).
     *
     * @return array<string, list<string>>
     */
    public function grantMetadata(): array
    {
        return $this->grantRights === [] ? [] : ['grantRights' => $this->grantRights, 'classes' => $this->coveredClasses];
    }
}
