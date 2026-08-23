<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Application\Exceptions\InvalidGuardianStatusException;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): Guardian
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $attributes, $actor) {
            $guardian = Guardian::query()->create([
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

    /**
     * @param  array{first_name?: string, middle_name?: string|null, last_name?: string|null}  $attributes
     */
    public function update(Guardian $guardian, array $attributes, ?User $actor = null): Guardian
    {
        return $this->context->withSchool($guardian->school, fn () => DB::transaction(function () use ($guardian, $attributes, $actor) {
            $guardian->update($attributes);

            $this->audit->school($guardian->school, 'guardian.updated', actor: $actor, subject: $guardian, metadata: [
                'changedFields' => array_keys($attributes),
            ]);

            return $guardian->refresh();
        }));
    }

    public function changeStatus(Guardian $guardian, string $status, ?User $actor = null): Guardian
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidGuardianStatusException($status);
        }

        return $this->context->withSchool($guardian->school, fn () => DB::transaction(function () use ($guardian, $status, $actor) {
            $previousStatus = $guardian->status;
            $guardian->update(['status' => $status]);

            $this->audit->school($guardian->school, 'guardian.status_changed', actor: $actor, subject: $guardian, metadata: [
                'previousStatus' => $previousStatus,
                'newStatus' => $status,
            ]);

            return $guardian->refresh();
        }));
    }
}
