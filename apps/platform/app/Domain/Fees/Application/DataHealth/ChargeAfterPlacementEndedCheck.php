<?php

namespace App\Domain\Fees\Application\DataHealth;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Students\Application\StudentEnrollmentFeeTargetReadService;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIS 8.1 (SB-03a, V11 benchmark delta): a live fee assessment whose Charge
 * is not cancelled, for a billing period that starts after the placement it
 * billed ended, while no other placement of the Student in that year covers
 * the period's start (so a transfer never matches). A fee run excludes a
 * placement that has already ended (FEE.2 eligibility), so this is a Charge
 * billed BEFORE a later withdrawal or completion, which by design never
 * cancels Charges (FINANCE.md).
 *
 * Finance truth stays where it is: no amount, balance or total is computed
 * or shown here -- the Charge page shows them. The placement interval comes
 * from Students (StudentEnrollmentFeeTargetReadService), never a read of
 * placements here. One finding per Charge; the finding total is Charges.
 */
class ChargeAfterPlacementEndedCheck implements DataHealthCheck
{
    public function __construct(
        private readonly StudentEnrollmentFeeTargetReadService $targets,
        private readonly StudentEnrollmentReadService $placements,
    ) {}

    public function definition(): D
    {
        return new D(
            code: 'fees.charge_after_placement_ended',
            area: 'Fees',
            title: 'Fees billed for a period after the Student\'s placement ended',
            condition: 'A fee Charge that has not been cancelled is for a billing period starting after the class placement it was billed for ended, and the Student has no other placement covering that period.',
            whyItMatters: 'Ending a placement never cancels fees already billed, so a Student who left (or whose placement was ended with an earlier date) can still owe fees for a period after they left.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['finance.charges.view', 'enrollments.view'],
            repair: 'Open the Charge to see what was billed and paid. If it should not be owed, void its fee assessment on the fee run page with a reason, which cancels the Charge (a Charge with payments needs those payments corrected first). If the School charges for this period on purpose, no change is needed. Charges are never cancelled automatically.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->query($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->query($school)
            ->select([
                'fee_assessments.id', 'fee_assessments.student_enrollment_id', 'fee_assessments.charge_id', 'fee_assessments.fee_assessment_run_id',
                'charges.description AS charge_description',
                'fee_structure_installments.label AS period_label', 'fee_structure_installments.period_starts_on',
            ])
            ->orderBy('fee_structure_installments.period_starts_on')->orderBy('fee_assessments.id')
            ->paginate($perPage, ['*'], 'page', $page);
        $placements = $this->placements->placementsForFindings($school, collect($rows->items())->pluck('student_enrollment_id')->map(fn ($id) => (string) $id)->unique()->values()->all());

        return $rows->through(function (FeeAssessment $a) use ($placements) {
            $placement = $placements->get($a->student_enrollment_id);
            $student = $placement?->student;

            return new DataHealthFinding(
                entityType: 'charge',
                entityId: (string) $a->charge_id,
                label: $student === null ? 'Unknown Student' : trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name])))." ({$student->student_number})",
                detail: trim(implode(' · ', array_filter([
                    $a->getAttribute('charge_description'),
                    // The description usually names the period already ("Tuition · Term 2").
                    (str_contains((string) $a->getAttribute('charge_description'), (string) $a->getAttribute('period_label')) ? 'billing period' : $a->getAttribute('period_label'))
                        .' from '.CarbonImmutable::parse($a->getAttribute('period_starts_on'))->toDateString(),
                    $placement === null ? null : trim(implode(' · ', array_filter([$placement->academicYear?->name, $placement->gradeLevel?->name, $placement->section?->name]))),
                    $placement === null ? null : 'placement ended '.$placement->ends_on?->toDateString().' ('.$placement->status.')',
                ]))),
                links: array_values(array_filter([
                    new DataHealthLink('Open Charge', "/app/finance/charges/{$a->charge_id}", ['finance.charges.view']),
                    // A fee-assessed Charge is cancelled only by voiding its assessment (FEE.2, ADR 0062 §11.3).
                    $a->fee_assessment_run_id === null ? null : new DataHealthLink('Void on the fee run', "/app/finance/fee-runs/{$a->fee_assessment_run_id}", ['finance.charges.view', ChargeService::CANCEL_CAPABILITY], isRepair: true),
                    $placement === null ? null : new DataHealthLink('Enrollment history', "/app/students/{$placement->student_id}#enrollment-history", ['students.view']),
                ])),
            );
        });
    }

    /** @return Builder<FeeAssessment> */
    private function query(School $school)
    {
        return $this->targets->constrainToPeriodAfterPlacementEnded(
            FeeAssessment::query()
                ->join('charges', fn ($j) => $j->on('charges.id', '=', 'fee_assessments.charge_id')->where('charges.school_id', $school->id))
                ->join('fee_structure_installments', fn ($j) => $j->on('fee_structure_installments.id', '=', 'fee_assessments.fee_structure_installment_id')
                    ->where('fee_structure_installments.school_id', $school->id))
                ->where('fee_assessments.school_id', $school->id)
                ->whereNull('fee_assessments.voided_at')
                ->whereNull('charges.cancelled_at'),
            $school,
            'fee_assessments.student_enrollment_id',
            'fee_assessments.student_id',
            'fee_assessments.academic_year_id',
            'fee_structure_installments.period_starts_on',
        );
    }
}
