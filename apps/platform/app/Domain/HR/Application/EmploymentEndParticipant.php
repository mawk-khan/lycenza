<?php

namespace App\Domain\HR\Application;

use App\Models\School;
use App\Models\User;

/**
 * S7 (ADR 0063 §47): a module that holds authority granted BY an employment
 * and must end it with the employment. HR defines the contract and calls each
 * participant inside EmploymentService::end()'s transaction, after the
 * EmploymentRecord is locked FOR UPDATE and ended; the implementations live in
 * the modules that depend on HR (today Teaching Assignments), so HR never
 * depends on them (CLAUDE.md rule 4). Participants are registered under the
 * container tag `TAG`.
 *
 * A participant ends what the employment granted after `$endsOn` -- it never
 * deletes, never rewrites history up to and including `$endsOn`, and throws to
 * refuse the whole end (nothing commits).
 */
interface EmploymentEndParticipant
{
    public const TAG = 'hr.employment_end_participants';

    /**
     * Runs inside the end transaction, under the School's TenantContext.
     *
     * @param  string  $endsOn  School-local Y-m-d, the employment's last day (inclusive)
     */
    public function employmentEnded(School $school, string $employeeId, string $endsOn, User $actor): void;
}
