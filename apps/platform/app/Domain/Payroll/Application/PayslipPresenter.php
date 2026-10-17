<?php

namespace App\Domain\Payroll\Application;

use Carbon\CarbonImmutable;

/**
 * The one JSON/Inertia shape of a rendered `Payslip` DTO -- shared by the
 * administrative payslip API and page (Phase 9.10) and the HRX.4 own-payslip
 * API and page, so the two disclosures can never drift apart. Presentation
 * only: it reads the DTO PayslipReadService assembled and computes nothing.
 */
final class PayslipPresenter
{
    /**
     * LC-3 (M3): what a person reads on the payslip PAGE in place of the
     * run's internal id -- the run kind and its pay period, e.g. "Regular
     * payroll run · August 2026". Built from the period month and run kind,
     * both fixed once a run exists, so a historical payslip always reads the
     * same. Page-only: the API shape above is a published contract and is
     * unchanged.
     *
     * @return array{periodLabel: string, runLabel: string, correctsPeriodLabel: ?string, employeeLabel: string}
     */
    public static function labels(Payslip $payslip): array
    {
        $month = fn (string $date): string => CarbonImmutable::parse($date)->format('F Y');
        $period = $month($payslip->periodMonth);

        return [
            'periodLabel' => $period,
            'runLabel' => ($payslip->runKind === 'correction' ? 'Correction run' : 'Regular payroll run').' · '.$period,
            'correctsPeriodLabel' => $payslip->correctsPayrollPeriodMonth === null ? null : $month($payslip->correctsPayrollPeriodMonth),
            'employeeLabel' => $payslip->employeeFullName ?? 'Employee record unavailable',
        ];
    }

    /** @return array<string, mixed> */
    public static function present(Payslip $payslip): array
    {
        return [
            'schoolId' => $payslip->schoolId,
            'schoolName' => $payslip->schoolName,
            'payrollRunId' => $payslip->payrollRunId,
            'runKind' => $payslip->runKind,
            'correctsPayrollRunId' => $payslip->correctsPayrollRunId,
            'correctsPayrollPeriodMonth' => $payslip->correctsPayrollPeriodMonth,
            'runStatus' => $payslip->runStatus,
            'isReversed' => $payslip->isReversed,
            'payrollPeriodId' => $payslip->payrollPeriodId,
            'periodMonth' => $payslip->periodMonth,
            'paymentDate' => $payslip->paymentDate,
            'employmentRecordId' => $payslip->employmentRecordId,
            'employeeId' => $payslip->employeeId,
            'employeeFullName' => $payslip->employeeFullName,
            'employeeNumber' => $payslip->employeeNumber,
            'grossAmount' => $payslip->grossAmount,
            'totalDeductions' => $payslip->totalDeductions,
            'netAmount' => $payslip->netAmount,
            'lines' => array_map(fn (PayslipLine $l) => [
                'salaryComponentId' => $l->salaryComponentId,
                'componentCode' => $l->componentCode,
                'componentName' => $l->componentName,
                'componentType' => $l->componentType,
                'amount' => $l->amount,
                'effect' => $l->effect,
            ], $payslip->lines),
            'statutoryDeductionsIncluded' => $payslip->statutoryDeductionsIncluded,
            'statutory' => $payslip->statutory === null ? null : self::statutory($payslip->statutory),
        ];
    }

    /** @return array<string, mixed> */
    private static function statutory(PayslipStatutorySection $s): array
    {
        return [
            'isPfExcludedEmployee' => $s->isPfExcludedEmployee,
            'employeePfMandatory' => $s->employeePfMandatory,
            'employeePfVoluntary' => $s->employeePfVoluntary,
            'employerPfTotal' => $s->employerPfTotal,
            'employerEps' => $s->employerEps,
            'employerEpf' => $s->employerEpf,
            'employerEdli' => $s->employerEdli,
            'esiIsCovered' => $s->esiIsCovered,
            'employeeEsi' => $s->employeeEsi,
            'employerEsi' => $s->employerEsi,
            'professionalTax' => $s->professionalTax,
            'lwfCharged' => $s->lwfCharged,
            'employeeLwf' => $s->employeeLwf,
            'employerLwf' => $s->employerLwf,
            'tdsMonthlyDeduction' => $s->tdsMonthlyDeduction,
            'tdsResidualComplianceException' => $s->tdsResidualComplianceException,
            'maskedPan' => $s->maskedPan,
            'maskedUan' => $s->maskedUan,
            'maskedPfMemberId' => $s->maskedPfMemberId,
            'maskedEsicIpNumber' => $s->maskedEsicIpNumber,
            'esiDisabilityProvisionsEvaluated' => $s->esiDisabilityProvisionsEvaluated,
        ];
    }
}
