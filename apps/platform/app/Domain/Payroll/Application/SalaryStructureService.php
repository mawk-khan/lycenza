<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\ConcurrentStructureActivationConflictException;
use App\Domain\Payroll\Application\Exceptions\InvalidStructureTransitionException;
use App\Domain\Payroll\Application\Exceptions\StructureNotDraftException;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.2 -- the sole write path for `salary_structures`/
 * `salary_structure_components` (ADR 0032 "Salary structure revision
 * model"). `activate()` mirrors
 * App\Domain\AcademicStructure\Application\AcademicYearService::activate()'s
 * exact shape: lock whatever is currently active for the same logical
 * key (here `(school_id, code)`, there `school_id` alone), supersede
 * it in the same transaction, conditionally UPDATE the target (so a
 * stale in-memory model that lost a same-row race is caught), and
 * translate the partial-unique-index's genuine-concurrency loser into
 * a clean domain exception. The database triggers
 * (`trg_salary_structures_freeze`,
 * `trg_salary_structure_components_freeze`,
 * `salary_structures_one_active_per_code`) remain the authoritative
 * guarantees regardless of the Application-layer pre-checks below.
 *
 * Capability gating (`payroll.structures.manage`) is deliberately
 * deferred to Checkpoint 9.7 -- see `SalaryComponentService`'s
 * identical note.
 */
class SalaryStructureService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function createDraft(School $school, string $code, string $name, User $actor): SalaryStructure
    {
        return $this->context->withSchool($school, function () use ($school, $code, $name, $actor) {
            return DB::transaction(function () use ($school, $code, $name, $actor) {
                // Postgres rejects `FOR UPDATE` combined with an
                // aggregate function in the same query -- lock the
                // matching rows via a plain SELECT, then compute the
                // next version in PHP from the locked set.
                $maxVersion = SalaryStructure::query()
                    ->where('school_id', $school->id)
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->pluck('version')
                    ->max();

                $nextVersion = ((int) $maxVersion) + 1;

                $structure = SalaryStructure::query()->create([
                    'school_id' => $school->id,
                    'code' => $code,
                    'version' => $nextVersion,
                    'name' => $name,
                    'status' => 'draft',
                ]);

                $this->audit->school($school, 'payroll.structure.draft_created', actor: $actor, subject: $structure, metadata: [
                    'code' => $structure->code,
                    'version' => $structure->version,
                ]);

                return $structure;
            });
        });
    }

    public function addComponent(SalaryStructure $structure, AddStructureComponentData $data, User $actor): SalaryStructureComponent
    {
        $school = $structure->school;

        if (! $structure->isDraft()) {
            throw new StructureNotDraftException($structure->id, $structure->status);
        }

        return $this->context->withSchool($school, function () use ($school, $structure, $data, $actor) {
            return DB::transaction(function () use ($school, $structure, $data, $actor) {
                try {
                    $component = SalaryStructureComponent::query()->create([
                        'school_id' => $school->id,
                        'salary_structure_id' => $structure->id,
                        'salary_component_id' => $data->salaryComponentId,
                        'calculation_type' => $data->calculationType,
                        'base_component_id' => $data->baseComponentId,
                        'rate' => $data->rate,
                        'display_order' => $data->displayOrder,
                    ]);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'is no longer draft')) {
                        throw new StructureNotDraftException($structure->id, $structure->fresh()->status);
                    }

                    throw $e;
                }

                $this->audit->school($school, 'payroll.structure.component_added', actor: $actor, subject: $component, metadata: [
                    'salaryStructureId' => $structure->id,
                    'calculationType' => $component->calculation_type,
                ]);

                return $component;
            });
        });
    }

    public function activate(SalaryStructure $structure, User $actor): SalaryStructure
    {
        $school = $structure->school;

        if (! $structure->isDraft()) {
            throw new InvalidStructureTransitionException($structure->status, 'active');
        }

        return $this->context->withSchool($school, function () use ($school, $structure, $actor) {
            try {
                return DB::transaction(function () use ($school, $structure, $actor) {
                    $previousActive = SalaryStructure::query()
                        ->where('school_id', $school->id)
                        ->where('code', $structure->code)
                        ->where('status', 'active')
                        ->where('id', '!=', $structure->id)
                        ->lockForUpdate()
                        ->first();

                    if ($previousActive !== null) {
                        $previousActive->update(['status' => 'superseded']);
                    }

                    $affected = SalaryStructure::query()
                        ->where('id', $structure->id)
                        ->where('status', 'draft')
                        ->update(['status' => 'active']);

                    if ($affected === 0) {
                        throw new InvalidStructureTransitionException($structure->fresh()->status ?? 'unknown', 'active');
                    }

                    $this->audit->school($school, 'payroll.structure.activated', actor: $actor, subject: $structure, metadata: [
                        'code' => $structure->code,
                        'version' => $structure->version,
                        'previousActiveStructureId' => $previousActive?->id,
                    ]);

                    return $structure->refresh();
                });
            } catch (UniqueConstraintViolationException) {
                throw new ConcurrentStructureActivationConflictException($structure->id);
            }
        });
    }
}
