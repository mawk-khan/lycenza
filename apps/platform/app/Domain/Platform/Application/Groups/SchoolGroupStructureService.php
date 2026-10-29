<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\CodeNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ORG.12D (ADR 0080 §9, OD-12.10): the ONE writer of a School Group's
 * optional Regions and Zones -- platform-governed like the rest of the Group
 * layer (ADR 0045 §5, invariant 6: a Group Admin changes nothing), under
 * `platform.school_groups.manage`.
 *
 * - Governance classifications only: no tenant data, no School membership,
 *   no capability, no authorization scope.
 * - Regions and Zones are inactivated, never deleted. Inactivating one moves
 *   no School: existing placements stay and are surfaced as needing
 *   attention.
 * - A code is unique within the Group, upper-case, and frozen once anything
 *   references it (a Zone or a member School).
 * - Audited on the platform ledger with identifiers and codes, never names
 *   (ADR 0045 §11). An unchanged submit writes nothing.
 */
class SchoolGroupStructureService
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
        private readonly SchoolGroupGovernanceService $groups,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function createRegion(User $actor, SchoolGroup $group, array $input): SchoolGroupRegion
    {
        $this->authorize($actor, $group);
        $data = $this->validated($input, $group, 'region', creating: true);

        return $this->unique(fn (): SchoolGroupRegion => DB::transaction(function () use ($actor, $group, $data): SchoolGroupRegion {
            $this->groups->requireActiveLocked($group);
            $region = SchoolGroupRegion::query()->create([...$data, 'school_group_id' => $group->id]);
            $this->audit->platform('platform.school_group.region_created', actor: $actor, subject: $group, metadata: ['region_id' => $region->id, 'code' => $region->code]);

            return $region;
        }));
    }

    /** @param  array<string, mixed>  $input */
    public function updateRegion(User $actor, SchoolGroup $group, string $regionId, array $input): SchoolGroupRegion
    {
        $this->authorize($actor, $group);
        $region = SchoolGroupRegion::query()->where('school_group_id', $group->id)->findOrFail($regionId);
        $data = $this->validated($input, $group, 'region', creating: false);

        return $this->unique(fn (): SchoolGroupRegion => DB::transaction(function () use ($actor, $group, $region, $data): SchoolGroupRegion {
            $this->groups->requireActiveLocked($group);
            $region = SchoolGroupRegion::query()->whereKey($region->id)->lockForUpdate()->firstOrFail();
            $this->refuseCodeChangeOnceReferenced($region, $data, fn (): bool => SchoolGroupZone::query()->where('region_id', $region->id)->exists()
                || DB::table('school_group_members')->where('region_id', $region->id)->exists());

            return $this->save($actor, $group, $region, $data, 'platform.school_group.region_updated', 'region_id');
        }));
    }

    /** @param  array<string, mixed>  $input */
    public function createZone(User $actor, SchoolGroup $group, array $input): SchoolGroupZone
    {
        $this->authorize($actor, $group);
        $data = $this->validated($input, $group, 'zone', creating: true);

        return $this->unique(fn (): SchoolGroupZone => DB::transaction(function () use ($actor, $group, $data): SchoolGroupZone {
            $this->groups->requireActiveLocked($group);
            $zone = SchoolGroupZone::query()->create([...$data, 'school_group_id' => $group->id]);
            $this->audit->platform('platform.school_group.zone_created', actor: $actor, subject: $group, metadata: ['zone_id' => $zone->id, 'code' => $zone->code, 'region_id' => $zone->region_id]);

            return $zone;
        }));
    }

    /** @param  array<string, mixed>  $input */
    public function updateZone(User $actor, SchoolGroup $group, string $zoneId, array $input): SchoolGroupZone
    {
        $this->authorize($actor, $group);
        $zone = SchoolGroupZone::query()->where('school_group_id', $group->id)->findOrFail($zoneId);
        $data = $this->validated($input, $group, 'zone', creating: false);

        return $this->unique(fn (): SchoolGroupZone => DB::transaction(function () use ($actor, $group, $zone, $data): SchoolGroupZone {
            $this->groups->requireActiveLocked($group);
            $zone = SchoolGroupZone::query()->whereKey($zone->id)->lockForUpdate()->firstOrFail();
            $referenced = fn (): bool => DB::table('school_group_members')->where('zone_id', $zone->id)->exists();
            $this->refuseCodeChangeOnceReferenced($zone, $data, $referenced);
            if (array_key_exists('region_id', $data) && $data['region_id'] !== $zone->region_id && $referenced()) {
                throw ValidationException::withMessages(['region_id' => 'Move this Zone\'s Schools before changing its Region.']);
            }

            return $this->save($actor, $group, $zone, $data, 'platform.school_group.zone_updated', 'zone_id');
        }));
    }

    private function authorize(User $actor, SchoolGroup $group): void
    {
        if (! $this->capabilities->canPlatform($actor, SchoolGroupGovernanceService::MANAGE)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        if (! $group->isActive()) {
            throw ValidationException::withMessages(['group' => 'An archived Group cannot be changed.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, SchoolGroup $group, string $kind, bool $creating): array
    {
        if (array_key_exists('code', $input) && is_string($input['code'])) {
            $input['code'] = CodeNormalizer::normalize($input['code']);
        }
        foreach (['description', 'region_id'] as $nullable) {
            if (array_key_exists($nullable, $input) && is_string($input[$nullable]) && trim($input[$nullable]) === '') {
                $input[$nullable] = null;
            }
        }
        $presence = $creating ? ['required'] : ['sometimes', 'required'];
        $rules = [
            'code' => [...$presence, 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,31}$/'],
            'name' => [...$presence, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::in([SchoolGroupRegion::STATUS_ACTIVE, SchoolGroupRegion::STATUS_INACTIVE])],
        ];
        if ($kind === 'zone') {
            // A Region of THIS Group only (also the composite key).
            $rules['region_id'] = ['sometimes', 'nullable', 'uuid', Rule::exists('school_group_regions', 'id')->where('school_group_id', $group->id)];
        }

        return Validator::make($input, $rules, ['code.regex' => 'Use up to 32 letters, digits, hyphens or underscores.'])->validate();
    }

    /** @param  array<string, mixed>  $data */
    private function refuseCodeChangeOnceReferenced(Model $structure, array $data, callable $referenced): void
    {
        if (array_key_exists('code', $data) && $data['code'] !== $structure->getAttribute('code') && $referenced()) {
            throw ValidationException::withMessages(['code' => 'The code is fixed once Schools or Zones use it.']);
        }
    }

    /**
     * @template T of SchoolGroupRegion|SchoolGroupZone
     *
     * @param  T  $structure
     * @param  array<string, mixed>  $data
     * @return T
     */
    private function save(User $actor, SchoolGroup $group, Model $structure, array $data, string $event, string $idKey): Model
    {
        $structure->fill(array_diff_key($data, ['status' => true]));
        if (array_key_exists('status', $data)) {
            $structure->forceFill(['status' => $data['status']]);
        }
        $changed = array_keys($structure->getDirty());
        if ($changed === []) {
            return $structure;
        }
        $before = array_intersect_key($structure->getOriginal(), array_flip(array_intersect($changed, ['code', 'status', 'region_id'])));
        $structure->save();
        $this->audit->platform($event, actor: $actor, subject: $group, metadata: [
            $idKey => $structure->getKey(),
            'changed' => $changed,
            'before' => $before,
            'after' => $structure->only(array_keys($before)),
        ]);

        return $structure;
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
            throw ValidationException::withMessages(['code' => 'That code is already used in this Group.']);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'move the Zone')) {
                throw ValidationException::withMessages(['region_id' => 'Move this Zone\'s Schools before changing its Region.']);
            }
            throw $e;
        }
    }
}
