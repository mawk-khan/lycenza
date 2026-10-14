<?php

namespace App\Domain\Campuses\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\Campuses\Events\CampusCreated;
use App\Domain\Campuses\Events\CampusUpdated;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SWC.2 (ADR 0073 section 4.2): Campus create/update -- moved unchanged from
 * Api\V1\CampusController (Phase 0D sections 9-10, 52) so the staff web
 * pages and the API share one implementation. Deactivation is `status`
 * `inactive` through update(), reversible, never a delete (rule 73).
 */
class CampusAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'school.campuses.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, array $input, User $actor): Campus
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
        $validated = $this->validateInput($input, $rules);

        return $this->context->withSchool($school, fn (): Campus => $this->writeUnique(
            fn (): Campus => DB::transaction(function () use ($school, $validated, $actor): Campus {
                $campus = Campus::query()->create([...$validated, 'school_id' => $school->id]);

                $this->audit->school($school, 'campus.created', actor: $actor, subject: $campus, metadata: [
                    'name' => $campus->name,
                    'code' => $campus->code,
                ]);

                event(new CampusCreated($school->id, $campus->id, $campus->name, $campus->code));

                return $campus;
            }),
            fn () => $this->validateInput($input, $rules),
        ));
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $campusId, array $input, User $actor): Campus
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $campusId, $input, $actor): Campus {
            $campus = Campus::query()->findOrFail($campusId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)->ignore($campus->id)],
                'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'status' => ['sometimes', 'in:active,inactive'],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $campus->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $campus, $validated, $before, $actor): void {
                $campus->update($validated);

                $this->audit->school($school, 'campus.updated', actor: $actor, subject: $campus, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);

                event(new CampusUpdated($school->id, $campus->id, array_keys($validated)));
            }), fn () => $this->validateInput($input, $rules));

            return $campus->refresh();
        });
    }
}
