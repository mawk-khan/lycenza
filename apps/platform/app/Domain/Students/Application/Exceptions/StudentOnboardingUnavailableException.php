<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * ORG.7F-2 (ADR 0076 §46): Campus onboarding refused. One generic answer for
 * every reason a Campus-scoped actor could probe -- a Section that is
 * unknown, of another School or Campus, out of scope, inactive, on an
 * inactive Campus, or outside the active year; no active year at all -- so
 * it never discloses another Campus, record or state.
 */
class StudentOnboardingUnavailableException extends StudentException
{
    public function __construct()
    {
        parent::__construct(422, 'STUDENT_ONBOARDING_UNAVAILABLE', 'This Student cannot be onboarded into that class. Choose an active class of your Campus in the current academic year.');
    }
}
