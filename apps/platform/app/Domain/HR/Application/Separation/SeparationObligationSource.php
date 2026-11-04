<?php

namespace App\Domain\HR\Application\Separation;

use App\Models\School;

/**
 * HR.12: a module that can answer, from its OWN data, what a separating
 * employment still holds there (pending leave, timetable duties, an active
 * driver assignment, School access). HR defines the contract; the
 * implementations live in the modules that already depend on HR and are
 * registered under `TAG`, so HR never reads their tables (CLAUDE.md rule 4,
 * the EmploymentEndParticipant shape). A source reads only, never writes,
 * never caches, and runs under the School's TenantContext.
 */
interface SeparationObligationSource
{
    public const TAG = 'hr.separation_obligation_sources';

    /** The owning module's name, shown when its answer is unavailable (HR.15F). */
    public function owner(): string;

    /** @return list<SeparationObligation> */
    public function obligations(School $school, SeparationSubject $subject): array;
}
