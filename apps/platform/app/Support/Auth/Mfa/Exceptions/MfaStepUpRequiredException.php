<?php

namespace App\Support\Auth\Mfa\Exceptions;

/**
 * Thrown by the `mfa` route middleware: the User has an active factor,
 * but the current session's MFA assurance (session('mfa_verified_at'))
 * is missing or has expired past config('mfa.assurance_window_minutes').
 * Distinct from MfaRequiredNotEnrolledException so the frontend can
 * offer an inline re-challenge instead of a full "go enroll" prompt.
 */
class MfaStepUpRequiredException extends MfaException
{
    public function __construct()
    {
        parent::__construct(401, 'mfa_step_up_required', 'Your multi-factor authentication assurance has expired. Please verify again to continue.');
    }
}
