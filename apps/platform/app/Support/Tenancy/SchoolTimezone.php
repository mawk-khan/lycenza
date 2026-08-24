<?php

namespace App\Support\Tenancy;

use App\Models\School;
use DateTimeZone;

/**
 * Phase 5A.4 §18 -- `schools.timezone` (Phase 0B, default
 * `Asia/Kolkata`) is a real, editable-via-`App\Http\Controllers\App\SchoolSettingsController`
 * field, so scheduling uses the PREFERRED model: a user enters a
 * scheduled time in their School's configured timezone, the server
 * converts it to canonical UTC for storage, and the due-time
 * comparison and every display back to the user go through this same
 * timezone consistently.
 *
 * `schools.timezone` is only validated as `required|string|max:64` at
 * write time (not restricted to `DateTimeZone::listIdentifiers()`) --
 * so a corrupted value is possible in principle. `resolve()` never
 * throws for that case: it falls back to `config('app.timezone')`
 * (UTC), the same safe default every other timestamp in this codebase
 * already assumes. This is a documented limitation, not a silently
 * invented behavior (brief §18) -- a future School profile checkpoint
 * should tighten `timezone` validation at the source instead of this
 * class growing more defensive logic.
 */
final class SchoolTimezone
{
    public static function resolve(School $school): DateTimeZone
    {
        try {
            return new DateTimeZone($school->timezone);
        } catch (\Exception) {
            return new DateTimeZone((string) config('app.timezone'));
        }
    }
}
