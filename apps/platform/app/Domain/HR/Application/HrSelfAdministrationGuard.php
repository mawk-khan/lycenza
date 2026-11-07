<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * SR.4 (ADR 0071 §26.2): no self-administration of the HR identity
 * substrate. Teaching ownership, staff self-service, leave decisions and
 * payslips all follow the Employee a User is LINKED to (ActingEmployee),
 * so whoever can re-point their own link -- or shape the employment,
 * assignments, reporting line or attendance of the Employee they are --
 * can manufacture that authority (e.g. relink a teacher's Employee to
 * themselves and inherit the teacher's classes, or unlink themselves and
 * approve their own leave). An actor therefore never:
 *
 * - links an Employee to themselves (create, import or linkUser);
 * - unlinks, archives or restores the Employee linked to them;
 * - creates, changes or ends that Employee's employment or assignments,
 *   or sets a reporting line on which that Employee is either side;
 * - records or corrects that Employee's staff attendance.
 *
 * Another holder of the same capability does it. Called inside the
 * caller's transaction; the Employee row is read FOR SHARE so a
 * concurrent link of it commits first and is seen. Capability-neutral --
 * it applies to every actor (rule 24), School Admin included.
 */
class HrSelfAdministrationGuard
{
    /** @throws HrSelfAdministrationException */
    public function refuseSelfLink(User $actor, string $userId, string $operation): void
    {
        if ($userId === $actor->id) {
            throw new HrSelfAdministrationException($operation);
        }
    }

    /** @throws HrSelfAdministrationException */
    public function refuseOwnEmployee(User $actor, string $employeeId, string $operation): void
    {
        $linkedUserId = Employee::query()->whereKey($employeeId)->sharedLock()->value('user_id');

        if ($linkedUserId !== null && $linkedUserId === $actor->id) {
            throw new HrSelfAdministrationException($operation);
        }
    }

    /**
     * HR.R1-C7 (OD-HR-25): whether $actor has a link, current or ended and not
     * corrected as erroneous, to this Employee. An unlink does not change who a
     * person is, so this, not the current link, decides a person's own pay.
     */
    public function hasUncorrectedLink(User $actor, string $schoolId, string $employeeId): bool
    {
        return EmployeeAccountLink::query()->where('school_id', $schoolId)->where('employee_id', $employeeId)
            ->where('user_id', $actor->id)->whereNull('voided_at')->exists();
    }

    /**
     * HR.R1-C7 (OD-HR-25): whether $actor ever had a link to this Employee, corrected
     * or not. Stricter than hasUncorrectedLink(), for one purpose: nobody tied to an
     * Employee's identity in any way requests or decides a correction of its links.
     */
    public function hasAnyLink(User $actor, string $schoolId, string $employeeId): bool
    {
        return EmployeeAccountLink::query()->where('school_id', $schoolId)->where('employee_id', $employeeId)->where('user_id', $actor->id)->exists();
    }

    /** HR.R1-C7 (OD-HR-25): the same for a Payroll write, by employment. A plain read, as ownsEmployment(). */
    public function isEmployeeOf(User $actor, School $school, string $employmentRecordId): bool
    {
        return app(TenantContext::class)->withSchool($school, function () use ($actor, $school, $employmentRecordId): bool {
            $employeeId = EmploymentRecord::query()->whereKey($employmentRecordId)->value('employee_id');

            return $employeeId !== null && $this->hasUncorrectedLink($actor, $school->id, $employeeId);
        });
    }

    /**
     * Whether $employmentRecordId belongs to the Employee linked to $actor --
     * for another module's own self-administration rule (Payroll, SR.4
     * §26.4). A plain read: a concurrent link of that Employee to the actor
     * is itself an HR action by someone else.
     */
    public function ownsEmployment(User $actor, School $school, string $employmentRecordId): bool
    {
        return app(TenantContext::class)->withSchool($school, function () use ($actor, $employmentRecordId): bool {
            $employeeId = EmploymentRecord::query()->whereKey($employmentRecordId)->value('employee_id');

            return $employeeId !== null && Employee::query()->whereKey($employeeId)->value('user_id') === $actor->id;
        });
    }

    /**
     * HR.9: which of these EmploymentRecords belong to the Employee linked to
     * $actor, in ONE read -- for a batch writer (Leave's eligibility run) that
     * must skip the actor's own employment rather than ask per row.
     *
     * @param  list<string>  $employmentRecordIds
     * @return list<string>
     */
    public function ownedEmploymentIds(User $actor, School $school, array $employmentRecordIds): array
    {
        if ($employmentRecordIds === []) {
            return [];
        }

        return app(TenantContext::class)->withSchool($school, fn (): array => EmploymentRecord::query()
            ->where('employment_records.school_id', $school->id)
            ->whereIn('employment_records.id', $employmentRecordIds)
            ->join('employees', fn ($j) => $j->on('employees.id', '=', 'employment_records.employee_id')->on('employees.school_id', '=', 'employment_records.school_id'))
            ->where('employees.user_id', $actor->id)
            ->pluck('employment_records.id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    /** The same rule, from an EmploymentRecord (Staff Attendance). @throws HrSelfAdministrationException */
    public function refuseOwnEmployment(User $actor, string $employmentRecordId, string $operation): void
    {
        $employeeId = EmploymentRecord::query()->whereKey($employmentRecordId)->value('employee_id');

        if ($employeeId !== null) {
            $this->refuseOwnEmployee($actor, $employeeId, $operation);
        }
    }
}
