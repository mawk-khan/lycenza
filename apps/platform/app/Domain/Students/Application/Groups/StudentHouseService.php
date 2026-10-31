<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Application\Exceptions\HouseRefusedException;
use App\Domain\Students\Application\Exceptions\StudentHouseChangedException;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.7 (OD-SIS-4): a Student's House membership and responsibilities --
 * the ONE writer.
 *
 * - Authority: whole-School `students.houses.assign`, re-read fresh under
 *   the Student row lock, on a Student the actor sees whole-School
 *   (`students.view`; else NOT FOUND). Reading the history needs
 *   whole-School `students.houses.view` (it is School-wide history).
 * - Explicit only: no automatic allocation by Grade, Campus, sibling,
 *   number or anything else; no bulk; no import.
 * - Effective dates are the School-local TODAY: a membership starts today;
 *   ending one (a change, or an explicit end) closes it YESTERDAY
 *   (inclusive `ends_on`), so a same-day replacement records the empty
 *   interval `ends_on = starts_on - 1` (ADR 0078). Nothing is back-dated.
 * - Stale-safe: every membership write carries the StudentHouseVersion the
 *   form saw, compared under the Student row lock; the partial unique index
 *   and the overlap trigger are the database backstops.
 * - Responsibilities are descriptive participation (never authority), end
 *   with their membership, and Captain/Vice Captain are unique per House.
 * - Only an active Student gets a new House or responsibility; ending stays
 *   possible for any Student. A Student's status change, Campus transfer or
 *   rollover never touches their House (Houses are School-wide).
 *
 * Lock order: the Student (FOR NO KEY UPDATE, rule 101) first, then the
 * House (FOR SHARE to join it, FOR UPDATE for a unique responsibility).
 * House deactivation takes only the House (FOR UPDATE).
 */
final class StudentHouseService
{
    use AuthorizesCapability;

    public const string VIEW = 'students.houses.view';

    public const string ASSIGN = 'students.houses.assign';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The Student, their current membership and their full history (one
     * query each), for a whole-School House viewer.
     *
     * @return array{student: Student, current: ?StudentGroupMembership, memberships: Collection<int, StudentGroupMembership>, responsibilities: Collection<int, StudentGroupResponsibility>, version: string}
     */
    public function history(School $school, User $actor, string $studentId): array
    {
        // A declared whole-School operation of the Campus-adopted key: the
        // full House history is School-wide (rule 101), so a Campus scope does
        // not open it.
        if (! $this->students->scopeOf($actor, HouseAdministrationService::VIEW, $school)->isWholeSchool()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, function () use ($student): array {
            $memberships = StudentGroupMembership::query()->with('group')->where('student_id', $student->id)
                ->where('group_type', StudentGroupType::HOUSE->value)->orderByDesc('starts_on')->orderByDesc('id')->get();
            $current = $memberships->first(fn (StudentGroupMembership $m) => $m->ends_on === null);

            return [
                'student' => $student,
                'current' => $current,
                'memberships' => $memberships,
                'responsibilities' => StudentGroupResponsibility::query()->where('student_id', $student->id)
                    ->orderByRaw('ends_on IS NOT NULL')->orderByDesc('starts_on')->orderByDesc('id')->get(),
                'version' => StudentHouseVersion::of($student->id, $current),
            ];
        });
    }

    /**
     * The Student 360 card: the current House and responsibilities, only when
     * the viewer's `students.houses.view` scope reaches this Student (a
     * Campus scope through their current Campus); null (absent) otherwise.
     * Two queries.
     *
     * @return array{house: ?string, since: ?string, responsibilities: list<string>}|null
     */
    public function summary(School $school, CampusScope $scope, Student $student): ?array
    {
        if ($scope->isNone()) {
            return null;
        }

        return $this->context->withSchool($school, function () use ($school, $scope, $student): ?array {
            if (! $scope->isWholeSchool() && ! $this->students->students($school, $scope)->whereKey($student->id)->exists()) {
                return null;
            }
            $current = StudentGroupMembership::query()->with('group')->where('student_id', $student->id)
                ->where('group_type', StudentGroupType::HOUSE->value)->whereNull('ends_on')->first();

            return [
                'house' => $current?->group->name,
                'since' => $current?->starts_on->toDateString(),
                'responsibilities' => $current === null ? [] : StudentGroupResponsibility::query()->where('student_group_membership_id', $current->id)
                    ->whereNull('ends_on')->orderBy('responsibility')->get()->map(fn (StudentGroupResponsibility $r) => $r->responsibility->label())->values()->all(),
            ];
        });
    }

