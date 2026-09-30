<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * TCH.1 (ADR 0063 sections 4-5, D-08, D-10): the ONE canonical way to
 * answer "who is this User, as an eligible Employee, at this School, on
 * this School-local date?". No teaching module, controller or middleware
 * derives an Employee from a User on its own.
 *
 * The identity chain, every link required, any missing link fatal:
 *
 *   School operational (schools.status = 'active')
 *   -> active SchoolMembership for (user, school)
 *   -> enabled User
 *   -> the Employee linked through employees(school_id, user_id)
 *   -> Employee.record_status = 'active'
 *   -> exactly one EmploymentRecord with
 *        starts_on <= asOf AND (ends_on IS NULL OR ends_on >= asOf)
 *        AND status IN ('active', 'notice_period')
 *
 * The only identity input is the authenticated User and the trusted
 * School the caller already resolved. Nothing is ever inferred from an
 * email, employee number, name, role, request-supplied id or timetable
 * row (CLAUDE.md rule 19). Two eligible EmploymentRecords (impossible
 * under EmploymentService's overlap invariant, so corrupt or legacy data)
 * fail closed instead of picking one.
 *
 * The result identifies; it authorizes nothing. CapabilityResolver stays
 * a separate, independent fact (ADR 0063 section 11).
 *
 * Two modes, mirroring SchoolOperationalGuard's isOperational() /
 * holdOperational() split:
 *
 * - resolve(): a fresh read, no locks -- for reads and refusals.
 * - hold(): inside the caller's transaction, every row of the chain is
 *   read FOR SHARE and stays locked until that transaction ends. A
 *   state-changing consumer calls it inside its authoritative transaction,
 *   so a concurrent membership suspension, unlink, archive or employment
 *   end either commits first (and hold() refuses) or waits until the
 *   consumer's write has committed -- never in between (ADR 0063
 *   section 20).
 *
 * Lock order (hold): School -> membership -> User -> Employee ->
 * EmploymentRecord, the ADR 0063 order. StaffAccessService takes
 * membership before User, EmployeeService::linkUser() takes membership ->
 * User -> Employee, and EmploymentService locks Employee before creating
 * and EmploymentRecord alone when ending, so no two paths acquire these
 * rows in opposite orders.
 *
 * Nothing is cached: every call reads current rows (ADR 0063 section 21).
 */
class ActingEmployeeResolver
{
    public function __construct(
        private readonly SchoolOperationalGuard $guard,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string|null  $asOf  School-local date (Y-m-d); defaults to today in the School's timezone.
     *
     * @throws ActingEmployeeUnavailableException
     */
    public function resolve(User $user, School $school, ?string $asOf = null): ActingEmployee
    {
        return $this->establish($user, $school, $asOf, lock: false);
    }

    /**
     * resolve(), with every row of the chain held FOR SHARE until the
     * caller's transaction ends. Must run inside a database transaction.
     *
     * @param  string|null  $asOf  School-local date (Y-m-d); defaults to today in the School's timezone.
     *
     * @throws ActingEmployeeUnavailableException
     */
    public function hold(User $user, School $school, ?string $asOf = null): ActingEmployee
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActingEmployeeResolver::hold() must run inside a database transaction.');
        }

        return $this->establish($user, $school, $asOf, lock: true);
    }

    private function establish(User $user, School $school, ?string $asOf, bool $lock): ActingEmployee
    {
        $asOf = $this->asOfDate($school, $asOf);

        $operational = $lock ? $this->guard->holdOperational($school->id) : $this->guard->isOperational($school->id);
        if (! $operational) {
            throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::SCHOOL_NOT_OPERATIONAL);
        }

        $membership = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->when($lock, fn ($q) => $q->sharedLock())
            ->first();
        if ($membership === null || $membership->status !== SchoolMembership::STATUS_ACTIVE) {
            throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::MEMBERSHIP_NOT_ACTIVE);
        }

        // Re-read: the caller's User model may be stale.
        $account = User::query()->whereKey($user->id)->when($lock, fn ($q) => $q->sharedLock())->first();
        if ($account === null || $account->isDisabled()) {
            throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::USER_UNAVAILABLE);
        }

        return $this->context->withSchool($school, function () use ($school, $user, $asOf, $lock): ActingEmployee {
            $employee = Employee::query()
                ->where('school_id', $school->id)
                ->where('user_id', $user->id)
                ->when($lock, fn ($q) => $q->sharedLock())
                ->first();
            if ($employee === null) {
                throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::NOT_LINKED);
            }
            if (! $employee->isActive()) {
                throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::EMPLOYEE_NOT_ACTIVE);
            }

            $eligible = EmploymentRecord::query()
                ->where('school_id', $school->id)
                ->where('employee_id', $employee->id)
                ->where('starts_on', '<=', $asOf)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $asOf))
                ->whereIn('status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
                ->when($lock, fn ($q) => $q->sharedLock())
                ->limit(2)
                ->get();

            if ($eligible->isEmpty()) {
                throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::NO_ELIGIBLE_EMPLOYMENT);
            }
            if ($eligible->count() > 1) {
                throw new ActingEmployeeUnavailableException(ActingEmployeeUnavailableException::AMBIGUOUS_EMPLOYMENT);
            }

            return new ActingEmployee(
                schoolId: $school->id,
                userId: $user->id,
                employeeId: $employee->id,
                employmentRecordId: $eligible->first()->id,
                asOf: $asOf,
            );
        });
    }

    private function asOfDate(School $school, ?string $asOf): string
    {
        if ($asOf === null) {
            return CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $asOf);
        } catch (Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('ActingEmployeeResolver: $asOf must be a Y-m-d date.');
        }

        return $asOf;
    }
}
