<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Exceptions\SubjectOfferingClassificationLockedException;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0069 (S1) -- the ONE update path for a SubjectOffering's mutable fields
 * (`is_required`, `sequence`, `weekly_periods_target`, `status`).
 *
 * The required/elective classification is frozen once the Offering has any
 * dependent academic evidence. The rule lives in the database
 * (`subject_offering_classification_freeze`, migration
 * 2026_12_09_090000): only it can see every dependent table without
 * Academic Structure depending on the modules that depend on it (CLAUDE.md
 * rule 4), and only it is race-free against the first evidence row (each
 * evidence insert holds the Offering FOR SHARE). This service takes the
 * Offering FOR UPDATE, writes, and translates the database refusal into a
 * domain error -- it never pre-checks dependent tables itself.
 *
 * A no-op classification (the same value) is not a change. Other fields stay
 * editable whatever the evidence. Audited as `subject_offering.updated`
 * (before/after of the submitted fields), unchanged.
 */
class SubjectOfferingService
{
    use AuthorizesCapability;

    public const string CAPABILITY_MANAGE = 'academics.subjects.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{is_required?: bool, sequence?: int|null, weekly_periods_target?: int|null, status?: string}  $attributes
     */
    public function update(School $school, string $subjectOfferingId, array $attributes, User $actor): SubjectOffering
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, fn (): SubjectOffering => DB::transaction(function () use ($school, $subjectOfferingId, $attributes, $actor): SubjectOffering {
            $offering = SubjectOffering::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($subjectOfferingId);
            $before = $offering->only(array_keys($attributes));

            try {
                $offering->update($attributes);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'subject_offering_classification_locked')) {
                    throw new SubjectOfferingClassificationLockedException;
                }
                throw $e;
            }

            $this->audit->school($school, 'subject_offering.updated', actor: $actor, subject: $offering, metadata: [
                'before' => $before,
                'after' => $attributes,
            ]);

            return $offering->refresh();
        }));
    }
}
