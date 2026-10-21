<?php

namespace Tests\Feature\Inventory;

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7H-1 (ADR 0076 §54): a Location's first operational use and a change
 * of its Campus serialize on the Location row. Two real OS processes against
 * real PostgreSQL; the contender is positively observed BLOCKED on the
 * holder's uncommitted transaction before the holder commits
 * (ForcesConcurrentOverlap), so each test proves one order:
 *
 *   A  first receipt first      -> the Campus edit waits, then is refused
 *   B  Campus edit first        -> the receipt (authorized under the old
 *                                  Campus) waits, then is refused; nothing
 *                                  is recorded under either Campus
 *   C  name-only edit first     -> the receipt waits, then proceeds
 *   D  first receipt first      -> a raw runtime UPDATE waits, then the
 *                                  database refuses it
 *   E  first Canteen Order      -> the Campus edit waits, then is refused
 *   F  Campus edit first        -> Canteen's issueMany() waits, then is
 *                                  refused on the changed Location
 *   G  first receipt first      -> a raw runtime "cleanup" waits, then can
 *                                  neither erase nor move the now-committed
 *                                  evidence, and its Campus change is refused
 *                                  (ORG.7H-1R, ADR 0076 §55: used is monotonic)
 *
 * Never: a movement committed under Campus A while the Location commits to B.
 */
class InventoryLocationCampusConcurrencyTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    private School $school;

    private Campus $a;

    private Campus $b;

    private User $admin;

    private InventoryItem $item;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin('school_admin');
        $this->userIds[] = $this->admin->id;
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->item = $this->createInventoryItem($this->school);
        $this->location = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/inventory-location-op.php', $operation, $this->school->id, ...$args];
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function campus(): ?string
    {
        return $this->inSchool(fn () => InventoryLocation::query()->whereKey($this->location->id)->value('campus_id'));
    }

    private function movements(): int
    {
        return $this->inSchool(fn () => StockMovement::query()->count());
    }

    #[Test]
    public function a_campus_edit_waiting_on_the_first_receipt_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('receive', $this->item->id, $this->location->id, '5'),
            $this->op('edit', $this->admin->id, $this->location->id, $this->b->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LocationCampusFrozenException', $contender);
        $this->assertSame($this->a->id, $this->campus());
        $this->assertSame(1, $this->movements());
    }

    #[Test]
    public function a_first_receipt_waiting_on_a_campus_edit_is_refused_and_records_nothing(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('edit', $this->admin->id, $this->location->id, $this->b->id),
            $this->op('receive', $this->item->id, $this->location->id, '5'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LocationNotAvailableException', $contender);
        $this->assertSame($this->b->id, $this->campus());
        $this->assertSame(0, $this->movements(), 'no movement under Campus A, none under B');
    }

    #[Test]
    public function a_first_receipt_waiting_on_a_name_only_edit_proceeds(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('edit', $this->admin->id, $this->location->id, 'name:Kitchen'),
            $this->op('receive', $this->item->id, $this->location->id, '5'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame($this->a->id, $this->campus());
        $this->assertSame(1, $this->movements());
    }

    #[Test]
    public function a_raw_campus_update_waiting_on_the_first_receipt_is_refused_by_the_database(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('receive', $this->item->id, $this->location->id, '5'),
            $this->op('raw-campus', $this->location->id, 'null'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:frozen', $contender);
        $this->assertSame($this->a->id, $this->campus());
    }

    #[Test]
    public function a_campus_edit_waiting_on_the_first_canteen_order_is_refused(): void
    {
        $outlet = $this->createCanteenOutlet($this->school, $this->location, ['campus_id' => null]);
        $student = $this->createStudent($this->school);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('order', $student->id, $outlet->id),
            $this->op('edit', $this->admin->id, $this->location->id, $this->b->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LocationCampusFrozenException', $contender);
        $this->assertSame($this->a->id, $this->campus());
        $this->assertSame(1, $this->inSchool(fn () => CanteenOrder::query()->count()));
    }

    #[Test]
    public function canteen_issue_many_waiting_on_a_campus_edit_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('edit', $this->admin->id, $this->location->id, 'null'),
            $this->op('issue-many', $this->item->id, $this->location->id, '1'),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LocationNotAvailableException', $contender, 'refused on the changed Location, before any stock check');
        $this->assertNull($this->campus());
        $this->assertSame(0, $this->movements());
    }

    #[Test]
    public function a_cleanup_waiting_on_the_first_receipt_can_neither_erase_its_evidence_nor_move_the_campus(): void
    {
        $elsewhere = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'status' => 'active']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('receive', $this->item->id, $this->location->id, '5'),
            $this->op('erase-then-campus', $this->location->id, $elsewhere->id, $this->b->id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('move-movement:denied;move-balance:refused;delete-movement:denied;delete-balance:denied;campus:frozen', $contender);
        $this->assertSame($this->a->id, $this->campus());
        $this->assertSame(1, $this->inSchool(fn () => StockMovement::query()->where('to_location_id', $this->location->id)->count()));
    }
}
