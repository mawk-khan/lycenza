<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * ORG.7E-1 (ADR 0076 §43): Campus onboarding refused -- the work email
 * already belongs to an Employee of the School (onboarding never attaches or
 * reveals an existing Employee), the Campus is outside the actor's scope or
 * no longer active, or the start date is in the future. One generic answer
 * for a Campus-scoped actor; existing identities are a whole-School matter.
 */
class HrOnboardingUnavailableException extends HrException
{
    public const string CODE = 'HR_ONBOARDING_UNAVAILABLE';

    public function __construct()
    {
        parent::__construct(422, self::CODE, 'This person cannot be onboarded here. Check the details, or ask a whole-School HR administrator.');
    }
}
