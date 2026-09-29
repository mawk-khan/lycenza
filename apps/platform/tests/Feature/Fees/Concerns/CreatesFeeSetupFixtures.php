<?php

namespace Tests\Feature\Fees\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * FEE.1 fixtures. `feeWorld()` builds one School with an academic year
 * (2026-06-01 .. 2027-05-31), a grade, a campus, an active asset
 * receivable and an active income revenue account, and an actor holding
 * every FEE.1 capability. Authorization tests build their own narrow
 * actors with createUserWithCapabilities() instead.
 */
trait CreatesFeeSetupFixtures
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    public const FEE_SETUP_CAPABILITIES = [
        'finance.accounts.manage', 'finance.ledger.view',
        'finance.fee_structures.view', 'finance.fee_structures.manage',
    ];

    /**
     * @return array{school: School, actor: User, year: AcademicYear, grade: GradeLevel, campus: Campus, receivable: LedgerAccount, revenue: LedgerAccount}
     */
    protected function feeWorld(): array
    {
        $school = $this->createSchool();

        return [
            'school' => $school,
            'actor' => $this->createUserWithCapabilities($school, self::FEE_SETUP_CAPABILITIES),
            'year' => $this->createAcademicYear($school, [
                'code' => 'AY2026', 'name' => '2026-27', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
            ]),
            'grade' => $this->createGradeLevel($school),
            'campus' => $this->createCampus($school),
            'receivable' => $this->createLedgerAccount($school, ['code' => 'AR-FEES', 'type' => 'asset']),
            'revenue' => $this->createLedgerAccount($school, ['code' => 'INC-TUITION', 'type' => 'income']),
        ];
    }

    protected function makeFeeHead(array $w, array $attributes = []): FeeHead
    {
        return app(FeeHeadService::class)->create($w['school'], array_merge([
            'code' => 'TUITION',
            'name' => 'Tuition',
            'receivable_ledger_account_id' => $w['receivable']->id,
            'revenue_ledger_account_id' => $w['revenue']->id,
        ], $attributes), $w['actor']);
    }

    protected function makeDraftStructure(array $w, array $attributes = []): FeeStructure
    {
        return app(FeeStructureService::class)->createDraft($w['school'], array_merge([
            'academic_year_id' => $w['year']->id,
            'grade_level_id' => $w['grade']->id,
            'campus_id' => null,
            'code' => 'G-DEFAULT',
            'name' => 'Grade fees',
        ], $attributes), $w['actor']);
    }

    /** A draft structure with one line whose one-time schedule sums to its amount. */
    protected function makeCompleteDraft(array $w, array $attributes = [], string $amount = '12000.00', ?FeeHead $head = null): FeeStructure
    {
        $structure = $this->makeDraftStructure($w, $attributes);
        $head ??= $this->inSchool($w['school'], fn () => FeeHead::query()->where('code', 'TUITION')->first())
            ?? $this->makeFeeHead($w);

        $line = app(FeeStructureService::class)->addLine($w['school'], $structure->id, [
            'fee_head_id' => $head->id, 'amount' => $amount,
        ], $w['actor']);
        app(FeeStructureService::class)->generateInstallments($w['school'], $structure->id, $line->id, FeeStructureLine::FREQUENCY_ONE_TIME, $w['actor']);

        return $this->inSchool($w['school'], fn () => $structure->refresh());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    protected function auditCount(School $school, string $eventType): int
    {
        return $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count());
    }
}
