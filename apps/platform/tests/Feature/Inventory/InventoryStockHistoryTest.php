<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\IssueRequirement;
use App\Domain\Inventory\Application\StockMovementEffect;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-3 (ADR 0076 §71): the stock register (INV-016 by Campus, INV-023 by
 * Location) and the month view (INV-020), read from the one append-only
 * ledger inside the reader's Location scope. An IST School; movements pinned
 * around the June/July School-local boundary:
 *
 *   06-30 23:59:59 IST  receipt   10 CHALK -> A1          (June)
 *   07-01 00:00:00 IST  receipt    5 CHALK -> A1          (July)
 *   07-03               transfer   4 CHALK A1 -> A2       (inside Campus A)
 *   07-04               transfer   2 CHALK A1 -> B1       (A to B)
 *   07-05               issue      1 CHALK A2, to Science
 *   07-06               write-off  1 CHALK A2, lost
 *   07-07               issueMany  1 CHALK A1             (the Canteen path)
 *   07-08               receipt  2.5 RICE  -> CENTRAL     (a School-wide store)
 */
class InventoryStockHistoryTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, InventoryLocation> */
    private array $loc = [];

    private InventoryItem $chalk;

    private InventoryItem $rice;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->loc['a1'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'code' => 'A1', 'status' => 'active']);
        $this->loc['a2'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'code' => 'A2', 'status' => 'active']);
        $this->loc['b1'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->b->id, 'code' => 'B1', 'status' => 'active']);
        $this->loc['central'] = $this->createInventoryLocation($this->school, ['campus_id' => null, 'code' => 'CENTRAL', 'status' => 'active']);
        $this->chalk = $this->createInventoryItem($this->school, ['code' => 'CHALK', 'name' => 'Chalk', 'unit_of_measure' => 'each', 'status' => 'active']);
        $this->rice = $this->createInventoryItem($this->school, ['code' => 'RICE', 'name' => 'Rice', 'unit_of_measure' => 'kg', 'status' => 'active']);
        $science = $this->createDepartment($this->school, ['code' => 'SCI', 'name' => 'Science', 'campus_id' => $this->a->id, 'status' => 'active']);
        $this->officer = $this->reader([null]);

        $s = app(InventoryStockService::class);
        $this->at('2026-06-30T18:29:59Z', fn () => $s->receive($this->chalk, $this->loc['a1'], '10'));
        $this->at('2026-06-30T18:30:00Z', fn () => $s->receive($this->chalk, $this->loc['a1'], '5'));
        $this->at('2026-07-03T06:00:00Z', fn () => $s->transfer($this->chalk, $this->loc['a1'], $this->loc['a2'], '4'));
        $this->at('2026-07-04T06:00:00Z', fn () => $s->transfer($this->chalk, $this->loc['a1'], $this->loc['b1'], '2'));
        $this->at('2026-07-05T06:00:00Z', fn () => $s->issue($this->chalk, $this->loc['a2'], '1', null, $science->id));
        $this->at('2026-07-06T06:00:00Z', fn () => $s->writeOff($this->school, $this->chalk, $this->loc['a2'], '1', 'lost', $this->officer, new FreshMfaProof($this->officer->id, CarbonImmutable::now())));
        $this->at('2026-07-07T06:00:00Z', fn () => $s->issueMany($this->loc['a1'], [new IssueRequirement($this->chalk->id, '1')]));
        $this->at('2026-07-08T06:00:00Z', fn () => $s->receive($this->rice, $this->loc['central'], '2.5'));
        $this->travelTo(CarbonImmutable::parse('2026-07-20T06:00:00Z'));
    }

    private function at(string $utc, callable $fn): void
    {
        $this->travelTo(CarbonImmutable::parse($utc));
        app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function reader(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'stores_officer', $campusId);
        }

        return $user;
    }

    /** @return array<string, mixed> page props */
    private function page(User $user, string $path): array
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this->get($path)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, array<string, string>> itemCode => quantities */
    private function month(User $user, string $query): array
    {
        $rows = $this->page($user, '/app/inventory-stock/monthly?'.$query)['summary']['rows']['data'];

        return collect($rows)->mapWithKeys(fn (array $r) => [$r['itemCode'] => array_intersect_key($r, array_flip(['opening', 'received', 'transferredIn', 'issued', 'writtenOff', 'transferredOut', 'closing']))])->all();
    }

    /** @return array<string, string> */
    private function q(string $opening, string $received, string $in, string $issued, string $writtenOff, string $out, string $closing): array
    {
        return ['opening' => $opening, 'received' => $received, 'transferredIn' => $in, 'issued' => $issued, 'writtenOff' => $writtenOff, 'transferredOut' => $out, 'closing' => $closing];
    }

    /** @return list<string> "type:from>to:qty" newest first */
    private function register(User $user, string $query = ''): array
    {
        $rows = $this->page($user, '/app/inventory-stock/register'.($query === '' ? '' : '?'.$query))['rows']['data'];

        return array_map(fn (array $r) => "{$r['movementType']}:{$r['fromLocation']}>{$r['toLocation']}:{$r['quantity']}", $rows);
    }

    #[Test]
    public function the_month_view_rolls_opening_through_the_month_to_closing_school_wide(): void
    {
        $this->assertSame([
            'CHALK' => $this->q('10.000', '5.000', '0.000', '2.000', '1.000', '0.000', '12.000'),
            'RICE' => $this->q('0.000', '2.500', '0.000', '0.000', '0.000', '0.000', '2.500'),
        ], $this->month($this->officer, 'month=2026-07'), 'School-wide: every transfer is internal, the Canteen issue is an issue.');

        $june = $this->month($this->officer, 'month=2026-06');
        $this->assertSame(['CHALK' => $this->q('0.000', '10.000', '0.000', '0.000', '0.000', '0.000', '10.000')], $june, '23:59:59 IST on 06-30 is June; 00:00 IST on 07-01 (18:30 UTC on 06-30) is July.');
        $this->assertSame($june['CHALK']['closing'], $this->month($this->officer, 'month=2026-07')['CHALK']['opening'], 'one month closes where the next opens');
    }

    #[Test]
    public function a_campus_or_location_selection_counts_transfers_only_across_its_edge(): void
    {
        $this->assertSame(['CHALK' => $this->q('10.000', '5.000', '0.000', '2.000', '1.000', '2.000', '10.000')], $this->month($this->officer, "month=2026-07&campus_id={$this->a->id}"), 'A1 -> A2 stays inside Campus A; A1 -> B1 leaves it.');
        $this->assertSame(['CHALK' => $this->q('0.000', '0.000', '2.000', '0.000', '0.000', '0.000', '2.000')], $this->month($this->officer, "month=2026-07&campus_id={$this->b->id}"));
        $this->assertSame(['CHALK' => $this->q('10.000', '5.000', '0.000', '1.000', '0.000', '6.000', '8.000')], $this->month($this->officer, "month=2026-07&location_id={$this->loc['a1']->id}"));
        $this->assertSame(['CHALK' => $this->q('0.000', '0.000', '4.000', '1.000', '1.000', '0.000', '2.000')], $this->month($this->officer, "month=2026-07&location_id={$this->loc['a2']->id}"));
        $this->assertSame(['RICE' => $this->q('0.000', '2.500', '0.000', '0.000', '0.000', '0.000', '2.500')], $this->month($this->officer, 'month=2026-07&item=ric'));
    }

    #[Test]
    public function the_current_months_closing_equals_the_canonical_balance_for_every_location(): void
    {
        foreach ($this->loc as $key => $location) {
            $balances = app(TenantContext::class)->withSchool($this->school, fn () => InventoryStockBalance::query()->where('inventory_location_id', $location->id)->pluck('quantity_on_hand', 'inventory_item_id'));
            $closing = collect($this->page($this->officer, "/app/inventory-stock/monthly?location_id={$location->id}")['summary']['rows']['data'])->pluck('closing', 'itemId');
            foreach ($balances as $itemId => $onHand) {
                $this->assertSame(bcadd((string) $onHand, '0', 3), $closing[$itemId] ?? '0.000', "{$key}: the read model agrees with the balance Issue, Transfer and Write-off use");
            }
        }
    }

    #[Test]
    public function a_campus_reader_sees_only_its_own_locations_and_a_filter_never_widens(): void
    {
        $north = $this->reader([$this->a->id]);

        $this->assertSame(['CHALK' => $this->q('10.000', '5.000', '0.000', '2.000', '1.000', '2.000', '10.000')], $this->month($north, 'month=2026-07'), 'never Campus B, never the School-wide store');
        $this->assertSame([], $this->month($north, "month=2026-07&campus_id={$this->b->id}"));
        $this->assertSame([], $this->month($north, "month=2026-07&location_id={$this->loc['central']->id}"));
        $props = $this->page($north, '/app/inventory-stock/monthly');
        $this->assertSame(['A1', 'A2'], array_column($props['options']['locations'], 'code'));
        $this->assertSame(['North'], array_column($props['options']['campuses'], 'name'));

        // The register keeps the existing rule: a movement shows only when every Location it touches is in scope.
        $this->assertSame(['issue:A1>:1.000', 'write_off:A2>:1.000', 'issue:A2>:1.000', 'transfer:A1>A2:4.000', 'receipt:>A1:5.000', 'receipt:>A1:10.000'], $this->register($north));
        $this->assertSame([], $this->register($north, "location_id={$this->loc['b1']->id}"));
    }

    #[Test]
    public function the_register_filters_by_campus_location_item_type_and_school_local_dates(): void
    {
        $all = $this->register($this->officer);
        $this->assertCount(8, $all);
        $this->assertSame('receipt:>CENTRAL:2.500', $all[0], 'newest first');
        $this->assertSame(['transfer:A1>B1:2.000'], $this->register($this->officer, "location_id={$this->loc['b1']->id}"));
        $this->assertCount(7, $this->register($this->officer, "campus_id={$this->a->id}"), 'every movement touching Campus A, the A -> B transfer included');
        $this->assertSame(['receipt:>CENTRAL:2.500'], $this->register($this->officer, 'item=rice'));
        $this->assertSame(['write_off:A2>:1.000'], $this->register($this->officer, 'movement_type=write_off'));
        $this->assertSame(['receipt:>A1:5.000'], $this->register($this->officer, 'from=2026-07-01&to=2026-07-01'), '00:00 IST belongs to 07-01, 23:59:59 IST to 06-30');
        $this->assertSame(['receipt:>A1:10.000'], $this->register($this->officer, 'to=2026-06-30'));

        $rows = $this->page($this->officer, '/app/inventory-stock/register?movement_type=issue')['rows']['data'];
        $this->assertSame([null, 'Science'], array_column($rows, 'recipientDepartment'), 'the stored Department, by name; the Canteen issue (newer) has none');
        $this->assertSame(['id', 'occurredAt', 'movementType', 'itemCode', 'itemName', 'unitOfMeasure', 'quantity', 'fromLocation', 'toLocation', 'recipientDepartment', 'writeOffReason'], array_keys($rows[0]), 'no person, actor, key or MFA field');
        $this->assertSame('lost', $this->page($this->officer, '/app/inventory-stock/register?movement_type=write_off')['rows']['data'][0]['writeOffReason']);
    }

    #[Test]
    public function the_register_pages_deterministically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-21T06:00:00Z'));
        app(TenantContext::class)->withSchool($this->school, function (): void {
            for ($i = 0; $i < 50; $i++) {
                app(InventoryStockService::class)->receive($this->rice, $this->loc['central'], '1'); // the same instant: the id decides
            }
        });
        $first = $this->page($this->officer, '/app/inventory-stock/register')['rows'];
        $this->assertSame([58, 50], [$first['total'], count($first['data'])]);
        $second = $this->page($this->officer, '/app/inventory-stock/register?page=2')['rows']['data'];
        $this->assertCount(8, $second);
        $ids = [...array_column($first['data'], 'id'), ...array_column($second, 'id')];
        $this->assertSame(58, count(array_unique($ids)), 'no row twice, none skipped');
        $this->assertSame(array_column($first['data'], 'id'), array_column($this->page($this->officer, '/app/inventory-stock/register')['rows']['data'], 'id'), 'stable order');
    }

    #[Test]
    public function the_month_is_validated_reads_write_nothing_and_authority_is_required(): void
    {
        $before = app(TenantContext::class)->withSchool($this->school, fn () => [StockMovement::query()->count(), InventoryStockBalance::query()->sum('quantity_on_hand')]);
        $this->actingAs($this->officer)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/inventory-stock/monthly?month=2026-08')->assertSessionHasErrors('month');
        $this->get('/app/inventory-stock/monthly?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/app/inventory-stock/register?from=2026-07-10&to=2026-07-01')->assertSessionHasErrors('to');
        $this->get('/app/inventory-stock/register?movement_type=adjustment')->assertSessionHasErrors('movement_type');
        $this->get('/app/inventory-stock/register?from=0001-01-01')->assertSessionHasErrors('from');
        $this->get('/app/inventory-stock/register?to=9999-12-31')->assertSessionHasErrors('to');
        $this->get('/app/inventory-stock/monthly?month=0001-01')->assertSessionHasErrors('month');
        $this->assertSame('2026-07', $this->page($this->officer, '/app/inventory-stock/monthly')['summary']['month'], 'the School-local current month by default');
        $this->assertSame($before, app(TenantContext::class)->withSchool($this->school, fn () => [StockMovement::query()->count(), InventoryStockBalance::query()->sum('quantity_on_hand')]));

        $outsider = $this->createUserWithCapabilities($this->school, ['inventory.directory.view']);
        $this->actingAs($outsider)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/inventory-stock/register')->assertForbidden();
        $this->get('/app/inventory-stock/monthly')->assertForbidden();

        $other = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $stranger = $this->createUser();
        $this->assignSchoolRole($this->createMembership($stranger, $other), 'stores_officer');
        $this->actingAs($stranger)->post("/app/schools/{$other->id}/activate");
        $this->assertSame([], $this->get('/app/inventory-stock/register')->viewData('page')['props']['rows']['data']);
        $this->assertSame([], $this->get("/app/inventory-stock/monthly?month=2026-07&location_id={$this->loc['a1']->id}")->viewData('page')['props']['summary']['rows']['data']);

        $empty = $this->page($this->officer, '/app/inventory-stock/monthly?month=2026-05')['summary']['rows'];
        $this->assertSame(0, $empty['total']);
    }

    #[Test]
    public function the_one_sign_rule_matches_in_php_and_sql_for_every_movement_type(): void
    {
        foreach (StockMovementEffect::TYPES as $type) {
            foreach ([[true, true], [true, false], [false, true], [false, false]] as [$from, $to]) {
                $sql = (int) DB::selectOne('select '.StockMovementEffect::signSql("'{$type}'::text", $from ? 'true' : 'false', $to ? 'true' : 'false').' as s')->s;
                $this->assertSame(StockMovementEffect::sign($type, $from, $to), $sql, "{$type} from=".json_encode($from).' to='.json_encode($to));
            }
        }
        $this->assertSame([1, 0, -1, -1, 0], [
            StockMovementEffect::sign('receipt', false, true), StockMovementEffect::sign('transfer', true, true),
            StockMovementEffect::sign('transfer', true, false), StockMovementEffect::sign('issue', true, false), StockMovementEffect::sign('write_off', false, false),
        ]);
    }

    #[Test]
    public function the_read_models_are_get_only_write_nothing_and_never_join_the_location_set_per_movement(): void
    {
        foreach (['app.inventory-stock.register', 'app.inventory-stock.monthly'] as $name) {
            $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName($name)->methods(), $name);
        }
        $source = (string) file_get_contents(app_path('Domain/Inventory/Application/InventoryStockHistory.php'));
        foreach (['->insert(', '->update(', '->delete(', '->save(', '::create(', 'InventoryStockService'] as $write) {
            $this->assertStringNotContainsString($write, $source, $write);
        }
        // ORG.9C-3 query review: the month view tests membership against the resolved id set.
        $this->assertStringNotContainsString('leftJoinSub', $source);
        foreach (['employee_id', 'student_id', 'idempotency_key', 'mfa'] as $field) {
            $this->assertStringNotContainsString($field, $source, "no {$field} on a stock report");
        }
    }
}
