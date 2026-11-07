<?php

namespace Tests\Support;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Domain\HR\Infrastructure\SeparationClearanceItem;
use App\Domain\HR\Infrastructure\SeparationClearanceRequirement;
use App\Domain\Leave\Infrastructure\LeaveAccrualRun;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;

/** HR.R1-C11, HR.R1-C13 (HRC-10, GOV-6): the models whose state, owner and actor columns leave `$fillable`. */
final class LifecycleModels
{
    public const HARDENED = [
        EmploymentRecord::class, EmploymentSeparationCase::class, LeaveAccrualRun::class, LeavePolicy::class, LeaveRequest::class,
        PayrollPeriod::class, PayrollRun::class, EmployeeCompensationAssignment::class, WorkforceTask::class,
        // HR.R1-C13 (WO-2): the service-bound and stateful models of the HR family.
        Department::class, Employee::class, EmployeeAddress::class, EmployeeAssignment::class, EmployeeCategory::class, EmployeeCertification::class,
        EmployeeDocument::class, EmployeeEmergencyContact::class, EmployeeExperience::class, EmployeeNote::class, EmployeePersonalDetail::class, EmployeeQualification::class,
        Position::class, ReportingRelationship::class, SeparationClearanceItem::class, SeparationClearanceRequirement::class,
        LeavePolicyAssignment::class, LeaveType::class, StaffAttendanceRecord::class,
    ];
}
