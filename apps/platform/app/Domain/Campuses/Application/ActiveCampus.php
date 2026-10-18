<?php

namespace App\Domain\Campuses\Application;

use App\Models\Campus;
use App\Models\School;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ORG.7D-0 R5 (ADR 0076 §36): the one data-validity check for a write that
 * places NEW operational work on a Campus -- a Section, a Subject Offering,
 * a Timetable entry, an admission application, an employee assignment. The
 * Campus must exist, belong to the School and be active.
 *
 * Never authorization. It answers "can this Campus take new work?", never
 * "may this actor act there?" -- that is CampusScopeResolver's question
 * alone (CLAUDE.md rule 101), and every caller still authorizes first.
 *
 * Inside the caller's transaction the Campus row is share-locked, so a
 * concurrent deactivation waits for the write to commit (and the next write
 * then sees the Campus inactive). Records already on an inactive Campus are
 * never touched: this governs new writes only.
 */
final class ActiveCampus
{
    public const string MESSAGE = 'Choose an active Campus of this School.';

    /** The Campus when it is an active Campus of $school, else null. */
    public function find(School|string $school, ?string $campusId): ?Campus
    {
        if ($campusId === null || ! Str::isUuid($campusId)) {
            return null;
        }

        return Campus::query()
            ->where('school_id', $school instanceof School ? $school->id : $school)
            ->where('status', 'active')
            ->whereKey($campusId)
            ->sharedLock()
            ->first();
    }

    /** The active Campus, or a validation error under $field. */
    public function require(School|string $school, ?string $campusId, string $field = 'campus_id'): Campus
    {
        return $this->find($school, $campusId)
            ?? throw ValidationException::withMessages([$field => [self::MESSAGE]]);
    }
}