    /** Assign a House (no current one) or change to another (with a closed reason). */
    public function assign(School $school, User $actor, string $studentId, string $houseId, ?HouseMembershipReason $reason, string $expectedVersion): StudentGroupMembership
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn () => $this->unique(fn () => DB::transaction(function () use ($school, $actor, $student, $houseId, $reason, $expectedVersion): StudentGroupMembership {
            [$locked, $current] = $this->lockStudent($school, $actor, $student->id, $expectedVersion);
            if ($locked->status !== 'active') {
                throw new HouseRefusedException('STUDENT_NOT_ACTIVE');
            }
            $house = StudentGroup::query()->where('school_id', $school->id)->where('group_type', StudentGroupType::HOUSE->value)
                ->sharedLock()->find($houseId) ?? throw (new ModelNotFoundException)->setModel(StudentGroup::class, [$houseId]);
            if ($house->status !== 'active') {
                throw new HouseRefusedException('HOUSE_INACTIVE');
            }

            $today = SchoolTimezone::today($school);
            if ($current !== null) {
                if ($current->student_group_id === $house->id) {
                    throw new HouseRefusedException('HOUSE_SAME');
                }
                if ($reason === null || ! in_array($reason, HouseMembershipReason::changeReasons(), true)) {
                    throw ValidationException::withMessages(['reason' => 'Choose why the House changes.']);
                }
                $this->close($current, $actor, $reason, $today);
            }
            $start = $current === null ? HouseMembershipReason::ASSIGNMENT : $reason;

            $membership = new StudentGroupMembership;
            $membership->forceFill([
                'school_id' => $school->id,
                'student_group_id' => $house->id,
                'group_type' => StudentGroupType::HOUSE,
                'student_id' => $locked->id,
                'starts_on' => $today,
                'start_reason' => $start,
                'started_by_user_id' => $actor->id,
                'version' => 1,
            ])->save();

            $this->audit->school($school, $current === null ? 'student_house.assigned' : 'student_house.changed', actor: $actor, subject: $locked, metadata: [
                'studentId' => $locked->id,
                'studentGroupId' => $house->id,
                'previousStudentGroupId' => $current?->student_group_id,
                'reason' => $start->value,
            ]);

            return $membership;
        })));
    }

    /** End the current membership (and its responsibilities) with a closed reason; the Student is then unassigned. */
    public function end(School $school, User $actor, string $studentId, HouseMembershipReason $reason, string $expectedVersion): StudentGroupMembership
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        if (! in_array($reason, HouseMembershipReason::endReasons(), true)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the membership ends.']);
        }
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $student, $reason, $expectedVersion): StudentGroupMembership {
            [$locked, $current] = $this->lockStudent($school, $actor, $student->id, $expectedVersion);
            if ($current === null) {
                throw new HouseRefusedException('NO_CURRENT_HOUSE');
            }
            $this->close($current, $actor, $reason, SchoolTimezone::today($school));
            $this->audit->school($school, 'student_house.ended', actor: $actor, subject: $locked, metadata: [
                'studentId' => $locked->id, 'studentGroupId' => $current->student_group_id, 'reason' => $reason->value,
            ]);

            return $current;
        }));
    }

    /** A descriptive responsibility on the current membership (never a permission). */
    public function assignResponsibility(School $school, User $actor, string $studentId, HouseResponsibility $responsibility, string $expectedVersion): StudentGroupResponsibility
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, function () use ($school, $actor, $student, $responsibility, $expectedVersion): StudentGroupResponsibility {
            try {
                return DB::transaction(function () use ($school, $actor, $student, $responsibility, $expectedVersion): StudentGroupResponsibility {
                    [$locked, $current] = $this->lockStudent($school, $actor, $student->id, $expectedVersion);
                    if ($current === null) {
                        throw new HouseRefusedException('NO_CURRENT_HOUSE');
                    }
                    if ($locked->status !== 'active') {
                        throw new HouseRefusedException('STUDENT_NOT_ACTIVE');
                    }
                    // The House FOR UPDATE serializes its responsibility writes.
                    StudentGroup::query()->whereKey($current->student_group_id)->lockForUpdate()->firstOrFail();
                    $held = StudentGroupResponsibility::query()->whereNull('ends_on')->where('responsibility', $responsibility->value);
                    if ((clone $held)->where('student_group_membership_id', $current->id)->exists()) {
                        throw new HouseRefusedException('RESPONSIBILITY_HELD');
                    }
                    if ($responsibility->isUnique() && (clone $held)->where('student_group_id', $current->student_group_id)->exists()) {
                        throw new HouseRefusedException('RESPONSIBILITY_TAKEN');
                    }

                    $row = new StudentGroupResponsibility;
                    $row->forceFill([
                        'school_id' => $school->id,
                        'student_group_id' => $current->student_group_id,
                        'student_group_membership_id' => $current->id,
                        'student_id' => $locked->id,
                        'responsibility' => $responsibility,
                        'starts_on' => SchoolTimezone::today($school),
                        'assigned_by_user_id' => $actor->id,
                        'version' => 1,
                    ])->save();
                    $this->audit->school($school, 'student_house.responsibility_assigned', actor: $actor, subject: $locked, metadata: [
                        'studentId' => $locked->id, 'studentGroupId' => $current->student_group_id, 'responsibility' => $responsibility->value,
                    ]);

                    return $row;
                });
            } catch (UniqueConstraintViolationException) {
                // A racing holder committed first (the partial unique index decided).
                throw new HouseRefusedException('RESPONSIBILITY_TAKEN');
            }
        });
    }

    public function endResponsibility(School $school, User $actor, string $studentId, string $responsibilityId, int $expectedVersion): StudentGroupResponsibility
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $student, $responsibilityId, $expectedVersion): StudentGroupResponsibility {
            $this->lockStudentRow($school, $actor, $student->id);
            $row = StudentGroupResponsibility::query()->where('student_id', $student->id)->whereNull('ends_on')->lockForUpdate()->find($responsibilityId)
                ?? throw (new ModelNotFoundException)->setModel(StudentGroupResponsibility::class, [$responsibilityId]);
            if ($row->version !== $expectedVersion) {
                throw new StudentHouseChangedException;
            }
            $this->closeResponsibility($row, $actor, SchoolTimezone::today($school));
            $this->audit->school($school, 'student_house.responsibility_ended', actor: $actor, subject: $student, metadata: [
                'studentId' => $student->id, 'studentGroupId' => $row->student_group_id, 'responsibility' => $row->responsibility->value,
            ]);

            return $row;
        }));
    }

    /** The Student when the actor sees them whole-School, else NOT FOUND. */
    private function visibleStudent(School $school, User $actor, string $studentId): Student
    {
        $scope = $this->students->scopeOf($actor, 'students.view', $school);
        if (! $scope->isWholeSchool()) {
            throw (new ModelNotFoundException)->setModel(Student::class, [$studentId]);
        }

        return $this->context->withSchool($school, fn () => $this->students->student($school, $scope, $studentId));
    }

    private function lockStudentRow(School $school, User $actor, string $studentId): Student
    {
        $locked = Student::query()->where('school_id', $school->id)->whereKey($studentId)->lock('for no key update')->firstOrFail();
        if (! $this->capabilities->holdsInSchoolNow($actor, self::ASSIGN, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $locked;
    }

    /** @return array{0: Student, 1: ?StudentGroupMembership} */
    private function lockStudent(School $school, User $actor, string $studentId, string $expectedVersion): array
    {
        $locked = $this->lockStudentRow($school, $actor, $studentId);
        $current = StudentGroupMembership::query()->where('student_id', $locked->id)->where('group_type', StudentGroupType::HOUSE->value)
            ->whereNull('ends_on')->lockForUpdate()->first();
        if (! hash_equals(StudentHouseVersion::of($locked->id, $current), $expectedVersion)) {
            throw new StudentHouseChangedException;
        }

        return [$locked, $current];
    }

    /** Ends the membership's current responsibilities, then the membership itself, the day before $today. */
    private function close(StudentGroupMembership $membership, User $actor, HouseMembershipReason $reason, string $today): void
    {
        foreach (StudentGroupResponsibility::query()->where('student_group_membership_id', $membership->id)->whereNull('ends_on')->lockForUpdate()->get() as $row) {
            $this->closeResponsibility($row, $actor, $today);
        }
        $membership->forceFill([
            'ends_on' => $this->dayBefore($today),
            'end_reason' => $reason,
            'ended_by_user_id' => $actor->id,
            'version' => $membership->version + 1,
        ])->save();
    }

    private function closeResponsibility(StudentGroupResponsibility $row, User $actor, string $today): void
    {
        $row->forceFill(['ends_on' => $this->dayBefore($today), 'ended_by_user_id' => $actor->id, 'version' => $row->version + 1])->save();
    }

    private function dayBefore(string $today): string
    {
        return CarbonImmutable::parse($today)->subDay()->toDateString();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            // The one-current index decided a race the lock did not see (defence in depth).
            throw new StudentHouseChangedException;
        }
    }
}
