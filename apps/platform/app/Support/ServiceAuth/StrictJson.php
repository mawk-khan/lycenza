<?php

namespace App\Support\ServiceAuth;

/**
 * A flat JSON object whose values are all scalars, with duplicate members
 * refused (json_decode silently keeps the last one). Every string followed
 * by a colon in a flat object is a member name, so counting those against
 * the decoded members detects any duplicate.
 */
final class StrictJson
{
    /** @return array<string, scalar>|null */
    public static function flatObject(string $json): ?array
    {
        $value = json_decode($json, false, 2);
        if (! $value instanceof \stdClass) {
            return null;
        }
        $members = get_object_vars($value);
        foreach ($members as $member) {
            if (! is_scalar($member)) {
                return null;
            }
        }
        $names = preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/', $json);

        return $names === count($members) ? $members : null;
    }
}
