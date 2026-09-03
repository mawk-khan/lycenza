<?php

namespace App\Domain\Examinations\Application;

use App\Domain\Examinations\Application\Exceptions\DuplicateGradeBandThresholdException;
use App\Domain\Examinations\Application\Exceptions\DuplicateGradeScaleCodeException;
use App\Domain\Examinations\Application\Exceptions\GradeBandNotMutableException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIllegalTransitionException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIncompleteException;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.4C -- the ONE sanctioned write path for `grade_scales` and
 * `grade_bands` (ADR 0035). Neither controller writes either model
 * directly (proven by
 * Tests\Feature\Examinations\GradeScaleArchitectureGuardTest).
 *
 * LIFECYCLE. Exactly three legal transitions: draft->active,
 * active->inactive, inactive->active. Every other transition,
 * including every no-op, is illegal -- `assertLegalTransition()` below
 * is the single, uniform check for all nine (status, status) pairs, so
 * no separate no-op branch is needed: the legal-targets map for each
 * status never contains that same status.
 *
 * COMPLETENESS. A scale may only become `active` if it has a GradeBand
 * at `min_percentage = 0.00` -- because GradeBands store only a
 * lower-bound threshold, this single check is both the coverage AND
 * the gap-freedom invariant; no separate gap algorithm exists.
 *
 * BAND IMMUTABILITY. GradeBands may be created, updated, or deleted
 * ONLY while the parent's status is `draft`. Once a scale has ever
 * been `active`, its bands are frozen forever, including while later
 * `inactive`.
 *
 * CONCURRENCY. Every mutating operation against an EXISTING GradeScale
 * reloads it with `lockForUpdate()` inside `DB::transaction()` BEFORE
 * checking status or mutating anything -- a genuine PostgreSQL row
 * lock scoped to exactly one GradeScale aggregate, never a School-wide
 * `TenantLock` (which would needlessly serialize unrelated GradeScales
 * against each other) and never an advisory lock. This is the same
 * mechanism `CurriculumDeliveryService::transition()` already uses for
 * its own aggregate-local CAS. No database trigger backstops this --
 * the row-lock protocol, this service's sole-write-path status, and
 * the architecture guard together are the sanctioned protection (ADR
 * 0035).
 *
 * DUPLICATE TRANSLATION is constraint-specific in both directions:
 * `grade_scales_school_id_code_ci_unique` -> DuplicateGradeScaleCodeException,
 * `grade_bands_min_percentage_unique` -> DuplicateGradeBandThresholdException.
 * Any other unique violation is rethrown, never mislabelled.
 */
class GradeScaleService
{
    /**
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        GradeScale::STATUS_DRAFT => [GradeScale::STATUS_ACTIVE],
        GradeScale::STATUS_ACTIVE => [GradeScale::STATUS_INACTIVE],
        GradeScale::STATUS_INACTIVE => [GradeScale::STATUS_ACTIVE],
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{code: string, name: string, bands?: list<array{min_percentage: string, label: string}>}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): GradeScale
    {
        return $this->context->withSchool($school, function () use ($school, $attributes, $actor) {
            return DB::transaction(function () use ($school, $attributes, $actor) {
                $scale = new GradeScale;
                $scale->forceFill([
                    'school_id' => $school->id,
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'status' => GradeScale::STATUS_DRAFT,
                ]);

                $this->saveScale($scale, $attributes['code']);

                foreach ($attributes['bands'] ?? [] as $bandAttributes) {
                    $band = new GradeBand;
                    $band->forceFill([
                        'school_id' => $school->id,
                        'grade_scale_id' => $scale->id,
                        'min_percentage' => $bandAttributes['min_percentage'],
                        'label' => $bandAttributes['label'],
                    ]);
                    $this->saveBand($band);
                }

                $this->audit->school($school, 'examinations.grade_scale.created', actor: $actor, subject: $scale, metadata: [
                    'scaleId' => $scale->id,
                    'code' => $scale->code,
                    'status' => $scale->status,
                ]);

                return $scale->refresh()->load('bands');
            });
        });
    }

    /**
     * Updates name and/or requests a lifecycle transition. `status`,
     * when present, is ALWAYS interpreted as a guarded transition
     * request -- never a raw field write -- validated against
     * `LEGAL_TRANSITIONS` under the parent-row lock before anything is
     * mutated.
     *
     * @param  array{name?: string, status?: string}  $attributes
     */
    public function update(School $school, GradeScale $scale, array $attributes, User $actor): GradeScale
    {
        return $this->context->withSchool($school, function () use ($school, $scale, $attributes, $actor) {
            return DB::transaction(function () use ($school, $scale, $attributes, $actor) {
                $locked = GradeScale::query()->whereKey($scale->id)->lockForUpdate()->firstOrFail();

                $changedFields = [];
                $priorStatus = $locked->status;
                $activated = false;

                if (array_key_exists('name', $attributes)) {
                    $locked->forceFill(['name' => $attributes['name']]);
                    $changedFields[] = 'name';
                }

                if (array_key_exists('status', $attributes)) {
                    $requestedStatus = $attributes['status'];
                    $this->assertLegalTransition($locked->status, $requestedStatus);

                    if ($requestedStatus === GradeScale::STATUS_ACTIVE) {
                        $this->assertComplete($locked);
                        $activated = true;
                    }

                    $locked->forceFill(['status' => $requestedStatus]);
                    $changedFields[] = 'status';
                }

                $locked->save();

                if ($activated) {
                    // Fires for BOTH draft->active (first activation)
                    // and inactive->active (reactivation) -- the two
                    // are distinguished by `priorStatus`, avoiding a
                    // separate `.reactivated` action.
                    $this->audit->school($school, 'examinations.grade_scale.activated', actor: $actor, subject: $locked, metadata: [
                        'scaleId' => $locked->id,
                        'priorStatus' => $priorStatus,
                    ]);
                } elseif ($changedFields !== []) {
                    // Deactivation (active->inactive) folds in here
                    // too, exactly like ordinary name corrections --
                    // status VALUES are bounded administrative facts
                    // and safe to audit; name/label VALUES never are.
                    $this->audit->school($school, 'examinations.grade_scale.updated', actor: $actor, subject: $locked, metadata: [
                        'scaleId' => $locked->id,
                        'changedFields' => $changedFields,
                        'before' => ['status' => $priorStatus],
                        'after' => ['status' => $locked->status],
                    ]);
                }

                return $locked->refresh();
            });
        });
    }

