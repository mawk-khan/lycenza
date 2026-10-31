<?php

namespace App\Domain\Students\Application\Exceptions;

use App\Domain\Students\Application\Groups\StudentGroupType;

/**
 * SIS 11.8: a Club or Activity operation the rules refuse (closed codes,
 * plain text naming only the type, never another Student or group).
 */
class CommunityRefusedException extends StudentException
{
    public function __construct(string $code, StudentGroupType $type)
    {
        $kind = $type->label();
        parent::__construct(409, $code, match ($code) {
            'COMMUNITY_INACTIVE' => "This {$kind} is inactive. Choose an active {$kind}.",
            'ALREADY_MEMBER' => $type === StudentGroupType::ACTIVITY ? 'The Student already participates in this Activity.' : 'The Student is already a member of this Club.',
            'NOT_A_MEMBER' => $type === StudentGroupType::ACTIVITY ? 'The Student no longer participates in this Activity.' : 'The Student is no longer a member of this Club.',
            'STUDENT_NOT_ACTIVE' => "Only an active Student can join a {$kind}. Their history stays visible.",
            'COMMUNITY_HAS_MEMBERS' => $type === StudentGroupType::ACTIVITY
                ? 'This Activity still has current participants. End their participation before deactivating it.'
                : 'This Club still has current members. End their memberships before deactivating it.',
            'RESPONSIBILITY_TAKEN' => 'This Club already has a current holder of that responsibility. End it first.',
            'RESPONSIBILITY_HELD' => 'The Student already holds this responsibility.',
            default => throw new \InvalidArgumentException("Unknown community refusal {$code}."),
        });
    }
}
