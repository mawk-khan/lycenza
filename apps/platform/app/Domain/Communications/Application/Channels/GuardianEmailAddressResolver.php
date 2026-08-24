<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;

/**
 * Phase 5B.1 §8/§10: the one place a canonical destination email
 * address is derived from a Guardian's GuardianContact rows -- the
 * Guardian-side counterpart to EmailAddressResolver (User-side).
 *
 * Deterministic contact-selection rule (documented, brief §10's
 * "narrowest deterministic rule" when no richer precedence exists):
 *
 *   1. the active, primary email contact, if one exists
 *   2. otherwise, the OLDEST active email contact (created_at ASC,
 *      id ASC tie-break) -- deterministic even when a Guardian has
 *      several email contacts and none is marked primary
 *
 * Documented limitation: a Guardian with multiple active, non-primary
 * email contacts always resolves to the same (oldest) one rather than
 * every address -- this codebase does not send one logical delivery to
 * multiple destinations (brief §10's explicit default). A School/
 * Guardian wanting a different address delivered to should mark it
 * primary via the existing GuardianContactService::setPrimary().
 *
 * `resolve()` DECRYPTS `encrypted_value` in memory (Laravel's
 * `encrypted` cast) only for the duration of this call and returns a
 * plain normalized email string or null -- never throws, never logs,
 * never returns the GuardianContact model itself. Callers must not
 * persist or log the return value anywhere except directly into a
 * delivery's `destination_snapshot` (docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §"Privacy
 * boundary").
 */
final class GuardianEmailAddressResolver
{
    public function resolve(Guardian $guardian): ?string
    {
        $contact = GuardianContact::query()
            ->where('guardian_id', $guardian->id)
            ->where('type', ContactType::Email)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($contact === null) {
            return null;
        }

        // GuardianContactService normalizes/lowercases at write time --
        // this is a defensive re-validation only (brief §33: a bad
        // stored value must degrade to "unavailable", never crash
        // publication), not a second normalization authority.
        $email = trim((string) $contact->encrypted_value);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
