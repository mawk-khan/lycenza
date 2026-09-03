<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Support\Carbon;

/**
 * Phase 9.7 -- the authorized ADMINISTRATIVE entry point for
 * `payroll_periods`, mirroring `PayrollStructureAdministrationService`'s
 * exact split from its own trusted core (`PayrollPeriodService`, which
 * performs no capability check itself). No transport may call
 * `PayrollPeriodService` directly; a future controller depends on this
 * class. Single capability, `payroll.periods.manage`, gates every
 * mutation here.
 */
class PayrollPeriodAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly PayrollPeriodService $periods) {}

    public function createPeriod(School $school, Carbon $month, ?Carbon $paymentDate, User $actor): PayrollPeriod
    {
        $this->authorizeCapabilityFor($actor, 'payroll.periods.manage', $school);

        return $this->periods->createPeriod($school, $month, $paymentDate, $actor);
    }

    public function open(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $this->authorizeCapabilityFor($actor, 'payroll.periods.manage', $period->school);

        return $this->periods->open($period, $actor);
    }

    public function close(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        $this->authorizeCapabilityFor($actor, 'payroll.periods.manage', $period->school);

        return $this->periods->close($period, $actor);
    }
}
