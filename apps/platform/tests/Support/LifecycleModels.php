<?php

namespace Tests\Support;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Leave\Infrastructure\LeaveAccrualRun;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;

/** HR.R1-C11 (HRC-10, GOV-6): the lifecycle models whose state and actor columns leave `$fillable`. */
final class LifecycleModels
{
    public const HARDENED = [
        EmploymentRecord::class, EmploymentSeparationCase::class, LeaveAccrualRun::class, LeavePolicy::class, LeaveRequest::class,
        PayrollPeriod::class, PayrollRun::class, EmployeeCompensationAssignment::class, WorkforceTask::class,
    ];
}
