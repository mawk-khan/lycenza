<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Application\Exceptions\CommunityRefusedException;
use App\Domain\Students\Application\Exceptions\HouseRefusedException;
use App\Domain\Students\Application\Exceptions\StudentCommunityChangedException;
use App\Domain\Students\Application\Exceptions\StudentHouseChangedException;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\CodeNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.7 / 11.8: the ONE writer of the community catalogue (Houses, Clubs,
 * Activities), reached only through HouseAdministrationService (House,
 * `students.houses.manage`) and CommunityAdministrationService (Club and
 * Activity, `students.communities.manage`); each passes its own type and
 * capability, so neither can write the other's groups.
 *
 * A group is School-wide (no Campus), has a unique name and code within the
 * School and type, and is deactivated, never deleted. Deactivation is
 * refused while it has current members (never orphaned Students).
 * Corrections carry the version the form saw. An Activity may carry one
 * optional closed ActivityCategory; no other type has one.
 */
final class StudentGroupCatalogue
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The type's groups with their CURRENT member count as the viewer may see
     * it: a Campus-scoped viewer counts only Students in their scope, so the
     * School-wide size never leaks through a count (operational, never
     * analytics).
     *
     * @return Collection<int, array{group: StudentGroup, members: int}>
     */
    public function list(School $school, StudentGroupType $type, CampusScope $scope): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $type, $scope): Collection {
            $visible = $this->students->students($school, $scope)->select('students.id');
            $counts = DB::table('student_group_memberships')->where('school_id', $school->id)
                ->where('group_type', $type->value)->whereNull('ends_on')
                ->whereIn('student_id', $visible)->groupBy('student_group_id')
                ->selectRaw('student_group_id, count(*) as members')->pluck('members', 'student_group_id');

            return StudentGroup::query()->where('school_id', $school->id)->where('group_type', $type->value)
                ->orderByRaw("status = 'inactive'")->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (StudentGroup $h) => ['group' => $h, 'members' => (int) ($counts[$h->id] ?? 0)]);
        });
    }

    /**
     * The type's active groups (names only), for an assignment form.
     *
     * @return EloquentCollection<int, StudentGroup>
     */
    public function active(School $school, StudentGroupType $type): EloquentCollection
    {
        return $this->context->withSchool($school, fn () => StudentGroup::query()->where('school_id', $school->id)
            ->where('group_type', $type->value)->where('status', 'active')
            ->orderBy('display_order')->orderBy('name')->get(['id', 'name', 'group_type', 'activity_category']));
    }

    /** @param  array<string, mixed>  $input */
    public function create(School $school, User $actor, StudentGroupType $type, string $capability, array $input): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, $capability, $school);
        $data = $this->validated($type, $input, false);

        return $this->context->withSchool($school, fn () => $this->unique($type, fn () => DB::transaction(function () use ($school, $actor, $type, $data): StudentGroup {
            $group = new StudentGroup;
            $group->forceFill([
                'school_id' => $school->id,
                'group_type' => $type,
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? 0),
                'activity_category' => $type === StudentGroupType::ACTIVITY ? ($data['activity_category'] ?? null) : null,
                'version' => 1,
            ])->save();
            $this->audit->school($school, 'student_group.created', actor: $actor, subject: $group, metadata: [
                'studentGroupId' => $group->id, 'groupType' => $type->value, 'code' => $group->code,
            ]);

            return $group;
        })));
    }

    /**
     * Name, code, description, order (and an Activity's category)
     * correction; memberships are never rewritten: history names the group by
     * reference.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(School $school, User $actor, StudentGroupType $type, string $capability, string $groupId, array $input): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, $capability, $school);
        $data = $this->validated($type, $input, true);

        return $this->context->withSchool($school, fn () => $this->unique($type, fn () => DB::transaction(function () use ($school, $actor, $type, $groupId, $data): StudentGroup {
            $group = $this->locked($school, $type, $groupId);
            if ($group->version !== (int) $data['version']) {
                throw $this->stale($type);
            }
            $group->forceFill([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? 0),
                ...($type === StudentGroupType::ACTIVITY ? ['activity_category' => $data['activity_category'] ?? null] : []),
            ]);
            $changed = array_keys($group->getDirty());
            if ($changed === []) {
                return $group;
            }
            $group->forceFill(['version' => $group->version + 1])->save();
            $this->audit->school($school, 'student_group.updated', actor: $actor, subject: $group, metadata: [
                'studentGroupId' => $group->id, 'groupType' => $type->value, 'changedFields' => $changed,
            ]);

            return $group;
        })));
    }

    /** Activate or deactivate; deactivation is refused while the group has current members. */
    public function setStatus(School $school, User $actor, StudentGroupType $type, string $capability, string $groupId, string $status, int $expectedVersion): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, $capability, $school);
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose active or inactive.']);
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $type, $groupId, $status, $expectedVersion): StudentGroup {
            // FOR UPDATE: a membership write holds the group FOR SHARE, so a
            // deactivation waits for it and then sees its new member.
            $group = $this->locked($school, $type, $groupId);
            if ($group->version !== $expectedVersion) {
                throw $this->stale($type);
            }
            if ($group->status === $status) {
                return $group;
            }
            if ($status === 'inactive' && DB::table('student_group_memberships')->where('student_group_id', $group->id)->whereNull('ends_on')->exists()) {
                throw $type === StudentGroupType::HOUSE ? new HouseRefusedException('HOUSE_HAS_MEMBERS') : new CommunityRefusedException('COMMUNITY_HAS_MEMBERS', $type);
            }
            $group->forceFill(['status' => $status, 'version' => $group->version + 1])->save();
            $this->audit->school($school, $status === 'inactive' ? 'student_group.deactivated' : 'student_group.reactivated', actor: $actor, subject: $group, metadata: [
                'studentGroupId' => $group->id, 'groupType' => $type->value,
            ]);

            return $group;
        }));
    }

    private function locked(School $school, StudentGroupType $type, string $groupId): StudentGroup
    {
        return StudentGroup::query()->where('school_id', $school->id)->where('group_type', $type->value)
            ->lockForUpdate()->findOrFail($groupId);
    }

    private function stale(StudentGroupType $type): \RuntimeException
    {
        return $type === StudentGroupType::HOUSE ? new StudentHouseChangedException : new StudentCommunityChangedException;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(StudentGroupType $type, array $input, bool $withVersion): array
    {
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = CodeNormalizer::normalize($input['code']);
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim((string) preg_replace('/\s+/u', ' ', $input['name']));
        }
        if (($input['activity_category'] ?? null) === '') {
            $input['activity_category'] = null;
        }

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:80', 'regex:/^[^\p{Cc}]+$/u'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            ...($type === StudentGroupType::ACTIVITY ? ['activity_category' => ['nullable', Rule::enum(ActivityCategory::class)]] : []),
            ...($withVersion ? ['version' => ['required', 'integer', 'min:1']] : []),
        ], [
            'code.regex' => 'Use letters, digits, - or _ only.',
        ], [])->validate();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function unique(StudentGroupType $type, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'student_groups_school_type_name_unique') ? 'name' : 'code';
            $kind = $type->label();
            throw ValidationException::withMessages([$field => $field === 'name' ? "Another {$kind} already has this name." : "Another {$kind} already has this code."]);
        }
    }
}
