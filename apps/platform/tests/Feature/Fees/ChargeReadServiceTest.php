<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\ChargeDetail;
use App\Domain\Fees\Application\ChargeQuery;
use App\Domain\Fees\Application\ChargeReadService;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeSummary;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.4 -- `ChargeReadService` is the sole authorized read path
 * for `charges`, mirroring `Tests\Feature\Finance\LedgerReadServiceTest`'s
 * exact discipline: authorization checked before any query, typed DTOs
 * only (never a raw `Charge`), cross-School "no oracle" behavior, one
 * audit event per call (never per row), and denial produces zero
 * audit events.
 */
class ChargeReadServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): ChargeReadService
    {
        return app(ChargeReadService::class);
    }

    #[Test]
    public function a_member_with_view_capability_may_list_charges(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->assessCharge($school, $student, $year, $receivable, $revenue, '300.00');
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $result = $this->service()->listCharges($school, new ChargeQuery, $actor);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result->getCollection());
        $this->assertInstanceOf(ChargeSummary::class, $result->getCollection()->first());
        $this->assertSame('300.00', $result->getCollection()->first()->amount);
    }

    #[Test]
    public function manage_capability_alone_does_not_authorize_listing(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.manage']);

        // finance.charges.manage grants assess/cancel, not read -- the
        // pair is FINANCE.md's own committed shape (both distinct from
        // finance.ledger.*), but manage vs. view remains a real split
        // within the pair itself (see ChargeReadService::listCharges()'s
        // own single-capability check -- it checks .view, never .manage).
        $this->expectException(AuthorizationException::class);

        $this->service()->listCharges($school, new ChargeQuery, $actor);
    }

    #[Test]
    public function a_non_member_may_not_list_charges(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();

        $this->expectException(AuthorizationException::class);

        $this->service()->listCharges($school, new ChargeQuery, $actor);
    }

    #[Test]
    public function view_capability_at_a_different_school_does_not_authorize_this_school(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['finance.charges.view']);

        $this->expectException(AuthorizationException::class);

        $this->service()->listCharges($school, new ChargeQuery, $actor);
    }

    #[Test]
    public function listing_filters_by_student(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->assessCharge($school, $studentA, $year, $receivable, $revenue);
        $this->assessCharge($school, $studentB, $year, $receivable, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $result = $this->service()->listCharges($school, new ChargeQuery(studentId: $studentA->id), $actor);

        $this->assertCount(1, $result->getCollection());
        $this->assertSame($studentA->id, $result->getCollection()->first()->studentId);
    }

    #[Test]
    public function listing_excludes_cancelled_charges_by_default_and_includes_them_when_requested(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);
        app(ChargeService::class)->cancel($school, $charge->id);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $default = $this->service()->listCharges($school, new ChargeQuery, $actor);
        $this->assertCount(0, $default->getCollection());

        $withCancelled = $this->service()->listCharges($school, new ChargeQuery(includeCancelled: true), $actor);
        $this->assertCount(1, $withCancelled->getCollection());
        $this->assertNotNull($withCancelled->getCollection()->first()->cancelledAt);
    }

    #[Test]
    public function a_member_with_view_capability_may_read_charge_detail(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '750.00');
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $detail = $this->service()->getChargeDetail($school, $charge->id, $actor);

        $this->assertInstanceOf(ChargeDetail::class, $detail);
        $this->assertNotInstanceOf(Charge::class, $detail);
        $this->assertSame('750.00', $detail->amount);
        $this->assertSame($receivable->id, $detail->receivableLedgerAccountId);
        $this->assertSame($revenue->id, $detail->revenueLedgerAccountId);
    }

    #[Test]
    public function a_nonexistent_charge_id_raises_not_found(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $this->expectException(ChargeNotFoundException::class);

        $this->service()->getChargeDetail($school, (string) Str::uuid(), $actor);
    }

    #[Test]
    public function a_charge_belonging_to_a_different_school_raises_the_identical_not_found_error(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $student = $this->createStudent($otherSchool);
        $year = $this->createAcademicYear($otherSchool);
        $receivable = $this->createLedgerAccount($otherSchool, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($otherSchool, ['type' => 'income']);
        $chargeInOtherSchool = $this->assessCharge($otherSchool, $student, $year, $receivable, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);

        $this->expectException(ChargeNotFoundException::class);

        $this->service()->getChargeDetail($school, $chargeInOtherSchool->id, $actor);
    }

    #[Test]
    public function successful_reads_are_audited_once_per_call_and_denied_reads_are_never_audited(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);
        $actor = $this->createUserWithCapabilities($school, ['finance.charges.view']);
        $nonMember = $this->createUser();

        $this->service()->listCharges($school, new ChargeQuery, $actor);
        $this->service()->getChargeDetail($school, $charge->id, $actor);

        try {
            $this->service()->listCharges($school, new ChargeQuery, $nonMember);
        } catch (AuthorizationException) {
        }

        $this->assertSame(
            1,
            app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'charge.list_viewed')->count()),
        );
        $this->assertSame(
            1,
            app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'charge.detail_viewed')->count()),
        );
    }
}
