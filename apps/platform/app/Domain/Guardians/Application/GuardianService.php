<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Application\Exceptions\GuardianProfileChangedException;
use App\Domain\Guardians\Application\Exceptions\InvalidGuardianStatusException;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only sanctioned write path for Guardian identity (Phase 1A.4) --
 * mirrors App\Domain\Students\Application\StudentService exactly.
 * Guardian *contact* information (email/mobile) is a completely
 * separate concern owned by App\Domain\Guardians\Application\
 * GuardianContactService (Phase 1A.3) -- this service never touches
 * `guardian_contacts` or duplicates any encryption/lookup-hash logic; a
 * caller composes both services independently (see
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md "Guardian service").
 *
 * Deliberately authorization-neutral, matching every other Application-
 * layer service in this codebase -- see
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Authorization boundary").
 *
 * Audit metadata never carries a Guardian's name (Sensitive personal
 * data) -- only changed field names and non-PII status values, matching
 * StudentService and GuardianContactService's audit design.
 */
class GuardianService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): Guardian
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $attributes, $actor) {
            // SIS.EXP-1: school_id and status are not mass assignable;
            // this trusted creation path sets them explicitly.
            $guardian = Guardian::query()->forceCreate([
                'school_id' => $school->id,
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'status' => 'active',
            ]);

            $this->audit->school($school, 'guardian.created', actor: $actor, subject: $guardian);

            return $guardian;
        }));
    }

    /** The identity fields update() writes (SIS.HARDEN-1: an explicit whitelist). */
    public const PROFILE_FIELDS = ['first_name', 'middle_name', 'last_name'];

    /**
     * SIS.HARDEN-1 (SIS.EXP-R1 finding R-1), the StudentService::update()
     * contract:
     * - only PROFILE_FIELDS are read, in canonical form (trimmed; an empty
     *   middle or last name is null), so an equivalent submission is no change;
     * - the Guardian row is locked FOR NO KEY UPDATE and, with an $actor,
     *   whole-School `guardians.manage` is re-read fresh under the lock;
     * - with $expectedVersion (GuardianProfileVersion), a form opened before
     *   another change is refused (GuardianProfileChangedException) and
     *   nothing is written -- the web form always sends it; the API may;
     * - `changedFields` names only the fields whose stored value changed; an
     *   unchanged save writes and audits nothing.
     *
     * @param  array{first_name?: string, middle_name?: string|null, last_name?: string|null}  $attributes
     */
    public function update(Guardian $guardian, array $attributes, ?User $actor = null, ?string $expectedVersion = null): Guardian
    {
        $attributes = self::canonical(array_intersect_key($attributes, array_flip(self::PROFILE_FIELDS)));

        return $this->context->withSchool($guardian->school, fn () => DB::transaction(function () use ($guardian, $attributes, $actor, $expectedVersion) {
            $locked = Guardian::query()->whereKey($guardian->id)->lock('for no key update')->firstOrFail();
            if ($actor !== null && ! $this->capabilities->holdsInSchoolNow($actor, 'guardians.manage', $guardian->school)) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            if ($expectedVersion !== null && ! GuardianProfileVersion::matches($locked, $expectedVersion)) {
                throw new GuardianProfileChangedException;
            }

            $locked->fill($attributes);
            $changed = array_values(array_intersect(self::PROFILE_FIELDS, array_keys($locked->getDirty())));
            if ($changed === []) {
                return $locked;
            }
            $locked->save();

            $this->audit->school($guardian->school, 'guardian.updated', actor: $actor, subject: $locked, metadata: [
                'changedFields' => $changed,
            ]);

            return $locked->refresh();
        }));
    }

    /**
     * @param  array<string, string|null>  $attributes
     * @return array<string, string|null>
     */
    private static function canonical(array $attributes): array
    {
        foreach ($attributes as $field => $value) {
            $value = $value === null ? null : Str::trim($value);
            $attributes[$field] = $field !== 'first_name' && $value === '' ? null : $value;
        }

        return $attributes;
    }

    public function changeStatus(Guardian $guardian, string $status, ?User $actor = null): Guardian
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidGuardianStatusException($status);
        }

        return $this->context->withSchool($guardian->school, fn () => DB::transaction(function () use ($guardian, $status, $actor) {
            $previousStatus = $guardian->status;
            $guardian->forceFill(['status' => $status])->save();

            $this->audit->school($guardian->school, 'guardian.status_changed', actor: $actor, subject: $guardian, metadata: [
                'previousStatus' => $previousStatus,
                'newStatus' => $status,
            ]);

            return $guardian->refresh();
        }));
    }
}
