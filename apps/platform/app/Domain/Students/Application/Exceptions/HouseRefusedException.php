<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 11.7: a House operation the rules refuse (closed codes, plain text,
 * no other Student or House named): an inactive House or Student, a House
 * with current members being deactivated, a unique responsibility already
 * held, a responsibility without a current membership.
 */
class HouseRefusedException extends StudentException
{
    public const array MESSAGES = [
        'HOUSE_INACTIVE' => 'This House is inactive. Choose an active House.',
        'HOUSE_SAME' => 'The Student is already in this House.',
        'HOUSE_HAS_MEMBERS' => 'This House still has current members. Move or end their memberships before deactivating it.',
        'STUDENT_NOT_ACTIVE' => 'Only an active Student can be given a House. Their House history stays visible.',
        'NO_CURRENT_HOUSE' => 'The Student has no current House.',
        'RESPONSIBILITY_TAKEN' => 'This House already has a current holder of that responsibility. End it first.',
        'RESPONSIBILITY_HELD' => 'The Student already holds this responsibility.',
    ];

    public function __construct(string $code)
    {
        parent::__construct(409, $code, self::MESSAGES[$code]);
    }
}
