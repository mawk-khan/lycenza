<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.1): School-configured leave types. No statutory
 * catalogue and no meaning read from a name. `is_paid`, `tracks_balance`
 * and `allows_half_day` are independent, and freeze once the type is used
 * by a policy or the ledger (database trigger). Deactivated, never deleted.
 */
class LeaveTypeService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
    ) {}

    public function create(School $school, string $code, string $name, bool $isPaid, bool $tracksBalance, bool $allowsHalfDay, User $actor): LeaveType
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $code, $name, $isPaid, $tracksBalance, $allowsHalfDay, $actor) {
            $this->guard->requireOperational($school->id);
            try {
                $type = LeaveType::query()->create([
                    'school_id' => $school->id, 'code' => $code, 'name' => trim($name),
                    'is_paid' => $isPaid, 'tracks_balance' => $tracksBalance, 'allows_half_day' => $allowsHalfDay, 'status' => 'active',
                ]);
            } catch (UniqueConstraintViolationException) {
                throw LeaveException::conflict('LEAVE_TYPE_CODE_TAKEN', 'A leave type with this code already exists.');
            }
            $this->audit->school($school, 'leave.type.created', actor: $actor, subject: $type, metadata: $this->rules($type));

            return $type;
        }));
    }

    /** @param  array{name?: string, is_paid?: bool, tracks_balance?: bool, allows_half_day?: bool}  $changes */
    public function update(School $school, string $leaveTypeId, array $changes, User $actor): LeaveType
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $changes, $actor) {
            $this->guard->requireOperational($school->id);
            $type = LeaveType::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($leaveTypeId);
            $before = $this->rules($type);
            $type->fill(array_intersect_key($changes, array_flip(['name', 'is_paid', 'tracks_balance', 'allows_half_day'])));
            if (isset($changes['name'])) {
                $type->name = trim($changes['name']);
            }
            try {
                $type->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'leave_type_in_use') || str_contains($e->getMessage(), 'leave_ledger_entries_type_fk')) {
                    throw LeaveException::conflict('LEAVE_TYPE_IN_USE', 'A leave type in use keeps its paid, balance and half-day rules.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.type.updated', actor: $actor, subject: $type, metadata: ['before' => $before, 'after' => $this->rules($type)]);

            return $type;
        }));
    }

    public function setStatus(School $school, string $leaveTypeId, string $status, User $actor): LeaveType
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        if (! in_array($status, LeaveType::STATUSES, true)) {
            throw LeaveException::invalid('LEAVE_TYPE_STATUS_INVALID', 'A leave type is active or inactive.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $status, $actor) {
            $this->guard->requireOperational($school->id);
            $type = LeaveType::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($leaveTypeId);
            if ($type->status === $status) {
                return $type;
            }
            $type->forceFill(['status' => $status])->save();
            $this->audit->school($school, $status === 'active' ? 'leave.type.activated' : 'leave.type.deactivated', actor: $actor, subject: $type);

            return $type;
        }));
    }

    /** @return array{isPaid: bool, tracksBalance: bool, allowsHalfDay: bool} */
    private function rules(LeaveType $type): array
    {
        return ['isPaid' => $type->is_paid, 'tracksBalance' => $type->tracks_balance, 'allowsHalfDay' => $type->allows_half_day];
    }
}
