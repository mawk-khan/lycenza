<?php

namespace App\Domain\Inventory\Application;

use App\Domain\Inventory\Application\Exceptions\ConcurrentStockConflictException;
use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Application\Exceptions\ItemNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\SameLocationTransferException;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * The ONLY sanctioned write path for Inventory stock
 * (docs/modules/INVENTORY.md "Stock truth architecture"). No
 * controller, model, or other service mutates
 * `inventory_stock_balances`/`stock_movements` directly.
 *
 * Every method: validates Item/Location lifecycle, validates the
 * quantity against the Item's unit policy, enters ONE
 * `DB::transaction()`, resolves the required balance row(s)
 * concurrency-safely (`ensureBalanceRow()` -- see its own docblock),
 * locks them, re-validates the stock rule AFTER the lock is held,
 * mutates the balance with exact-decimal (BCMath) arithmetic, inserts
 * exactly one `StockMovement`, audits, and returns. A CHECK-constraint
 * violation that somehow survives past the lock (should not occur in
 * normal operation; the row lock is the primary mechanism) is
 * translated to `ConcurrentStockConflictException` -- the database's
 * `inventory_stock_balances_quantity_non_negative_check` remains the
 * final structural backstop regardless.
 */
class InventoryStockService
{
    private const PG_CHECK_VIOLATION = '23514';

    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Receive stock into a Location. Concurrent first-ever receipts to
     * the same Item x Location cannot lose an update -- both resolve
     * to the SAME canonical balance row via `ensureBalanceRow()`, then
     * serialize on that row's lock.
     */
    public function receive(InventoryItem $item, InventoryLocation $location, string $quantity, ?User $actor = null): StockMovement
    {
        $this->assertAvailable($item, $location);
        $this->assertValidQuantity($item, $quantity);

        return $this->guarded(function () use ($item, $location, $quantity, $actor) {
            return DB::transaction(function () use ($item, $location, $quantity, $actor) {
                $balance = $this->lockBalance($this->ensureBalanceRow($item, $location));

                $balance->quantity_on_hand = bcadd($balance->quantity_on_hand, $quantity, 3);
                $balance->save();

                $movement = $this->recordMovement($item, 'receipt', null, $location, $quantity);

                $this->audit->school($item->school, 'inventory.stock.received', actor: $actor, subject: $movement, metadata: [
                    'movementId' => $movement->id,
                    'itemId' => $item->id,
                    'itemCode' => $item->code,
                    'toLocationId' => $location->id,
                    'toLocationCode' => $location->code,
                    'quantity' => $quantity,
                ]);

                return $movement;
            });
        });
    }

    /**
     * Issue stock from a Location. REJECTS if the Location's balance
     * (re-checked AFTER the row lock is held, never before) is less
     * than the requested quantity -- `quantity_on_hand` can never go
     * negative, proven under real two-process concurrency
     * (tests/Feature/Inventory/InventoryStockConcurrencyTest.php).
     */
    public function issue(InventoryItem $item, InventoryLocation $location, string $quantity, ?User $actor = null): StockMovement
    {
        $this->assertAvailable($item, $location);
        $this->assertValidQuantity($item, $quantity);

        return $this->guarded(function () use ($item, $location, $quantity, $actor) {
            return DB::transaction(function () use ($item, $location, $quantity, $actor) {
                $balance = $this->lockBalance($this->ensureBalanceRow($item, $location));

                if (bccomp($balance->quantity_on_hand, $quantity, 3) < 0) {
                    throw new InsufficientStockException;
                }

                $balance->quantity_on_hand = bcsub($balance->quantity_on_hand, $quantity, 3);
                $balance->save();

                $movement = $this->recordMovement($item, 'issue', $location, null, $quantity);

                $this->audit->school($item->school, 'inventory.stock.issued', actor: $actor, subject: $movement, metadata: [
                    'movementId' => $movement->id,
                    'itemId' => $item->id,
                    'itemCode' => $item->code,
                    'fromLocationId' => $location->id,
                    'fromLocationCode' => $location->code,
                    'quantity' => $quantity,
                ]);

                return $movement;
            });
        });
    }

