<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Application\Exceptions\CommunityRefusedException;
use App\Domain\Students\Application\Exceptions\StudentCommunityChangedException;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.8 (OD-SIS-5A Club, OD-SIS-5B Activity): a Student's Club
 * memberships and Activity participation -- the ONE writer (Houses stay
 * StudentHouseService's).
 *
 * - Cardinality: any number of Clubs and Activities at once; ONE current
 *   membership per Student per Club/Activity (partial unique index
 *   `student_group_memberships_one_current_per_group`; the no-overlap trigger
 *   keeps history intervals apart).
 * - Authority: whole-School `students.communities.assign`, re-read fresh
 *   under the Student row lock, on a Student the actor sees whole-School
 *   (`students.view`; else NOT FOUND). History needs whole-School
 *   `students.communities.view`.
 * - Explicit only: no bulk, import, rule or automatic allocation.
 * - Dates: a join starts the School-local TODAY; a leave closes YESTERDAY
 *   (inclusive `ends_on`; a same-day leave is the empty interval, ADR 0078).
 * - Stale safety: a join is additive and refused when the Student is already
 *   a current member (no version needed; joining a DIFFERENT Club at the
 *   same time is valid). A leave and a responsibility end carry the row's
 *   version, compared under the lock.
 * - Club responsibilities are descriptive (ClubResponsibility), unique where
 *   declared, and end with their membership; Activities have none.
 * - Only an active Student joins or takes a responsibility; leaving stays
 *   possible for any Student. Status changes, Campus transfer, rollover and
 *   conversion never touch a membership.
 *
 * Lock order (as for Houses): the Student (FOR NO KEY UPDATE), then the
 * membership, then the group (FOR SHARE to join, FOR UPDATE for a unique
 * responsibility). Deactivation takes only the group.
 */
final class StudentCommunityService
{
    use AuthorizesCapability, GuardsStudentGroupWrites;

    public const string VIEW = CommunityAdministrationService::VIEW;

    public const string ASSIGN = CommunityAdministrationService::ASSIGN;

    /** Current Clubs/Activities listed on the Student 360 card before "and N more". */
    public const int CARD_LIMIT = 4;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The Student, every Club and Activity membership (current first) and
     * every Club responsibility (two queries), for a whole-School viewer.
     *
     * @return array{student: Student, memberships: Collection<int, StudentGroupMembership>, responsibilities: Collection<int, StudentGroupResponsibility>}
     */
    public function history(School $school, User $actor, string $studentId): array
    {
        // A declared whole-School operation of the Campus-adopted key: the full
        // history is School-wide (rule 101).
        if (! $this->students->scopeOf($actor, self::VIEW, $school)->isWholeSchool()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn (): array => [
            'student' => $student,
            'memberships' => StudentGroupMembership::query()->with('group')->where('student_id', $student->id)
                ->whereIn('group_type', $this->types())->orderByRaw('ends_on IS NOT NULL')->orderByDesc('starts_on')->orderByDesc('id')->get(),
            'responsibilities' => StudentGroupResponsibility::query()->where('student_id', $student->id)
                ->whereIn('responsibility', $this->clubResponsibilities())->orderByRaw('ends_on IS NOT NULL')->orderByDesc('starts_on')->orderByDesc('id')->get(),
        ]);
    }

    /**
     * The Student 360 card: current Clubs (with responsibilities) and
     * Activities, only when the viewer's `students.communities.view` scope
     * reaches this Student; null (absent) otherwise. Bounded: two queries,
     * at most CARD_LIMIT names per type plus a count of the rest.
     *
     * @return array{clubs: list<array{name: string, responsibilities: list<string>}>, moreClubs: int, activities: list<array{name: string, category: ?string}>, moreActivities: int}|null
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
                ->whereIn('group_type', $this->types())->whereNull('ends_on')->get()
                ->sortBy(fn (StudentGroupMembership $m) => [$m->group->display_order, $m->group->name])->values();
            $roles = StudentGroupResponsibility::query()->whereIn('student_group_membership_id', $current->pluck('id')->all())
                ->whereNull('ends_on')->get()->groupBy('student_group_membership_id');
            $clubs = $current->filter(fn (StudentGroupMembership $m) => $m->group_type === StudentGroupType::CLUB)->values();
            $activities = $current->filter(fn (StudentGroupMembership $m) => $m->group_type === StudentGroupType::ACTIVITY)->values();

            return [
                'clubs' => $clubs->take(self::CARD_LIMIT)->map(fn (StudentGroupMembership $m) => [
                    'name' => $m->group->name,
                    'responsibilities' => collect($roles->get($m->id, []))->map(fn (StudentGroupResponsibility $r) => $r->responsibility->label())->values()->all(),
                ])->values()->all(),
                'moreClubs' => max(0, $clubs->count() - self::CARD_LIMIT),
                'activities' => $activities->take(self::CARD_LIMIT)->map(fn (StudentGroupMembership $m) => [
                    'name' => $m->group->name,
                    'category' => $m->group->activity_category?->label(),
                ])->values()->all(),
                'moreActivities' => max(0, $activities->count() - self::CARD_LIMIT),
            ];
        });
    }

    /** Join a Club or start Activity participation, from today. */
    public function join(School $school, User $actor, string $studentId, string $groupId): StudentGroupMembership
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, function () use ($school, $actor, $student, $groupId): StudentGroupMembership {
            try {
                return DB::transaction(function () use ($school, $actor, $student, $groupId): StudentGroupMembership {
                    $locked = $this->lockStudentRow($school, $actor, $student->id, self::ASSIGN);
                    $group = StudentGroup::query()->where('school_id', $school->id)->whereIn('group_type', $this->types())
                        ->sharedLock()->find($groupId) ?? throw (new ModelNotFoundException)->setModel(StudentGroup::class, [$groupId]);
                    if ($locked->status !== 'active') {
                        throw new CommunityRefusedException('STUDENT_NOT_ACTIVE', $group->group_type);
                    }
                    if ($group->status !== 'active') {
                        throw new CommunityRefusedException('COMMUNITY_INACTIVE', $group->group_type);
                    }
                    if (StudentGroupMembership::query()->where('student_id', $locked->id)->where('student_group_id', $group->id)->whereNull('ends_on')->exists()) {
                        throw new CommunityRefusedException('ALREADY_MEMBER', $group->group_type);
                    }

                    $membership = new StudentGroupMembership;
                    $membership->forceFill([
                        'school_id' => $school->id,
                        'student_group_id' => $group->id,
                        'group_type' => $group->group_type,
                        'student_id' => $locked->id,
                        'starts_on' => SchoolTimezone::today($school),
                        'start_reason' => CommunityMembershipReason::JOINED,
                        'started_by_user_id' => $actor->id,
                        'version' => 1,
                    ])->save();
                    $this->audit->school($school, 'student_community.joined', actor: $actor, subject: $locked, metadata: [
                        'studentId' => $locked->id, 'studentGroupId' => $group->id, 'groupType' => $group->group_type->value,
                    ]);

                    return $membership;
                });
            } catch (UniqueConstraintViolationException) {
                // The per-group index decided a race the Student lock did not see (defence in depth).
                throw new StudentCommunityChangedException;
            }
        });
    }

    /** Leave a Club or end Activity participation (and its responsibilities), with a closed reason. */
    public function leave(School $school, User $actor, string $studentId, string $membershipId, CommunityMembershipReason $reason, int $expectedVersion): StudentGroupMembership
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $student, $membershipId, $reason, $expectedVersion): StudentGroupMembership {
            $locked = $this->lockStudentRow($school, $actor, $student->id, self::ASSIGN);
            $membership = $this->lockedMembership($locked, $membershipId);
            if ($membership->ends_on !== null) {
                throw new CommunityRefusedException('NOT_A_MEMBER', $membership->group_type);
            }
            if ($membership->version !== $expectedVersion) {
                throw new StudentCommunityChangedException;
            }
            if (! in_array($reason, CommunityMembershipReason::endReasons($membership->group_type), true)) {
                throw ValidationException::withMessages(['reason' => 'Choose why this ends.']);
            }

            $today = SchoolTimezone::today($school);
            foreach (StudentGroupResponsibility::query()->where('student_group_membership_id', $membership->id)->whereNull('ends_on')->lockForUpdate()->get() as $row) {
                $this->closeResponsibility($row, $actor, $today);
            }
            $membership->forceFill([
                'ends_on' => $this->dayBefore($today),
                'end_reason' => $reason,
                'ended_by_user_id' => $actor->id,
                'version' => $membership->version + 1,
            ])->save();
            $this->audit->school($school, 'student_community.left', actor: $actor, subject: $locked, metadata: [
                'studentId' => $locked->id, 'studentGroupId' => $membership->student_group_id, 'groupType' => $membership->group_type->value, 'reason' => $reason->value,
            ]);

            return $membership;
        }));
    }

    /** A descriptive Club responsibility on a current Club membership (never a permission). */
    public function assignResponsibility(School $school, User $actor, string $studentId, string $membershipId, ClubResponsibility $responsibility): StudentGroupResponsibility
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, function () use ($school, $actor, $student, $membershipId, $responsibility): StudentGroupResponsibility {
            try {
                return DB::transaction(function () use ($school, $actor, $student, $membershipId, $responsibility): StudentGroupResponsibility {
                    $locked = $this->lockStudentRow($school, $actor, $student->id, self::ASSIGN);
                    $membership = $this->lockedMembership($locked, $membershipId);
                    if ($membership->group_type !== StudentGroupType::CLUB) {
                        throw ValidationException::withMessages(['responsibility' => 'Only a Club membership has responsibilities.']);
                    }
                    if ($membership->ends_on !== null) {
                        throw new CommunityRefusedException('NOT_A_MEMBER', StudentGroupType::CLUB);
                    }
                    if ($locked->status !== 'active') {
                        throw new CommunityRefusedException('STUDENT_NOT_ACTIVE', StudentGroupType::CLUB);
                    }
                    // The Club FOR UPDATE serializes its responsibility writes.
                    StudentGroup::query()->whereKey($membership->student_group_id)->lockForUpdate()->firstOrFail();
                    $held = StudentGroupResponsibility::query()->whereNull('ends_on')->where('responsibility', $responsibility->value);
                    if ((clone $held)->where('student_group_membership_id', $membership->id)->exists()) {
                        throw new CommunityRefusedException('RESPONSIBILITY_HELD', StudentGroupType::CLUB);
                    }
                    if ($responsibility->isUnique() && (clone $held)->where('student_group_id', $membership->student_group_id)->exists()) {
                        throw new CommunityRefusedException('RESPONSIBILITY_TAKEN', StudentGroupType::CLUB);
                    }

                    $row = new StudentGroupResponsibility;
                    $row->forceFill([
                        'school_id' => $school->id,
                        'student_group_id' => $membership->student_group_id,
                        'student_group_membership_id' => $membership->id,
                        'student_id' => $locked->id,
                        'responsibility' => $responsibility,
                        'starts_on' => SchoolTimezone::today($school),
                        'assigned_by_user_id' => $actor->id,
                        'version' => 1,
                    ])->save();
                    $this->audit->school($school, 'student_community.responsibility_assigned', actor: $actor, subject: $locked, metadata: [
                        'studentId' => $locked->id, 'studentGroupId' => $membership->student_group_id, 'responsibility' => $responsibility->value,
                    ]);

                    return $row;
                });
            } catch (UniqueConstraintViolationException) {
                // A racing holder committed first (the partial unique index decided).
                throw new CommunityRefusedException('RESPONSIBILITY_TAKEN', StudentGroupType::CLUB);
            }
        });
    }

    public function endResponsibility(School $school, User $actor, string $studentId, string $responsibilityId, int $expectedVersion): StudentGroupResponsibility
    {
        $this->authorizeCapabilityFor($actor, self::ASSIGN, $school);
        $student = $this->visibleStudent($school, $actor, $studentId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $student, $responsibilityId, $expectedVersion): StudentGroupResponsibility {
            $this->lockStudentRow($school, $actor, $student->id, self::ASSIGN);
            $row = StudentGroupResponsibility::query()->where('student_id', $student->id)->whereNull('ends_on')
                ->whereIn('responsibility', $this->clubResponsibilities())->lockForUpdate()->find($responsibilityId)
                ?? throw (new ModelNotFoundException)->setModel(StudentGroupResponsibility::class, [$responsibilityId]);
            if ($row->version !== $expectedVersion) {
                throw new StudentCommunityChangedException;
            }
            $this->closeResponsibility($row, $actor, SchoolTimezone::today($school));
            $this->audit->school($school, 'student_community.responsibility_ended', actor: $actor, subject: $student, metadata: [
                'studentId' => $student->id, 'studentGroupId' => $row->student_group_id, 'responsibility' => $row->responsibility->value,
            ]);

            return $row;
        }));
    }

    /** One of this Student's Club/Activity memberships, locked; another Student's or a House's is NOT FOUND. */
    private function lockedMembership(Student $student, string $membershipId): StudentGroupMembership
    {
        return StudentGroupMembership::query()->where('student_id', $student->id)->whereIn('group_type', $this->types())
            ->lockForUpdate()->find($membershipId) ?? throw (new ModelNotFoundException)->setModel(StudentGroupMembership::class, [$membershipId]);
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_map(fn (StudentGroupType $t) => $t->value, StudentGroupType::communities());
    }

    /** @return list<string> */
    private function clubResponsibilities(): array
    {
        return array_map(fn (ClubResponsibility $r) => $r->value, ClubResponsibility::cases());
    }
}
