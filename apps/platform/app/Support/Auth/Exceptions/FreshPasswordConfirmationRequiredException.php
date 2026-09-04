<?php

namespace App\Support\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\Auth\PasswordConfirmationService::require()
 * when the caller has no recent password confirmation within
 * config('mfa.password_confirmation_window_minutes'). Deliberately
 * lives outside the Mfa\Exceptions namespace -- this seam is generic
 * account-security infrastructure (Phase 0H.4D-P1 section 12), not
 * MFA-specific, even though MFA enrollment/disable/reset are its first
 * callers.
 */
class FreshPasswordConfirmationRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Please confirm your password to continue.');
    }

    public function getStatusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'password_confirmation_required';
    }
}
