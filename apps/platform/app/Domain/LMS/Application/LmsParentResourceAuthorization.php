<?php

namespace App\Domain\LMS\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use InvalidArgumentException;

/**
 * TCH.5B (ADR 0063 sections 34.9, 35) -- the LMS-owned answer to "may
 * this actor read / write the LMS resource that owns this Document?".
 *
 * Documents asks here instead of naming an LMS capability itself, so the
 * LMS resource-authorization decision lives in LMS. The direction is the
 * existing Documents -> LMS one; LMS never depends on Documents, and
 * Documents never sees an owner Employee, an audience or a
 * TeachingAssignment.
 *
 * Today this is exactly the School-wide (Tier 1) rule Documents applied
 * before: `lms.content.view/.manage`, `lms.assignments.view/.manage`. No
 * teacher path exists. TCH.5C/TCH.5D add the owned (Tier 2) branch here --
 * owned-scope capability + ActingEmployee + the owner/audience rule +
 * TeachingAssignment coverage -- and Documents does not change.
 *
 * `$parentId` is the Document's LMS owner row. It is unused by the Tier 1
 * rule and is part of the contract because the owned rule is per row.
 */
class LmsParentResourceAuthorization
{
    use AuthorizesCapability;

    public const LEARNING_CONTENT = 'learning_content';

    public const ASSIGNMENT = 'assignment';

    private const READ = [self::LEARNING_CONTENT => 'lms.content.view', self::ASSIGNMENT => 'lms.assignments.view'];

    private const WRITE = [self::LEARNING_CONTENT => 'lms.content.manage', self::ASSIGNMENT => 'lms.assignments.manage'];

    public function authorizeRead(User $actor, School $school, string $parentType, string $parentId): void
    {
        $this->authorizeCapabilityFor($actor, self::READ[$parentType] ?? $this->unknown($parentType), $school);
    }

    public function authorizeWrite(User $actor, School $school, string $parentType, string $parentId): void
    {
        $this->authorizeCapabilityFor($actor, self::WRITE[$parentType] ?? $this->unknown($parentType), $school);
    }

    private function unknown(string $parentType): never
    {
        throw new InvalidArgumentException("Not an LMS parent resource type: {$parentType}");
    }
}
