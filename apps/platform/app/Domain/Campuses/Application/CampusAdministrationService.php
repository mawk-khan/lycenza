<?php

namespace App\Domain\Campuses\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\Campuses\Events\CampusCreated;
use App\Domain\Campuses\Events\CampusUpdated;
use App\Domain\Campuses\Infrastructure\CampusServiceOffering;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\InstitutionalProfileRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SWC.2 (ADR 0073 section 4.2): Campus create/update -- moved unchanged from
 * Api\V1\CampusController (Phase 0D sections 9-10, 52) so the staff web
 * pages and the API share one implementation. Deactivation is `status`
 * `inactive` through update(), reversible, never a delete (rule 73).
 *
 * ORG.12C (ADR 0080 §8): the rich Campus profile -- structured address,
 * contacts (InstitutionalProfileRules), optional coordinates (both or
 * neither) and the current service offerings (CampusServiceType). Services
 * are descriptive: nothing here reads or writes a module entitlement,
 * capability or any other module's records. Whole-School
 * `school.campuses.manage` only (not Campus-adopted). Audit metadata names
 * the changed fields; contact, address and coordinate VALUES are never
 * copied into it.
 */
class CampusAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'school.campuses.manage';

    /** Fields whose values are identifiers, safe to keep in audit metadata. */
    private const array AUDITED_VALUES = ['name', 'code', 'status'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, array $input, User $actor): Campus
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $input = InstitutionalProfileRules::normalize($input);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)],
            ...self::profileRules('nullable', null),
        ];
        $validated = $this->validateInput($input, $rules);
        [$fields, $services] = self::split($validated);

        return $this->context->withSchool($school, fn (): Campus => $this->writeUnique(
            fn (): Campus => DB::transaction(function () use ($school, $fields, $services, $actor): Campus {
                $campus = Campus::query()->create([...$fields, 'school_id' => $school->id]);
                $added = $services === null ? [] : $this->syncServices($school, $campus, $services, $actor)[0];

                $this->audit->school($school, 'campus.created', actor: $actor, subject: $campus, metadata: [
                    'name' => $campus->name,
                    'code' => $campus->code,
                    'profile_fields' => array_values(array_diff(array_keys(array_filter($fields, fn ($v) => $v !== null)), ['name', 'code'])),
                    'services' => $added,
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
        $input = InstitutionalProfileRules::normalize($input);

        return $this->context->withSchool($school, function () use ($school, $campusId, $input, $actor): Campus {
            $campus = Campus::query()->findOrFail($campusId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)->ignore($campus->id)],
                'status' => ['sometimes', 'in:active,inactive'],
                ...self::profileRules('sometimes', $campus->phone),
            ];
            $validated = $this->validateInput($input, $rules);
            [$fields, $services] = self::split($validated);

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $campus, $fields, $services, $actor): void {
                // FOR NO KEY UPDATE: serializes profile/service edits of this
                // Campus without blocking FK inserts that key-share it.
                Campus::query()->whereKey($campus->id)->lock('for no key update')->first();
                $campus->fill($fields);
                $changed = array_keys($campus->getDirty());
                $before = array_intersect_key($campus->getOriginal(), array_flip(array_intersect($changed, self::AUDITED_VALUES)));
                $campus->save();
                [$added, $removed] = $services === null ? [[], []] : $this->syncServices($school, $campus, $services, $actor);

                if ($changed !== []) {
                    $this->audit->school($school, 'campus.updated', actor: $actor, subject: $campus, metadata: [
                        'changed' => $changed,
                        'before' => $before,
                        'after' => $campus->only(array_keys($before)),
                    ]);
                    event(new CampusUpdated($school->id, $campus->id, $changed));
                }
                if ($added !== [] || $removed !== []) {
                    $this->audit->school($school, 'campus.services_changed', actor: $actor, subject: $campus, metadata: [
                        'added' => $added,
                        'removed' => $removed,
                    ]);
                }
            }), fn () => $this->validateInput($input, $rules));

            return $campus->refresh();
        });
    }

    /**
     * Makes the Campus's offered services exactly $codes: inserts the missing
     * ones, deletes the rest. Idempotent -- the same set changes nothing --
     * and the (campus_id, service_code) unique constraint backs it.
     *
     * @param  list<string>  $codes
     * @return array{0: list<string>, 1: list<string>} [added, removed]
     */
    private function syncServices(School $school, Campus $campus, array $codes, User $actor): array
    {
        $current = CampusServiceOffering::query()->where('school_id', $school->id)->where('campus_id', $campus->id)->pluck('service_code')->all();
        $wanted = array_values(array_unique($codes));
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        foreach ($added as $code) {
            CampusServiceOffering::query()->create(['school_id' => $school->id, 'campus_id' => $campus->id, 'service_code' => $code, 'created_by_user_id' => $actor->id]);
        }
        if ($removed !== []) {
            CampusServiceOffering::query()->where('school_id', $school->id)->where('campus_id', $campus->id)->whereIn('service_code', $removed)->delete();
        }
        sort($added);
        sort($removed);

        return [$added, $removed];
    }

    /** @return array<string, list<mixed>> */
    private static function profileRules(string $presence, ?string $currentPhone): array
    {
        $optional = $presence === 'sometimes' ? ['sometimes'] : [];

        return [
            ...InstitutionalProfileRules::contact($currentPhone, $presence),
            ...InstitutionalProfileRules::address($presence),
            'latitude' => [...$optional, 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => [...$optional, 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'services' => [...$optional, 'nullable', 'array'],
            'services.*' => ['string', 'distinct', Rule::enum(CampusServiceType::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: list<string>|null} [Campus columns, services or null when not submitted]
     */
    private static function split(array $validated): array
    {
        $services = array_key_exists('services', $validated) ? array_values($validated['services'] ?? []) : null;
        unset($validated['services']);

        return [$validated, $services];
    }
}
