<?php

namespace App\Domain\Communications\Application\Channels;

use App\Models\User;

/**
 * Phase 5A.3 §7/§9: the one place a canonical destination email
 * address is derived from a User. Identity resolution stays on
 * User/SchoolMembership (brief §7) -- no Student/Guardian model is
 * introduced here. `resolve()` returns null (never throws) for a User
 * whose stored `email` is missing/blank or fails a basic RFC-shape
 * check -- callers turn that into the appropriate deterministic
 * failure code (`recipient_email_missing`/`recipient_email_invalid`,
 * §9) rather than this class deciding delivery semantics.
 */
final class EmailAddressResolver
{
    public function resolve(User $user): ?string
    {
        // E21.4: a minimized User receives nothing (its address is a placeholder).
        $email = trim((string) $user->publicEmail());

        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