    /**
     * @param  array{min_percentage: string, label: string}  $attributes
     */
    public function addBand(School $school, GradeScale $scale, array $attributes, User $actor): GradeBand
    {
        return $this->context->withSchool($school, function () use ($school, $scale, $attributes, $actor) {
            return DB::transaction(function () use ($school, $scale, $attributes, $actor) {
                $locked = GradeScale::query()->whereKey($scale->id)->lockForUpdate()->firstOrFail();
                $this->assertDraft($locked);

                $band = new GradeBand;
                $band->forceFill([
                    'school_id' => $school->id,
                    'grade_scale_id' => $locked->id,
                    'min_percentage' => $attributes['min_percentage'],
                    'label' => $attributes['label'],
                ]);
                $this->saveBand($band);

                $this->audit->school($school, 'examinations.grade_scale.updated', actor: $actor, subject: $locked, metadata: [
                    'scaleId' => $locked->id,
                    'bandId' => $band->id,
                    'changedFields' => ['bands'],
                ]);

                return $band;
            });
        });
    }

    /**
     * @param  array{min_percentage?: string, label?: string}  $attributes
     */
    public function updateBand(School $school, GradeScale $scale, GradeBand $band, array $attributes, User $actor): GradeBand
    {
        return $this->context->withSchool($school, function () use ($school, $scale, $band, $attributes, $actor) {
            return DB::transaction(function () use ($school, $scale, $band, $attributes, $actor) {
                $locked = GradeScale::query()->whereKey($scale->id)->lockForUpdate()->firstOrFail();
                $this->assertDraft($locked);

                $changedFields = [];
                if (array_key_exists('min_percentage', $attributes)) {
                    $changedFields[] = 'minPercentage';
                }
                if (array_key_exists('label', $attributes)) {
                    $changedFields[] = 'label';
                }

                $band->forceFill(array_intersect_key($attributes, array_flip(['min_percentage', 'label'])));
                $this->saveBand($band);

                // `minPercentage` is a bounded numeric administrative
                // fact and safe to record; `label` is School-authored
                // content and is named in `changedFields` only, never
                // by value -- mirroring the published Examination
                // `name`-exclusion rule.
                $this->audit->school($school, 'examinations.grade_scale.updated', actor: $actor, subject: $locked, metadata: [
                    'scaleId' => $locked->id,
                    'bandId' => $band->id,
                    'changedFields' => $changedFields,
                ]);

                return $band->refresh();
            });
        });
    }

    public function removeBand(School $school, GradeScale $scale, GradeBand $band, User $actor): void
    {
        $this->context->withSchool($school, function () use ($school, $scale, $band, $actor) {
            DB::transaction(function () use ($school, $scale, $band, $actor) {
                $locked = GradeScale::query()->whereKey($scale->id)->lockForUpdate()->firstOrFail();
                $this->assertDraft($locked);

                $bandId = $band->id;
                $band->delete();

                $this->audit->school($school, 'examinations.grade_scale.updated', actor: $actor, subject: $locked, metadata: [
                    'scaleId' => $locked->id,
                    'bandId' => $bandId,
                    'changedFields' => ['bands'],
                ]);
            });
        });
    }

    private function assertLegalTransition(string $from, string $to): void
    {
        if (! in_array($to, self::LEGAL_TRANSITIONS[$from] ?? [], true)) {
            throw new GradeScaleIllegalTransitionException($from, $to);
        }
    }

    /**
     * Completeness == coverage == gap-freedom, all reduced to one
     * check, because GradeBands store only a lower-bound threshold.
     */
    private function assertComplete(GradeScale $scale): void
    {
        $hasFloor = GradeBand::query()
            ->where('grade_scale_id', $scale->id)
            ->where('min_percentage', '0.00')
            ->exists();

        if (! $hasFloor) {
            throw new GradeScaleIncompleteException($scale->id);
        }
    }

    private function assertDraft(GradeScale $scale): void
    {
        if (! $scale->isDraft()) {
            throw new GradeBandNotMutableException($scale->id, $scale->status);
        }
    }

    private function saveScale(GradeScale $scale, string $code): void
    {
        try {
            $scale->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'grade_scales_school_id_code_ci_unique')) {
                throw $e;
            }

            throw new DuplicateGradeScaleCodeException($code);
        }
    }

    private function saveBand(GradeBand $band): void
    {
        try {
            $band->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'grade_bands_min_percentage_unique')) {
                throw $e;
            }

            throw new DuplicateGradeBandThresholdException((string) $band->min_percentage);
        }
    }
}
