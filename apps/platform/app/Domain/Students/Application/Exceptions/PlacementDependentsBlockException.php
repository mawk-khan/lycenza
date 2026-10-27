<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 4.2: a placement change that would leave other modules' active records
 * behind. Nothing is ended, moved or cancelled for the operator -- each record
 * is resolved in its own module first. The message names the kinds of
 * records (from the owning modules), never an id or another person.
 *
 * - transfer(): a whole-School transfer that changes the Student's Campus
 *   while an active Hostel residency, Transport rider assignment or elective
 *   on the old Campus remains (the Campus-scoped path keeps its one generic
 *   refusal, ADR 0076 §47.5).
 * - cancellation(): cancelling a placement before it starts while records
 *   already depend on it (an elective, a fee assessment, a mark).
 */
class PlacementDependentsBlockException extends StudentException
{
    /** @param  list<string>  $kinds */
    public static function transfer(array $kinds): self
    {
        return new self(422, 'STUDENT_TRANSFER_BLOCKED', 'This Student can\'t change Campus yet. First end or move: '.implode('; ', $kinds).'.');
    }

    /** @param  list<string>  $kinds */
    public static function cancellation(array $kinds): self
    {
        return new self(422, 'PLACEMENT_CANCELLATION_BLOCKED', 'This placement can\'t be cancelled yet because other records depend on it. First resolve: '.implode('; ', $kinds).'.');
    }
}
