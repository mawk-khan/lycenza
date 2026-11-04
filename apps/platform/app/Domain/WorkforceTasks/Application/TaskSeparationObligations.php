<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;

/**
 * HR.13: a separating employment's open staff tasks. Shown for review on the
 * separation case, never blocking and never cancelled or reassigned
 * automatically: a person reassigns or cancels each one (no replacement is
 * invented). Generation stops by itself once the employment is not current.
 */
final class TaskSeparationObligations implements SeparationObligationSource
{
    public function owner(): string
    {
        return 'Staff tasks';
    }

    public function obligations(School $school, SeparationSubject $subject): array
    {
        $open = WorkforceTask::query()->where('school_id', $school->id)->where('employment_record_id', $subject->employmentRecordId)
            ->whereIn('status', WorkforceTask::OPEN_STATUSES)->count();

        return [new SeparationObligation('staff_tasks_open', 'Staff tasks', 'Open staff tasks', $open > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $open,
            $open > 0 ? 'Reassign or cancel these tasks under Staff tasks; nothing is moved automatically.' : null)];
    }
}
