<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\Payroll\Statutory\Infrastructure\PayrollEsiRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollIncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollLwfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollPfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollProfessionalTaxRuleVersion;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Checkpoint 9.6I (Section 1 "Statutory rule status") -- READ-ONLY
 * visibility into the active statutory rule versions. Deliberately
 * exposes no mutation: rule-version content (rates, slabs, ceilings)
 * remains code/seed-controlled reference data
 * (`StatutoryRuleVersionSeeder`), never a runtime tenant-editable
 * "rules engine" (ADR 0035's explicit prohibition) -- a future legal
 * change ships as a new seeded version with a later `effective_from`,
 * through a reviewed code change, never a School admin's own edit.
 */
class StatutoryRuleStatusReadService
{
    use AuthorizesCapability;

    /**
     * @return array{pf: ?PayrollPfRuleVersion, esi: ?PayrollEsiRuleVersion, professionalTax: ?PayrollProfessionalTaxRuleVersion, lwf: ?PayrollLwfRuleVersion, incomeTaxNewRegime: ?PayrollIncomeTaxRuleVersion, incomeTaxOldRegime: ?PayrollIncomeTaxRuleVersion}
     */
    public function activeRuleVersions(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);

        $asOf = now()->toDateString();

        return [
            'pf' => PayrollPfRuleVersion::query()->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->first(),
            'esi' => PayrollEsiRuleVersion::query()->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->first(),
            'professionalTax' => PayrollProfessionalTaxRuleVersion::query()->where('jurisdiction', 'Telangana')->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->with('slabs')->first(),
            'lwf' => PayrollLwfRuleVersion::query()->where('jurisdiction', 'Telangana')->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->first(),
            'incomeTaxNewRegime' => PayrollIncomeTaxRuleVersion::query()->where('regime', 'new')->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->with(['incomeSlabs', 'surchargeSlabs'])->first(),
            'incomeTaxOldRegime' => PayrollIncomeTaxRuleVersion::query()->where('regime', 'old')->where('status', 'active')->where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->with(['incomeSlabs', 'surchargeSlabs'])->first(),
        ];
    }
}
