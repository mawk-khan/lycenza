<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0O.3 (ADR 0049 sections 2-3): creating or changing a reusable API
 * credential needs an enrolled factor AND a fresh code, re-verified now
 * (MfaReverificationService, the elevation and School-lifecycle
 * precedent) -- stale sign-in assurance is never enough.
 */
class FreshMfaRequirement
{
    public function __construct(private readonly MfaReverificationService $mfa) {}

    public function require(Request $request, User $user, mixed $code): void
    {
        if (! $this->mfa->hasActiveFactor($user)) {
            throw ValidationException::withMessages(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);
        }

        if (! is_string($code) || trim($code) === '') {
            throw ValidationException::withMessages(['mfa_code' => 'Enter a current authentication code.']);
        }

        if (! $this->mfa->reverify($request, $user, trim($code))) {
            throw ValidationException::withMessages(['mfa_code' => 'That code is not valid.']);
        }
    }

    public function hasActiveFactor(User $user): bool
    {
        return $this->mfa->hasActiveFactor($user);
    }
}
