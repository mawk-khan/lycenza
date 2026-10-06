<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Application\Exceptions\AdmissionFeeHeadNotSelectableException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeHead;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * OPF.3 (ADR 0067 §16, D1): Admissions' side of the Operational Fee
 * Integration -- the ONLY writer of `admission_fee_heads` and
 * `admission_fee_selections`.
 *
 * - **Only after conversion (D1).** A successful conversion records the
 *   converted STUDENT's one-time Admission-fee intent, inside the
 *   conversion transaction (AdmissionConversionService::convert()). Nothing
 *   here ever names an applicant or an unconverted application as a
 *   financial subject, and nothing is accepted or charged before
 *   conversion. A pre-conversion application fee stays deferred.
 * - **Intent, never money.** It goes through FEE's trusted source seam
 *   (FeeSourceSelectionService) -- the same seam Transport and Hostel use.
 *   Nothing here assesses, cancels or alters a charge; only FEE assessment
 *   runs (`finance.fee_assessments.run`) charge the line's instalment.
 * - **Amounts stay in FEE (D7).** The School maps one fee head; FEE's
 *   structure for the application's year, grade and campus owns the line
 *   and its (one-time) instalment.
 * - **Academic year.** The application's own academic year -- the year of
 *   the enrollment the conversion just created. FEE resolves the line from
 *   that enrollment.
 * - **One-time, no lifecycle.** No withdrawal, no carry-forward: later
 *   application or Student changes never rewrite this evidence; corrections
 *   are explicit Finance actions.
 * - **Exactly once.** One provenance row per application
 *   (`admission_fee_selections_one_per_application`) plus FEE's
 *   one-active-selection key, behind the application row lock the
 *   conversion already holds.
 * - **Authorization** is the caller's `admissions.manage` (checked by the
 *   controllers); this grants no Finance capability (D9).
 */
class AdmissionFeeSelectionService
{
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public function __construct(
        private readonly FeeSourceSelectionService $fees,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Called by AdmissionConversionService::convert() inside its
     * transaction, after the application is marked converted. Idempotent: a
     * repeated call for the same application records nothing new.
     *
     * @return 'linked'|'already_linked'|'not_applicable'
     */
    public function recordForConversion(AdmissionApplication $application, ?User $actor): string
    {
        $school = $application->school;

        return $this->context->withSchool($school, fn (): string => DB::transaction(function () use ($school, $application, $actor): string {
            $locked = AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'converted' || $locked->converted_student_id === null) {
                throw new LogicException('Only a converted admission application carries Admission-fee intent (ADR 0067 D1).');
            }

            if (AdmissionFeeSelection::query()->where('admission_application_id', $locked->id)->exists()) {
                return 'already_linked';
            }

            $mapping = AdmissionFeeHead::query()->first();
            if ($mapping === null) {
                $this->notApplicable($school, $locked, null, self::REASON_NOT_CONFIGURED, $actor);

                return 'not_applicable';
            }

            $result = $this->fees->selectForSource($school, $locked->converted_student_id, $locked->academic_year_id, $mapping->fee_head_id, FeeSelectionSource::admissions($locked->id), $actor);
            if (! $result->hasSelection()) {
                $this->notApplicable($school, $locked, $mapping->fee_head_id, (string) $result->reason, $actor);

                return 'not_applicable';
            }

            try {
                // A savepoint: a concurrent identical link must not abort the caller's transaction.
                $link = DB::transaction(fn () => AdmissionFeeSelection::query()->create([
                    'school_id' => $school->id,
                    'admission_application_id' => $locked->id,
                    'student_id' => $locked->converted_student_id,
                    'academic_year_id' => $locked->academic_year_id,
                    'fee_head_id' => $mapping->fee_head_id,
                    'fee_optional_selection_id' => $result->selectionId,
                    'selection_outcome' => $result->outcome,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'admission_fee_selections_one_per_application')) {
                    throw $e;
                }

                return 'already_linked';
            }

            $this->audit->school($school, 'admission_fee_selection.linked', actor: $actor, subject: $locked, metadata: [
                'admissionApplicationId' => $locked->id,
                'studentId' => $locked->converted_student_id,
                'academicYearId' => $locked->academic_year_id,
                'feeHeadId' => $mapping->fee_head_id,
                'feeOptionalSelectionId' => $link->fee_optional_selection_id,
                'selectionOutcome' => $result->outcome,
            ]);

            return 'linked';
        }));
    }

    /**
     * D7: maps the School's Admission fee to an active fee head of the
     * School, or clears it (null). Affects only later conversions; recorded
     * intent is never rewritten.
     */
    public function setFeeHead(School $school, ?string $feeHeadId, ?User $actor): ?AdmissionFeeHead
    {
        if ($feeHeadId !== null && $this->fees->selectableFeeHead($school, $feeHeadId) === null) {
            throw new AdmissionFeeHeadNotSelectableException;
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $feeHeadId, $actor) {
            // One mapping per School: concurrent writers serialize on the School's key.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['admissions.fee_head:'.$school->id]);
            $current = AdmissionFeeHead::query()->first();
            $previous = $current?->fee_head_id;

            if ($feeHeadId === null) {
                $current?->delete();
            } elseif ($current === null) {
                $current = AdmissionFeeHead::query()->create(['school_id' => $school->id, 'fee_head_id' => $feeHeadId]);
            } else {
                $current->update(['fee_head_id' => $feeHeadId]);
            }

            if ($previous !== $feeHeadId) {
                $this->audit->school($school, $feeHeadId === null ? 'admission_fee_head.cleared' : 'admission_fee_head.set', actor: $actor, metadata: [
                    'feeHeadId' => $feeHeadId,
                    'previousFeeHeadId' => $previous,
                ]);
            }

            return $feeHeadId === null ? null : $current;
        }));
    }

    /** @return array{feeHeadId: string, code: string, name: string}|null the School's Admission fee head */
    public function feeHead(School $school): ?array
    {
        $mapping = $this->context->withSchool($school, fn () => AdmissionFeeHead::query()->first());
        if ($mapping === null) {
            return null;
        }
        $head = $this->fees->selectableFeeHead($school, $mapping->fee_head_id);

        return [
            'feeHeadId' => $mapping->fee_head_id,
            'code' => $head['code'] ?? '',
            'name' => $head['name'] ?? '',
        ];
    }

    /** A conversion whose Admission-fee intent was not recorded: audited (never fails the conversion). */
    private function notApplicable(School $school, AdmissionApplication $application, ?string $feeHeadId, string $why, ?User $actor): void
    {
        $this->audit->school($school, 'admission_fee_selection.not_applicable', actor: $actor, subject: $application, metadata: [
            'admissionApplicationId' => $application->id,
            'studentId' => $application->converted_student_id,
            'academicYearId' => $application->academic_year_id,
            'feeHeadId' => $feeHeadId,
            'reason' => $why,
        ]);
    }
}
