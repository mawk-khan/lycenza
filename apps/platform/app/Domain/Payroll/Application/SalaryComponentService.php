<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.2 -- the sole write path for `salary_components` (semantic
 * component identity only -- no monetary value, ADR 0032). A simple,
 * direct create-then-audit block is appropriate here (rule 76's
 * "genuinely simple entity" line): no multi-step invariant beyond the
 * database's own type/status CHECK constraints exists yet for this
 * table. `liabilityLedgerAccountId` is validated as same-School purely
 * by the composite FK (`salary_components_liability_ledger_account_id_school_id_currenc`)
 * -- never trusted from caller input beyond that.
 *
 * Capability gating (`payroll.structures.manage`) is deliberately
 * deferred to Checkpoint 9.7, exactly as the accepted Phase 9
 * checkpoint plan scopes it -- this class takes an explicit `User
 * $actor` now (for audit attribution) but does not yet call
 * `AuthorizesCapability` since no `payroll.*` capability is registered
 * in the catalog until 9.7 adds it.
 */
class SalaryComponentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(School $school, string $code, string $name, string $type, ?string $liabilityLedgerAccountId, User $actor): SalaryComponent
    {
        return $this->context->withSchool($school, function () use ($school, $code, $name, $type, $liabilityLedgerAccountId, $actor) {
            return DB::transaction(function () use ($school, $code, $name, $type, $liabilityLedgerAccountId, $actor) {
                $component = SalaryComponent::query()->create([
                    'school_id' => $school->id,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'liability_ledger_account_id' => $liabilityLedgerAccountId,
                    'currency' => 'INR',
                    'status' => 'active',
                ]);

                $this->audit->school($school, 'payroll.component.created', actor: $actor, subject: $component, metadata: [
                    'code' => $component->code,
                    'type' => $component->type,
                ]);

                return $component;
            });
        });
    }

    public function deactivate(SalaryComponent $component, User $actor): SalaryComponent
    {
        $school = $component->school;

        return $this->context->withSchool($school, function () use ($school, $component, $actor) {
            return DB::transaction(function () use ($school, $component, $actor) {
                $component->update(['status' => 'inactive']);

                $this->audit->school($school, 'payroll.component.deactivated', actor: $actor, subject: $component);

                return $component->fresh();
            });
        });
    }
}
