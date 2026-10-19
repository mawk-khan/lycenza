<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SWC.2 (ADR 0073 section 4.2): Room create/update, moved from the API
 * controller so the web pages and `/api/v1` share it. A Room belongs to one
 * Campus of the same School (composite foreign key, rule 70); its code is
 * unique per Campus; deactivation is reversible `status` (rule 73).
 */
class RoomAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.structure.manage';

    /** The closed room_type catalog (Phase 0D). */
    public const array ROOM_TYPES = ['classroom', 'laboratory', 'auditorium', 'library', 'sports', 'other'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ActiveCampus $activeCampus,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, string $campusId, array $input, User $actor): Room
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $campusId, $input, $actor): Room {
            // Another School's Campus is invisible under SchoolScope: the same 404 as a missing one.
            $campus = Campus::query()->findOrFail($campusId);
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:32', Rule::unique('rooms')->where('school_id', $school->id)->where('campus_id', $campus->id)],
                'room_type' => ['sometimes', Rule::in(self::ROOM_TYPES)],
                'capacity' => ['nullable', 'integer', 'min:1'],
            ];
            $validated = $this->validateInput($input, $rules);

            return $this->writeUnique(fn (): Room => DB::transaction(function () use ($school, $campus, $validated, $actor): Room {
                // ORG.7D-1 D1-1 (ADR 0076 §37): no new Room on an inactive Campus.
                $this->activeCampus->require($school, $campus->id);

                $room = Room::query()->create([...$validated, 'school_id' => $school->id, 'campus_id' => $campus->id]);

                $this->audit->school($school, 'room.created', actor: $actor, subject: $room, metadata: [
                    'name' => $room->name,
                    'code' => $room->code,
                    'campusId' => $campus->id,
                ]);

                return $room;
            }), fn () => $this->validateInput($input, $rules));
        });
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $roomId, array $input, User $actor): Room
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $roomId, $input, $actor): Room {
            $room = Room::query()->findOrFail($roomId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('rooms')->where('school_id', $school->id)->where('campus_id', $room->campus_id)->ignore($room->id)],
                'room_type' => ['sometimes', Rule::in(self::ROOM_TYPES)],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'status' => ['sometimes', 'in:active,inactive'],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $room->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $room, $validated, $before, $actor): void {
                $room->update($validated);

                $this->audit->school($school, 'room.updated', actor: $actor, subject: $room, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $room->refresh();
        });
    }
}
