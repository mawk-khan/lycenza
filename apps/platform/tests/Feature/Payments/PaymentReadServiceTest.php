<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\PaymentQuery;
use App\Domain\Payments\Application\PaymentReadService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5: proves `PaymentReadService`'s authorization/disclosure/
 * audit discipline mirrors
 * `Tests\Feature\Fees\ChargeReadServiceTest`'s exact shape.
 */
class PaymentReadServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    #[Test]
    public function an_authorized_actor_can_list_and_view_payment_detail(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['finance.payments.view']);
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '250.00');
        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '250.00']], '250.00');

        $service = app(PaymentReadService::class);

        $page = $service->listPayments($school, new PaymentQuery, $actor);
        $this->assertCount(1, $page->items());

        $detail = $service->getPaymentDetail($school, $result->paymentId, $actor);
        $this->assertSame($result->paymentId, $detail->paymentId);
        $this->assertSame('250.00', $detail->amount);
        $this->assertCount(1, $detail->allocations);

        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.list_viewed')->count()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.detail_viewed')->count()));
    }

    #[Test]
    public function an_actor_without_the_capability_is_denied(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);
        app(PaymentReadService::class)->listPayments($school, new PaymentQuery, $actor);
    }

    #[Test]
    public function a_cross_school_payment_id_raises_the_same_not_found_error_as_a_nonexistent_one(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->createUserWithCapabilities($schoolA, ['finance.payments.view']);
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $settlementB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB, '100.00');
        $resultB = $this->recordSettlement($schoolB, $settlementB->id, [[$chargeB, '100.00']], '100.00');

        $this->expectException(PaymentNotFoundException::class);
        app(PaymentReadService::class)->getPaymentDetail($schoolA, $resultB->paymentId, $actorA);
    }
}
