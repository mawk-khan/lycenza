<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * SIS 2.1 (MC-4): a Guardian holds each value of a contact type in ONE row,
 * active or inactive (`guardian_contacts` unique (school_id, guardian_id,
 * type, lookup_hash)). A correction or reactivation that would give this
 * Guardian a second row with the same value is refused; another Guardian
 * sharing the value (a household) is never a conflict. No value, hash or id
 * appears in the message.
 */
class GuardianContactConflictException extends GuardianException
{
    public static function duplicate(bool $otherIsActive, string $type): self
    {
        $noun = $type === 'email' ? 'email address' : 'mobile number';

        return new self(422, 'GUARDIAN_CONTACT_DUPLICATE', $otherIsActive
            ? "This Guardian already has this {$noun}."
            : "This Guardian already has this {$noun} as an inactive contact. Reactivate that contact instead.");
    }

    public static function alreadyActive(): self
    {
        return new self(409, 'GUARDIAN_CONTACT_ALREADY_ACTIVE', 'This contact is already active.');
    }

    public static function noLongerValid(): self
    {
        return new self(422, 'GUARDIAN_CONTACT_NO_LONGER_VALID', 'This contact\'s value is not valid under the current rules. Correct it first, then reactivate it.');
    }

    /** SIS 2.2: only an active contact may be primary; reactivate it first. */
    public static function inactiveCannotBePrimary(): self
    {
        return new self(422, 'GUARDIAN_CONTACT_INACTIVE', 'An inactive contact cannot be made primary. Reactivate it first, then make it primary.');
    }

    public static function changed(): self
    {
        return new self(409, 'GUARDIAN_CONTACT_CHANGED', 'This contact changed after you opened it. Reload the page and make your correction again.');
    }
}
