<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-2 (ADR 0076 §70): a write-off and an issue serialize with every
 * other stock write on the balance row (Location FOR SHARE, then balance
 * FOR UPDATE, sufficiency re-checked under the lock) -- two GENUINELY
 * separate OS processes against real PostgreSQL, never a sequential
 * simulation. From 5 on hand, two writes of 4 each: exactly one succeeds,
 * the other is refused for insufficient stock, the balance ends at 1 and
 * one movement exists for the winner; no deadlock.
 */
class InventoryWriteOffConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private InventoryItem $item;

    private InventoryLocation $location;

    private InventoryLocation $destination;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->item = $this->createInventoryItem($this->school, ['status' => 'active', 'unit_of_measure' => 'each']);
        $this->location = $this->createInventoryLocation($this->school, ['status' => 'active']);
        $this->destination = $this->createInventoryLocation($this->school, ['status' => 'active']);
        app(TenantContext::class)->withSchool($this->school, fn () => InventoryStockBalance::factory()->create([
            'school_id' => $this->school->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'quantity_on_hand' => '5.000',
        ]));
        $this->actor = $this->createUser();
        $this->assignSchoolRole($this->createMembership($this->actor, $this->school), 'stores_officer');
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);
        parent::tearDown();
    }

    /** @return list<string> */
    private function writeOff(): array
    {
        return ['php', __DIR__.'/../../Support/write-off-inventory-stock.php', $this->school->id, $this->item->id, $this->location->id, '4', $this->actor->id];
    }

    /** @return list<string> */
    private function issue(): array
    {
        return ['php', __DIR__.'/../../Support/issue-inventory-stock.php', $this->school->id, $this->item->id, $this->location->id, '4'];
    }

    /** @return list<string> */
    private function transfer(): array
    {
        return ['php', __DIR__.'/../../Support/transfer-inventory-stock.php', $this->school->id, $this->item->id, $this->location->id, $this->destination->id, '4'];
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string> both outputs
     */
    private function race(array $a, array $b): array
    {
        $processA = new Process($a);
        $processB = new Process($b);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        return [$processA->getOutput(), $processB->getOutput()];
    }

    /** @param  list<string>  $outputs */
    private function assertOneWinner(array $outputs, string $race): void
    {
        $won = array_values(array_filter($outputs, fn (string $o) => ! str_starts_with($o, 'rejected:')));
        $lost = array_values(array_filter($outputs, fn (string $o) => str_starts_with($o, 'rejected:')));
        $this->assertCount(1, $won, "{$race}: exactly one succeeds ".json_encode($outputs));
        $this->assertCount(1, $lost, $race);
        $this->assertStringContainsString('InsufficientStockException', $lost[0], "{$race}: the loser re-checked under the lock (never a deadlock)");

        $context = app(TenantContext::class);
        $balance = $context->withSchool($this->school, fn () => InventoryStockBalance::query()->where('inventory_location_id', $this->location->id)->value('quantity_on_hand'));
        $this->assertSame('1.000', $balance, "{$race}: never negative, never a lost update");
        $outgoing = $context->withSchool($this->school, fn () => StockMovement::query()->where('from_location_id', $this->location->id)->count());
        $this->assertSame(1, $outgoing, "{$race}: one movement for the one winner");
    }

    #[Test]
    public function a_write_off_and_an_issue_never_overspend_the_same_stock(): void
    {
        $this->assertOneWinner($this->race($this->writeOff(), $this->issue()), 'write-off vs issue');
    }

    #[Test]
    public function two_write_offs_never_remove_more_than_existed(): void
    {
        $this->assertOneWinner($this->race($this->writeOff(), $this->writeOff()), 'write-off vs write-off');
    }

    #[Test]
    public function an_issue_and_a_transfer_from_the_same_stock_serialize(): void
    {
        $this->assertOneWinner($this->race($this->issue(), $this->transfer()), 'issue vs transfer');
    }

    #[Test]
    public function a_write_off_and_a_transfer_from_the_same_stock_serialize(): void
    {
        $this->assertOneWinner($this->race($this->writeOff(), $this->transfer()), 'write-off vs transfer');
    }
}
