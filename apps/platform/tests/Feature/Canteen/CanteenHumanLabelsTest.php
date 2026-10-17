<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\CanteenChargeDescription;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderLine;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * LC-3 (M3): people read Canteen orders, and the Charges they raise, by
 * names, numbers and dates -- never by internal ids. Charges and journal
 * entries are immutable once posted, so the readable description is
 * written when the order is fulfilled; earlier charges keep their text.
 */
class CanteenHumanLabelsTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesTenancyFixtures;

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    /** @return array<string, mixed> a billable School with one Student, an outlet and two items */
    private function world(?School $school = null): array
    {
        $school ??= $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $this->configureCanteenBilling($school, $this->createLedgerAccount($school, ['type' => 'asset']), $this->createLedgerAccount($school, ['type' => 'income']));
        $outlet = $this->createCanteenOutlet($school, $this->createInventoryLocation($school), ['code' => 'MAIN', 'name' => 'Main Canteen']);
        $student = $this->createStudent($school, ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'student_number' => 'LDS-0001']);
        $thali = $this->createCanteenItem($school, ['code' => 'THALI', 'name' => 'Veg Thali', 'price' => '40.00']);
        $juice = $this->createCanteenItem($school, ['code' => 'JUICE', 'name' => 'Juice', 'price' => '15.00']);

        return compact('school', 'year', 'outlet', 'student', 'thali', 'juice');
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** @param  list<array{0: CanteenItem, 1: int}>  $lines */
    private function fulfilledOrder(array $w, array $lines): CanteenOrder
    {
        $placed = $this->placeCanteenOrder($w['school'], $w['student']->id, $w['outlet']->id, array_map(fn ($l) => ['canteenItemId' => $l[0]->id, 'quantity' => $l[1]], $lines));
        $this->fulfillCanteenOrder($w['school'], $placed->orderId);

        return $this->inSchool($w['school'], fn () => CanteenOrder::query()->findOrFail($placed->orderId));
    }

    private function viewer(School $school, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function a_fulfilled_order_raises_a_charge_and_journal_entry_people_can_read(): void
    {
        Carbon::setTestNow('2026-10-17 05:00:00'); // 10:30 in Kolkata
        $w = $this->world();
        $order = $this->fulfilledOrder($w, [[$w['thali'], 2], [$w['juice'], 1]]);

        $charge = $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($order->charge_id));
        $this->assertSame('Canteen · Juice ×1, Veg Thali ×2 · 17 Oct 2026', $charge->description);
        $journal = $this->inSchool($w['school'], fn () => JournalEntry::query()->findOrFail($charge->journal_entry_id));
        $this->assertSame($charge->description, $journal->description);
        $this->assertDoesNotMatchRegularExpression(self::UUID, $charge->description);

        // The staff statement reads the same immutable description.
        $statement = app(StudentFeeStatementReadService::class)->statementFor($w['school'], $w['student']->id, null, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']));
        $this->assertSame([$charge->description], array_column($statement->lines, 'description'));

        // Cancelling it reverses with a readable narrative too.
        app(ChargeService::class)->cancel($w['school'], $charge->id);
        $reversal = $this->inSchool($w['school'], fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $journal->id)->firstOrFail());
        $this->assertSame('Reversal of: '.$charge->description, $reversal->description);
        Carbon::setTestNow();
    }

    #[Test]
    public function the_guardian_statement_shows_the_readable_canteen_line(): void
    {
        $school = $this->createSchool();
        $g = $this->portalGuardian($school);
        $w = $this->world($school);
        $w['student'] = $g['student'];
        $order = $this->fulfilledOrder($w, [[$w['thali'], 1]]);
        $this->enrollActiveMfaFactor($g['user']);
        $this->signInTo($g['user'], $school);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        $response = $this->get("/app/portal/fees/students/{$g['student']->id}")->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('statement.lines.0.description', fn (string $d) => str_starts_with($d, 'Canteen · Veg Thali ×1 · ')));
        $this->assertStringNotContainsString($order->id, (string) $response->getContent());
    }

    #[Test]
    public function the_order_pages_name_the_student_outlet_and_items(): void
    {
        $w = $this->world();
        $order = $this->fulfilledOrder($w, [[$w['thali'], 2]]);
        $this->viewer($w['school'], ['canteen.orders.view']);

        $this->get('/app/canteen-orders')->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Orders/Index')
            ->where('orders.data.0.studentName', 'Aarav Sharma')
            ->where('orders.data.0.studentNumber', 'LDS-0001')
            ->where('orders.data.0.outletName', 'Main Canteen')
            ->missing('orders.data.0.studentId')
            ->missing('orders.data.0.outletId')
            ->missing('orders.data.0.totalAmount'));

        $this->get("/app/canteen-orders/{$order->id}")->assertInertia(fn ($page) => $page
            ->component('App/Canteen/Orders/Show')
            ->where('order.studentName', 'Aarav Sharma')
            ->where('order.outletName', 'Main Canteen')
            ->where('order.lines.0.itemName', 'Veg Thali')
            ->where('order.lines.0.itemCode', 'THALI')
            ->missing('order.lines.0.canteenItemId'));
    }

    #[Test]
    public function the_order_list_does_not_query_per_row(): void
    {
        $w = $this->world();
        foreach (range(1, 6) as $_) {
            $this->placeCanteenOrder($w['school'], $this->createStudent($w['school'])->id, $w['outlet']->id, [['canteenItemId' => $w['juice']->id, 'quantity' => 1]]);
        }
        $this->viewer($w['school'], ['canteen.orders.view']);

        DB::enableQueryLog();
        $this->get('/app/canteen-orders')->assertOk();
        $studentQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "students"'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $studentQueries, 'Students load in one query for the whole page.');
    }

    #[Test]
    public function same_named_items_stay_distinguishable_and_a_deactivated_item_keeps_its_name(): void
    {
        $w = $this->world();
        $twin = $this->createCanteenItem($w['school'], ['code' => 'JUICE-L', 'name' => 'Juice', 'price' => '25.00']);
        $placed = $this->placeCanteenOrder($w['school'], $w['student']->id, $w['outlet']->id, [
            ['canteenItemId' => $w['juice']->id, 'quantity' => 1], ['canteenItemId' => $twin->id, 'quantity' => 1],
        ]);
        $this->inSchool($w['school'], fn () => $twin->update(['status' => 'inactive']));
        $this->viewer($w['school'], ['canteen.orders.view']);

        $this->get("/app/canteen-orders/{$placed->orderId}")->assertInertia(fn ($page) => $page
            ->where('order.lines', fn ($lines) => collect($lines)->pluck('itemCode')->sort()->values()->all() === ['JUICE', 'JUICE-L']
                && collect($lines)->pluck('itemName')->unique()->all() === ['Juice']));
    }

    #[Test]
    public function a_long_order_fits_the_description_column_and_counts_the_rest(): void
    {
        $school = $this->createSchool();
        $order = new CanteenOrder(['placed_at' => Carbon::parse('2026-10-17 05:00:00')]);
        $items = [];
        $lines = collect(range(1, 12))->map(function (int $i) use (&$items) {
            $id = "item-{$i}";
            $items[$id] = new CanteenItem(['name' => str_repeat('Long item name ', 2).$i]);

            return new CanteenOrderLine(['canteen_item_id' => $id, 'quantity' => 1]);
        });
        $description = CanteenChargeDescription::for($school, $order, $lines, $items);

        $this->assertLessThanOrEqual(CanteenChargeDescription::MAX_LENGTH, mb_strlen($description));
        $this->assertMatchesRegularExpression('/^Canteen · .+ and \d+ more · \d{1,2} Oct 2026$/u', $description);

        // An item that can no longer be resolved reads neutrally, never as an id.
        $missing = CanteenChargeDescription::for($school, $order, collect([new CanteenOrderLine(['canteen_item_id' => 'gone', 'quantity' => 2])]), []);
        $this->assertStringStartsWith('Canteen · Item ×2 · ', $missing);
    }

    #[Test]
    public function another_schools_order_is_not_found_and_lends_no_names(): void
    {
        $mine = $this->world();
        $other = $this->world();
        $theirs = $this->fulfilledOrder($other, [[$other['thali'], 1]]);
        $this->viewer($mine['school'], ['canteen.orders.view']);

        $this->get("/app/canteen-orders/{$theirs->id}")->assertNotFound();
        $content = (string) $this->get('/app/canteen-orders')->getContent();
        $this->assertStringNotContainsString($theirs->id, $content);
        $this->assertStringNotContainsString('LDS-0001', $content, 'The other School\'s Student is never named here.');
    }
}
