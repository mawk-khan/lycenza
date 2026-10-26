<?php

namespace App\Domain\AcademicStructure\Application\Calendar;

use App\Models\School;

/**
 * SIS 5.1 (MC-3, ADR 0077): records another module keeps that must lie inside
 * their Academic Year's dates (a placement's start, a teaching assignment, an
 * examination, an LMS due date, a fee instalment period or concession window).
 *
 * AcademicStructure defines the contract and asks every dependency while it
 * corrects a draft year's dates, under that year's FOR UPDATE lock; the
 * implementations live in the owning modules, so AcademicStructure never reads
 * their tables (CLAUDE.md rule 4). Registered under the container tag `TAG`.
 *
 * A dependency only counts; it never moves, ends or rewrites anything. Every
 * writer of such a record reads the year FOR SHARE in its own transaction, so
 * a correction either sees the record or the record sees the corrected dates.
 */
interface AcademicYearDateDependency
{
    public const TAG = 'academic_structure.year_date_dependencies';

    /**
     * How many of this module's records in the year would be invalid if the
     * year ran from `$startsOn` to `$endsOn` (inclusive `Y-m-d`). Runs inside
     * the correction transaction, under the School's TenantContext.
     */
    public function recordsOutside(School $school, string $academicYearId, string $startsOn, string $endsOn): int;

    /** The plural noun the operator reads in the refusal, e.g. "placements". */
    public function kind(): string;
}
