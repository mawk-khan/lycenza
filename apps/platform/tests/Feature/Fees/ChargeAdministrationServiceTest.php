<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeResult;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.4 -- `ChargeAdministrationService` is the authorized
 * administrative entry point wrapping the trusted `ChargeService` core
 * (docs/modules/FINANCE.md "Authorization architecture"). Mirrors
 * `Tests\Feature\Finance\LedgerAdministrationServiceTest`'s exact
 * section 50/51 matrix: correct capability succeeds, wrong/missing
 * capability is denied before any mutation, another School's grant
 * never authorizes this School, and a denied operation causes zero new
 * charge/ledger/audit/outbox rows.
 */
class ChargeAdministrationServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): ChargeAdministrationService
    {
        return app(ChargeAdministrationService::class);
    }

    private function assessData(string $studentId, string $academicYearId, string $receivableId, string $revenueId): AssessChargeData
    {
        return new AssessChargeData(
            studentId: $studentId,
            academicYearId: $academicYearId,
            description: 'Authorized assessment test',
            amount: Money::of('250.00', 'INR'),
            receivableLedgerAccountId: $receivableId,
            revenueLedgerAccountId: $revenueId,
        );
    }

    // --- assess() ---------------------------------------------------

    #[Test]
    public function a_member_with_manage_capability_may_assess(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.manage']);

        $result = $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id), $actor);

        $this->assertInstanceOf(ChargeResult::class, $result);
    }

    #[Test]
    public function a_view_only_member_may_not_assess(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id), $actor);
        } finally {
            $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => Charge::query()->count()));
            $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->count()));
        }
    }

    #[Test]
    public function holding_finance_ledger_post_alone_does_not_authorize_assessing_a_charge(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.ledger.post', 'finance.ledger.view']);

        $this->expectException(AuthorizationException::class);

        $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id), $actor);
    }

    #[Test]
    public function a_non_member_may_not_assess(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id), $actor);
    }

    #[Test]
    public function manage_capability_at_a_different_school_does_not_authorize_this_school(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.charges.manage']);

        $this->expectException(AuthorizationException::class);

        $this->service()->assess($school, $this->assessData($student->id, $year->id, $receivable->id, $revenue->id), $actor);
    }

    // --- cancel() ---------------------------------------------------

    #[Test]
    public function a_member_with_manage_capability_may_cancel(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.manage']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $result = $this->service()->cancel($school, $charge->id, $actor);

        $this->assertNotNull($result->cancelledAt);
    }

    #[Test]
    public function a_view_only_member_may_not_cancel(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->cancel($school, $charge->id, $actor);
        } finally {
            $reloaded = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->findOrFail($charge->id));
            $this->assertNull($reloaded->cancelled_at, 'A denied cancellation must leave the charge untouched.');
            $this->assertSame(
                1,
                app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->count()),
                'A denied cancellation must not create a reversal journal entry.',
            );
        }
    }

    #[Test]
    public function a_non_member_may_not_cancel(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUser();
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->expectException(AuthorizationException::class);

        $this->service()->cancel($school, $charge->id, $actor);
    }

    #[Test]
    public function manage_capability_at_a_different_school_does_not_authorize_cancelling_this_schools_charge(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.charges.manage']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->expectException(AuthorizationException::class);

        $this->service()->cancel($school, $charge->id, $actor);
    }
}
