<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * HRX.2 (ADR 0065 §23.14): what the Leave administration pages share.
 *
 * - **Errors.** A command's domain refusal is a form error (field `leave`)
 *   carrying the stable machine code; an unknown or other-School id is a
 *   form error, never a leak.
 * - **Employee labels.** Directory-tier only: EmploymentRecord id, Employee
 *   number and name, the TeachingAssignment pickers' precedent. They are
 *   read tenant-scoped and only for the School in context.
 */
trait LeavePageSupport
{
    private const EMPLOYMENT_OPTION_LIMIT = 1000;

    /** Runs a command and redirects back; a domain refusal becomes a form error. */
    private function command(callable $command, string $success): RedirectResponse
    {
        try {
            $command();
        } catch (LeaveException $e) {
            throw ValidationException::withMessages(['leave' => "{$e->getMessage()} ({$e->errorCode()})"]);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['leave' => 'Choose a record of this School.']);
        }

        return back()->with('status', $success);
    }

    /**
     * @param  list<string>|null  $ids  restrict to these EmploymentRecords (null: the School's planned and current ones)
     * @return list<array{employmentRecordId: string, employeeNumber: ?string, fullName: ?string, status: string}>
     */
    private function employments(TenantContext $context, School $school, ?array $ids = null): array
    {
        return $context->withSchool($school, fn () => EmploymentRecord::query()->where('school_id', $school->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === null, fn ($q) => $q->whereIn('status', ['pre_joining', 'active', 'notice_period']))
            ->with('employee:id,employee_number,full_name')->limit(self::EMPLOYMENT_OPTION_LIMIT)->get()
            ->map(fn (EmploymentRecord $r) => [
                'employmentRecordId' => $r->id, 'employeeNumber' => $r->employee?->employee_number, 'fullName' => $r->employee?->full_name, 'status' => $r->status,
            ])->sortBy('fullName')->values()->all());
    }
}
