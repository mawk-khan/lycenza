<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Application\Exceptions\HouseRefusedException;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.7 (OD-SIS-4): the ONE writer of Houses -- whole-School
 * `students.houses.manage`. A House is School-wide (no Campus), has a
 * unique name and code within the School, and is deactivated, never
 * deleted. Deactivation is refused while it has current members (never
 * orphaned Students). Corrections carry the House version the form saw.
 */
final class HouseAdministrationService
{
    use AuthorizesCapability;

    public const string MANAGE = 'students.houses.manage';

    public const string VIEW = 'students.houses.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Houses with their CURRENT member count as the viewer may see it: a
     * Campus-scoped viewer counts only Students in their scope, so the
     * School-wide size never leaks through a count (operational, never
     * analytics).
     *
     * @return Collection<int, array{house: StudentGroup, members: int}>
     */
    public function list(School $school, CampusScope $scope): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $scope): Collection {
            $visible = $this->students->students($school, $scope)->select('students.id');
            $counts = DB::table('student_group_memberships')->where('school_id', $school->id)
                ->where('group_type', StudentGroupType::HOUSE->value)->whereNull('ends_on')
                ->whereIn('student_id', $visible)->groupBy('student_group_id')
                ->selectRaw('student_group_id, count(*) as members')->pluck('members', 'student_group_id');

            return StudentGroup::query()->where('school_id', $school->id)->where('group_type', StudentGroupType::HOUSE->value)
                ->orderByRaw("status = 'inactive'")->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (StudentGroup $h) => ['house' => $h, 'members' => (int) ($counts[$h->id] ?? 0)]);
        });
    }

    /**
     * The active Houses (names only), for the assignment form.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StudentGroup>
     */
    public function activeHouses(School $school): \Illuminate\Database\Eloquent\Collection
    {
        return $this->context->withSchool($school, fn () => StudentGroup::query()->where('school_id', $school->id)
            ->where('group_type', StudentGroupType::HOUSE->value)->where('status', 'active')
            ->orderBy('display_order')->orderBy('name')->get(['id', 'name']));
    }

    /** @param  array<string, mixed>  $input */
    public function create(School $school, User $actor, array $input): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE, $school);
        $data = $this->validated($input, false);

        return $this->context->withSchool($school, fn () => $this->unique(fn () => DB::transaction(function () use ($school, $actor, $data): StudentGroup {
            $house = new StudentGroup;
            $house->forceFill([
                'school_id' => $school->id,
                'group_type' => StudentGroupType::HOUSE,
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? 0),
                'version' => 1,
            ])->save();
            $this->audit->school($school, 'student_group.created', actor: $actor, subject: $house, metadata: [
                'studentGroupId' => $house->id, 'groupType' => StudentGroupType::HOUSE->value, 'code' => $house->code,
            ]);

            return $house;
        })));
    }

    /**
     * Name, code, description and order correction (memberships are never
     * rewritten: history names the House by reference).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(School $school, User $actor, string $houseId, array $input): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE, $school);
        $data = $this->validated($input, true);

        return $this->context->withSchool($school, fn () => $this->unique(fn () => DB::transaction(function () use ($school, $actor, $houseId, $data): StudentGroup {
            $house = $this->locked($school, $houseId);
            if ($house->version !== (int) $data['version']) {
                throw new StudentHouseChangedException;
            }
            $house->forceFill([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? 0),
            ]);
            $changed = array_keys($house->getDirty());
            if ($changed === []) {
                return $house;
            }
            $house->forceFill(['version' => $house->version + 1])->save();
            $this->audit->school($school, 'student_group.updated', actor: $actor, subject: $house, metadata: [
                'studentGroupId' => $house->id, 'changedFields' => $changed,
            ]);

            return $house;
        })));
    }

    /** Activate or deactivate; deactivation is refused while the House has current members. */
    public function setStatus(School $school, User $actor, string $houseId, string $status, int $expectedVersion): StudentGroup
    {
        $this->authorizeCapabilityFor($actor, self::MANAGE, $school);
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose active or inactive.']);
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $houseId, $status, $expectedVersion): StudentGroup {
            // FOR UPDATE: an assignment holds the House FOR SHARE, so a
            // deactivation waits for it and then sees its new member.
            $house = $this->locked($school, $houseId);
            if ($house->version !== $expectedVersion) {
                throw new StudentHouseChangedException;
            }
            if ($house->status === $status) {
                return $house;
            }
            if ($status === 'inactive' && DB::table('student_group_memberships')->where('student_group_id', $house->id)->whereNull('ends_on')->exists()) {
                throw new HouseRefusedException('HOUSE_HAS_MEMBERS');
            }
            $house->forceFill(['status' => $status, 'version' => $house->version + 1])->save();
            $this->audit->school($school, $status === 'inactive' ? 'student_group.deactivated' : 'student_group.reactivated', actor: $actor, subject: $house, metadata: [
                'studentGroupId' => $house->id,
            ]);

            return $house;
        }));
    }

    private function locked(School $school, string $houseId): StudentGroup
    {
        return StudentGroup::query()->where('school_id', $school->id)->where('group_type', StudentGroupType::HOUSE->value)
            ->lockForUpdate()->findOrFail($houseId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, bool $withVersion): array
    {
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = CodeNormalizer::normalize($input['code']);
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim((string) preg_replace('/\s+/u', ' ', $input['name']));
        }

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:80', 'regex:/^[^\p{Cc}]+$/u'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
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
    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'student_groups_school_type_name_unique') ? 'name' : 'code';
            throw ValidationException::withMessages([$field => $field === 'name' ? 'Another House already has this name.' : 'Another House already has this code.']);
        }
    }
}
