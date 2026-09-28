<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Phase 0O.10A (ADR 0056 section 11.2): the session side of
 * `users.credential_version`. Every place a browser session becomes fully
 * authenticated STAMPS the User's current version into it:
 *
 * - LoginController::store()          (password-only login)
 * - MfaChallengeController::store()   (MFA completion)
 * - InvitationAcceptanceController    (a new Guardian signs in on acceptance)
 * - SessionHandoffController          (cross-host sign-in; the ticket carries
 *                                      the version and a mismatch is refused)
 *
 * (guard-tested: every Auth::login/attempt call site stamps). The
 * EnforceCredentialVersion middleware compares on every authenticated
 * request, on every host: a missing or different stamp ends the session.
 */
final class CredentialSession
{
    public const KEY = 'auth.credential_version';

    /** Stamps the version as stored now (a just-created model does not carry the column default). */
    public static function stamp(Session $session, User $user): void
    {
        $session->put(self::KEY, self::current($user));
    }

    public static function current(User $user): int
    {
        return (int) User::query()->whereKey($user->getKey())->value('credential_version');
    }

    public static function matches(Session $session, User $user): bool
    {
        $stamped = $session->get(self::KEY);
        // A User resolved from the session carries the column; a model
        // created in-process does not carry its database default yet.
        $current = $user->getAttribute('credential_version');

        return is_int($stamped) && $stamped === ($current === null ? self::current($user) : (int) $current);
    }
}
