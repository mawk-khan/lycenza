<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for HR Position reference data
 * (docs/modules/HR.md "Department and Position strategy"), mirroring
 * App\Domain\HR\Application\DepartmentService's shape. Deliberately
 * has NO method, parameter, or side effect that creates, references,
 * or grants a Role/Capability/MembershipRoleAssignment -- Position is
 * organizational/job data only (docs/modules/HR.md principle 2.4,
 * Position != Authorization Role).
 *
 * Phase 8A.10: every public method requires a real `User $actor` and
 * authorizes `hr.positions.manage` at $school before doing anything
 * else.
 */
class PositionService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): Position
    {
        $this->authorizeCapabilityFor($actor, 'hr.positions.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $attributes, $actor) {
            return DB::transaction(function () use ($school, $attributes, $actor) {
                $position = Position::query()->create([
                    'school_id' => $school->id,
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'description' => $attributes['description'] ?? null,
                    'status' => 'active',
                ]);

                $this->audit->school($school, 'hr.position.created', actor: $actor, subject: $position, metadata: [
                    'code' => $position->code,
                ]);

                return $position;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  name/code/description keys are applied; school_id/status are always stripped below
     */
    public function update(Position $position, array $attributes, User $actor): Position
    {
        $school = $position->school;
        $this->authorizeCapabilityFor($actor, 'hr.positions.manage', $school);

        unset($attributes['school_id'], $attributes['status']);

        return $this->context->withSchool($school, function () use ($school, $position, $attributes, $actor) {
            return DB::transaction(function () use ($school, $position, $attributes, $actor) {
                $position->update($attributes);

                $this->audit->school($school, 'hr.position.updated', actor: $actor, subject: $position, metadata: [
                    'fields' => array_keys($attributes),
                ]);

                return $position->fresh();
            });
        });
    }

    public function archive(Position $position, User $actor): Position
    {
        $this->authorizeCapabilityFor($actor, 'hr.positions.manage', $position->school);

        return $this->setStatus($position, 'inactive', 'hr.position.archived', $actor);
    }

    public function reactivate(Position $position, User $actor): Position
    {
        $this->authorizeCapabilityFor($actor, 'hr.positions.manage', $position->school);

        return $this->setStatus($position, 'active', 'hr.position.reactivated', $actor);
    }

    private function setStatus(Position $position, string $status, string $eventType, ?User $actor): Position
    {
        $school = $position->school;

        return $this->context->withSchool($school, function () use ($school, $position, $status, $eventType, $actor) {
            return DB::transaction(function () use ($school, $position, $status, $eventType, $actor) {
                $position->update(['status' => $status]);

                $this->audit->school($school, $eventType, actor: $actor, subject: $position);

                return $position->fresh();
            });
        });
    }
}