    /**
     * Transfer stock between two Locations for the same Item, as ONE
     * atomic transaction -- never an issue+receipt pair (checkpoint
     * brief section 22). Both balance rows are resolved
     * concurrency-safely BEFORE locking (section 23 -- lock order
     * cannot be determined from ids that do not exist yet), then
     * locked in ASCENDING id order, never a fixed source-then-
     * destination role order: both locked resources are the SAME
     * entity type (a stock balance row), so a role-based order would
     * deadlock two opposing simultaneous transfers (A->B and B->A each
     * locking their own "source" first). Ascending-id ordering is
     * direction-independent and provably deadlock-free -- proven under
     * real two-process concurrency
     * (InventoryStockConcurrencyTest::opposing_concurrent_transfers...).
     * Insufficient source stock throws before either balance row is
     * touched; the whole transaction rolls back, including any
     * newly-created zero-balance destination row.
     */
    public function transfer(InventoryItem $item, InventoryLocation $from, InventoryLocation $to, string $quantity, ?User $actor = null): StockMovement
    {
        if ($from->id === $to->id) {
            throw new SameLocationTransferException;
        }

        $this->assertAvailable($item, $from);
        $this->assertAvailable($item, $to);
        $this->assertValidQuantity($item, $quantity);

        return $this->guarded(function () use ($item, $from, $to, $quantity, $actor) {
            return DB::transaction(function () use ($item, $from, $to, $quantity, $actor) {
                $sourceBalanceId = $this->ensureBalanceRow($item, $from);
                $destBalanceId = $this->ensureBalanceRow($item, $to);

                $orderedIds = [$sourceBalanceId, $destBalanceId];
                sort($orderedIds, SORT_STRING);

                $locked = [];
                foreach ($orderedIds as $id) {
                    $locked[$id] = $this->lockBalance($id);
                }

                $sourceBalance = $locked[$sourceBalanceId];
                $destBalance = $locked[$destBalanceId];

                if (bccomp($sourceBalance->quantity_on_hand, $quantity, 3) < 0) {
                    throw new InsufficientStockException;
                }

                $sourceBalance->quantity_on_hand = bcsub($sourceBalance->quantity_on_hand, $quantity, 3);
                $sourceBalance->save();

                $destBalance->quantity_on_hand = bcadd($destBalance->quantity_on_hand, $quantity, 3);
                $destBalance->save();

                $movement = $this->recordMovement($item, 'transfer', $from, $to, $quantity);

                $this->audit->school($item->school, 'inventory.stock.transferred', actor: $actor, subject: $movement, metadata: [
                    'movementId' => $movement->id,
                    'itemId' => $item->id,
                    'itemCode' => $item->code,
                    'fromLocationId' => $from->id,
                    'fromLocationCode' => $from->code,
                    'toLocationId' => $to->id,
                    'toLocationCode' => $to->code,
                    'quantity' => $quantity,
                ]);

                return $movement;
            });
        });
    }

    /**
     * The concurrency-safe missing-balance-row primitive
     * (docs/modules/INVENTORY.md "Balance creation primitive"). A
     * naive `firstOrCreate()` then `lockForUpdate()` is NOT safe here:
     * two processes may both observe no row exists, and a row that
     * does not yet exist cannot be locked. Instead: attempt a
     * conflict-safe `INSERT ... ON CONFLICT DO NOTHING` against
     * `inventory_stock_balances_item_location_unique`, then
     * unconditionally re-read the canonical row by its natural key.
     * Exactly one of any number of concurrent callers physically
     * inserts; every other caller's insert is silently ignored by
     * PostgreSQL (no exception raised), and every caller's subsequent
     * re-read resolves to the SAME row id -- the winner's. This is
     * what proven under real two-process concurrency
     * (InventoryStockConcurrencyTest::concurrent_first_receipts...)
     * makes both "no lost update" and "no unique-constraint leak to
     * the client" true simultaneously.
     */
    private function ensureBalanceRow(InventoryItem $item, InventoryLocation $location): string
    {
        /** @var string|null $existing */
        $existing = InventoryStockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $location->id)
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        DB::table('inventory_stock_balances')->insertOrIgnore([[
            'id' => (string) new UuidV7,
            'school_id' => $item->school_id,
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity_on_hand' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return InventoryStockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $location->id)
            ->firstOrFail()
            ->id;
    }

    private function lockBalance(string $balanceId): InventoryStockBalance
    {
        return InventoryStockBalance::query()->where('id', $balanceId)->lockForUpdate()->firstOrFail();
    }

    private function recordMovement(InventoryItem $item, string $type, ?InventoryLocation $from, ?InventoryLocation $to, string $quantity): StockMovement
    {
        return StockMovement::query()->create([
            'school_id' => $item->school_id,
            'inventory_item_id' => $item->id,
            'movement_type' => $type,
            'from_location_id' => $from?->id,
            'to_location_id' => $to?->id,
            'quantity' => $quantity,
            'occurred_at' => now(),
        ]);
    }

    private function assertAvailable(InventoryItem $item, InventoryLocation $location): void
    {
        if (! $item->isActive()) {
            throw new ItemNotAvailableException;
        }

        if (! $location->isActive()) {
            throw new LocationNotAvailableException;
        }
    }

    /**
     * Quantity must be strictly positive and, for a non-fractional
     * unit (each/box/packet), a whole number -- derived from the
     * Item's OWN `unit_of_measure`, never a separate stored flag
     * (checkpoint brief section 8). Exact string comparison
     * throughout, never a float cast.
     */
    private function assertValidQuantity(InventoryItem $item, string $quantity): void
    {
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new InvalidQuantityException('Quantity must be greater than zero.');
        }

        if (! $item->allowsFractionalQuantity() && ! $this->isWholeNumber($quantity)) {
            throw new InvalidQuantityException("Quantity for unit '{$item->unit_of_measure}' must be a whole number.");
        }
    }

    private function isWholeNumber(string $quantity): bool
    {
        $fractional = explode('.', $quantity, 2)[1] ?? '';

        return $fractional === '' || (bool) preg_match('/^0+$/', $fractional);
    }

    /**
     * Translates a surviving CHECK-constraint violation (SQLSTATE
     * 23514) into the domain conflict exception -- never lets a raw
     * PostgreSQL exception reach an API client (checkpoint brief
     * section 19).
     */
    private function guarded(callable $callback): StockMovement
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if ($this->isCheckViolation($e)) {
                throw new ConcurrentStockConflictException;
            }

            throw $e;
        }
    }

    private function isCheckViolation(Throwable $e): bool
    {
        return $e instanceof QueryException && $e->getCode() === self::PG_CHECK_VIOLATION;
    }
}
