<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;

/**
 * SIS 11.H1 (SIS 11.R1 stale-form inventory): the optimistic-concurrency
 * token of a Student–Guardian relationship -- a digest of its governed
 * fields (type, primary, legal guardian, emergency contact, authorized
 * pickup), the same pattern as GuardianProfileVersion. `is_primary` is
 * included although an edit never writes it: a form opened before the
 * primary Guardian changed is stale. Unkeyed: no value is secret from the
 * form's viewer.
 */
final class StudentGuardianRelationshipVersion
{
    public static function of(StudentGuardianRelationship $relationship): string
    {
        return hash('sha256', implode("\x1f", [
            'student-guardian-relationship:v1',
            $relationship->id,
            $relationship->relationship_type->value,
            $relationship->is_primary ? '1' : '0',
            $relationship->is_legal_guardian ? '1' : '0',
            $relationship->is_emergency_contact ? '1' : '0',
            $relationship->is_authorized_pickup ? '1' : '0',
        ]));
    }

    public static function matches(StudentGuardianRelationship $relationship, string $expected): bool
    {
        return hash_equals(self::of($relationship), $expected);
    }
}
