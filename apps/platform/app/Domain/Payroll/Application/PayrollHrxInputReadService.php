<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunHrxInput;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * HRX.5 (ADR 0065 §26.10): a run's captured HRX absence evidence beside a
 * FRESH read of the same period and employment, compared by fingerprint.
 *
 * - For an `approved`/`posted` run a difference means the HRX source changed
 *   after approval: the snapshot and the results stay frozen, and any money
 *   correction is the existing correction run with administrator-entered
 *   deltas -- nothing here reposts or rewrites anything.
 * - For a `draft`/`calculated` run the remedy is recalculation, which
 *   recaptures the evidence.
 *
 * Evidence only, never a legal or monetary conclusion (HRX-L4 open): no
 * "deductible", "loss of pay" or "NCP" is derived. `payroll.runs.prepare`
 * (the evidence is attendance-derived personal data, not "non-sensitive"
 * run detail).
 */
class PayrollHrxInputReadService
{
    use AuthorizesCapability;

    public const CAPABILITY = 'payroll.runs.prepare';

    /** ADR 0065 §26.2: no payroll policy turns this evidence into a non-payable quantity until HRX-L4 is qualified. */
    public const PAYROLL_POLICY = 'pending_hrx_l4';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly PayrollAbsenceEvidenceReader $hrx,
        private readonly EmploymentRoster $roster,
    ) {}

    /** @return array<string, mixed> */
    public function forRun(School $school, string $payrollRunId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->compare($school, $payrollRunId);
    }

    /**
     * Records `payroll.hrx_input.difference_detected` for every employment of
     * the run whose current HRX evidence no longer matches the snapshot.
     *
     * @return array{payrollRunId: string, checked: int, changed: int}
     */
    public function checkDifferences(School $school, string $payrollRunId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);
        $comparison = $this->compare($school, $payrollRunId);
        $run = $this->context->withSchool($school, fn () => PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRunId));

        $changed = array_values(array_filter($comparison['inputs'], fn (array $i) => $i['sourceChanged']));
        $this->context->withSchool($school, function () use ($school, $run, $changed, $actor) {
            foreach ($changed as $input) {
                $this->audit->school($school, 'payroll.hrx_input.difference_detected', actor: $actor, subject: $run, metadata: [
                    'payrollRunId' => $run->id, 'runStatus' => $run->status, 'employmentRecordId' => $input['employmentRecordId'],
                    'contractVersion' => $input['contractVersion'], 'capturedFingerprint' => $input['fingerprint'], 'currentFingerprint' => $input['current']['fingerprint'],
                    'capturedUnits' => $input['units'], 'currentUnits' => $input['current']['units'],
                ]);
            }
        });

        return ['payrollRunId' => $run->id, 'checked' => count($comparison['inputs']), 'changed' => count($changed)];
    }

    /** @return array<string, mixed> */
    private function compare(School $school, string $payrollRunId): array
    {
        return $this->context->withSchool($school, function () use ($school, $payrollRunId) {
            $run = PayrollRun::query()->where('school_id', $school->id)->find($payrollRunId) ?? throw new ModelNotFoundException('No query results for the payroll run.');
            $frozen = in_array($run->status, ['approved', 'posted'], true);
            $snapshots = PayrollRunHrxInput::query()->where('school_id', $school->id)->where('payroll_run_id', $run->id)->orderBy('employment_record_id')->get();
            $labels = collect($this->roster->labels($school, $snapshots->pluck('employment_record_id')->all()))->keyBy('employmentRecordId');

            $inputs = $snapshots->map(function (PayrollRunHrxInput $s) use ($school, $labels) {
                $current = $this->hrx->read($school, $s->employment_record_id, $s->period_starts_on->toDateString(), $s->period_ends_on->toDateString());
                $label = $labels[$s->employment_record_id] ?? null;

                return [
                    'employmentRecordId' => $s->employment_record_id,
                    'employee' => ['employeeNumber' => $label['employeeNumber'] ?? null, 'fullName' => $label['fullName'] ?? null],
                    'contractVersion' => $s->contract_version, 'fingerprint' => $s->fingerprint,
                    'completeness' => $s->completeness, 'incompleteReasons' => $s->incomplete_reasons,
                    'periodStartsOn' => $s->period_starts_on->toDateString(), 'periodEndsOn' => $s->period_ends_on->toDateString(),
                    'coveredFrom' => $s->covered_from?->toDateString(), 'coveredTo' => $s->covered_to?->toDateString(),
                    'units' => self::units($s), 'capturedAt' => $s->captured_at->toIso8601String(),
                    'sourceChanged' => $current->fingerprint !== $s->fingerprint,
                    'current' => ['fingerprint' => $current->fingerprint, 'completeness' => $current->completeness, 'incompleteReasons' => $current->incompleteReasons, 'units' => $current->units()],
                ];
            })->values()->all();

            return [
                'payrollRunId' => $run->id, 'runKind' => $run->run_kind, 'runStatus' => $run->status,
                'contractVersion' => PayrollAbsenceEvidenceReader::CONTRACT_VERSION, 'payrollPolicy' => self::PAYROLL_POLICY,
                'frozen' => $frozen,
                // HRX source changed after approval/posting (frozen) vs recalculation refreshes it (not frozen).
                'differenceState' => collect($inputs)->contains('sourceChanged', true) ? ($frozen ? 'source_changed_after_approval' : 'recalculate_to_refresh') : 'none',
                'inputs' => $inputs,
            ];
        });
    }

    /** @return array<string, int|null> */
    private static function units(PayrollRunHrxInput $s): array
    {
        return [
            'requiredWorkingHalfUnits' => $s->required_working_half_units,
            'approvedPaidLeaveHalfUnits' => $s->approved_paid_leave_half_units,
            'approvedUnpaidLeaveHalfUnits' => $s->approved_unpaid_leave_half_units,
            'recordedAbsenceHalfUnits' => $s->recorded_absence_half_units,
            'recordedPresenceHalfUnits' => $s->recorded_presence_half_units,
            'unresolvedWorkingHalfUnits' => $s->unresolved_working_half_units,
        ];
    }
}
