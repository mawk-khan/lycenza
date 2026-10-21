<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\ChargeResult;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantRls;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * ORG.7G-1A (ADR 0076 §49), raw SQL as the runtime role (`school_os_app`):
 * a Charge's stored Campus is frozen after insert (its one-time cancellation
 * still works), it can only name a Campus of the Charge's own School, and a
 * source row (fee assessment, late fee, Canteen order) is refused when its
 * historical Campus disagrees with the Charge it records. RLS stays forced.
 */
class ChargeCampusIntegrityTest extends TestCase
{
    use CreatesCanteenFixtures;
    use CreatesLateFeeFixtures;

    /** A Charge with an arbitrary attribution -- what no production creator can build (the constructor is private). */
    private function forgedCharge(array $w, string $studentId, ?string $campusId): ChargeResult
    {
        $forged = Closure::bind(fn () => new ChargeCampusAttribution(ChargeCampusAttribution::SOURCE_ENROLLMENT, $campusId), null, ChargeCampusAttribution::class)();

        return app(ChargeService::class)->assess($w['school'], new AssessChargeData(
            studentId: $studentId, academicYearId: $w['year']->id, description: 'Forged', amount: Money::of('1.00', 'INR'),
            receivableLedgerAccountId: $w['receivable']->id, revenueLedgerAccountId: $w['revenue']->id, campus: $forged,
        ));
    }

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function refusedBy(string $needle, Closure $statement, string $message): void
    {
        try {
            DB::transaction($statement);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage(), $message);
        }
    }

    #[Test]
    public function the_runtime_role_cannot_rewrite_a_charge_campus_but_can_still_cancel_it(): void
    {
        $this->travelTo('2026-06-05 06:00:00');
        $w = $this->lateFeeWorld();
        $other = $this->createCampus($w['school']);
        $this->setSchool($w['school']->id);
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        foreach ([$other->id, null] as $value) {
            $this->refusedBy('recognized financial fields are immutable', fn () => DB::update('UPDATE charges SET campus_id = ? WHERE id = ?', [$value, $w['source']->id]), 'a stored Campus is never rewritten');
        }

        $manual = $this->assessCharge($w['school'], $this->createStudent($w['school']), $w['year'], $w['receivable'], $w['revenue'], '10.00');
        app(ChargeService::class)->cancel($w['school'], $manual->id, null, 'recorded in error');
        $cancelled = $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($manual->id));
        $this->assertNotNull($cancelled->cancelled_at, 'the one-time cancellation still works');
        $this->assertSame($manual->campus_id, $cancelled->campus_id);
        $this->assertSame($w['campus']->id, $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($w['source']->id)->campus_id));

        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'charges'");
        $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity);
    }

    #[Test]
    public function a_charge_can_only_name_a_campus_of_its_own_school(): void
    {
        $this->travelTo('2026-06-05 06:00:00');
        $w = $this->concessionWorld();
        $foreign = $this->createCampus($this->createSchool());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/charges_campus_fk/');
        $this->forgedCharge($w, $w['student']->id, $foreign->id);
    }

    #[Test]
    public function a_source_row_whose_campus_disagrees_with_its_charge_is_refused(): void
    {
        $this->travelTo('2026-06-05 06:00:00');
        $w = $this->lateFeeWorld();
        $other = $this->createCampus($w['school'], ['name' => 'Elsewhere']);
        $rule = $this->activeRule($w);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());
        $wrong = $this->forgedCharge($w, $w['sourceStudent'], $other->id);
        $this->setSchool($w['school']->id);

        // Fee run: an assessment records its Enrollment's Campus.
        $row = (array) DB::table('fee_assessments')->where('id', $assessment->id)->first();
        $this->refusedBy("fee-run charge's Campus must be its source's historical Campus", fn () => DB::table('fee_assessments')->insert(array_merge(
            $row, ['id' => (string) new UuidV7, 'charge_id' => $wrong->chargeId, 'billing_period_key' => 'X9'],
        )), 'a fee assessment cannot point at a Charge of another Campus');

        // Late fee: inherits the source Charge's Campus.
        $this->travelTo('2026-06-11 06:00:00');
        $run = $this->previewedLateRun($w, $rule);
        $this->setSchool($w['school']->id);
        $this->refusedBy("late-fee charge's Campus must be its source's historical Campus", fn () => DB::table('late_fee_assessments')->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'source_charge_id' => $w['source']->id,
            'fee_late_fee_rule_id' => $rule->id, 'charge_id' => $wrong->chargeId, 'late_fee_run_id' => $run->id,
            'created_at' => now(), 'updated_at' => now(),
        ]), 'a late fee cannot carry another Campus than its source Charge');

        // Canteen: an order's Charge is its Outlet's Campus.
        $this->configureCanteenBilling($w['school'], $w['receivable'], $w['revenue']);
        $location = $this->createInventoryLocation($w['school'], ['campus_id' => $w['campus']->id, 'status' => 'active']);
        $outlet = $this->createCanteenOutlet($w['school'], $location, ['campus_id' => $w['campus']->id, 'status' => 'active']);
        $item = $this->createCanteenItem($w['school'], ['price' => '1.00', 'status' => 'active']);
        $order = $this->placeCanteenOrder($w['school'], $w['sourceStudent'], $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
        $this->setSchool($w['school']->id);
        $this->refusedBy("Canteen charge's Campus must be its source's historical Campus", fn () => DB::update('UPDATE canteen_orders SET charge_id = ? WHERE id = ?', [$wrong->chargeId, $order->orderId]), 'a Canteen order cannot point at a Charge of another Campus');
    }
}
