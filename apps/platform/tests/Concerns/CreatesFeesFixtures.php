<?php

namespace Tests\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0G.4 test fixtures.
 *
 * assessCharge() delegates to the real
 * App\Domain\Fees\Application\ChargeService::assess() rather than
 * constructing rows by hand -- the same "don't maintain a second,
 * competing implementation of posting semantics" rule
 * Tests\Concerns\CreatesFinanceFixtures::postBalancedJournalEntry()
 * already established for Finance, applied to Fees.
 */
trait CreatesFeesFixtures
{
    protected function assessCharge(
        School $school,
        Student $student,
        AcademicYear $academicYear,
        LedgerAccount $receivableAccount,
        LedgerAccount $revenueAccount,
        string $amount = '1000.00',
        string $currency = 'INR',
        string $description = 'Test charge',
        ?string $dueDate = null,
    ): Charge {
        $result = app(ChargeService::class)->assess($school, new AssessChargeData(
            studentId: $student->id,
            academicYearId: $academicYear->id,
            description: $description,
            amount: Money::of($amount, $currency),
            receivableLedgerAccountId: $receivableAccount->id,
            revenueLedgerAccountId: $revenueAccount->id,
            dueDate: $dueDate,
        ));

        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Charge::query()->findOrFail($result->chargeId),
        );
    }
}
