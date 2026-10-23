<?php

namespace App\Domain\Inventory\Application;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ORG.9C-3 (ADR 0076 §71): the Inventory stock register (INV-016 by Campus,
 * INV-023 by Location) and the month view (INV-020), read on demand from the
 * ONE append-only ledger -- no second balance table, no stored opening or
 * closing, nothing written.
 *
 * Scope first, filters second: every query starts from the reader's
 * Locations (InventoryStockAccess, the stored Location Campus; a School-wide
 * Location is whole-School scope only), and a Campus, Location or Item
 * filter only narrows that set. Dates are School-local days.
 *
 * - register(): one row per movement, newest first (occurred_at, id),
 *   visible on the existing rule -- every Location it touches is in scope.
 * - month(): per Item, for the selected set of Locations, the opening
 *   quantity before the month's first School-local instant, what came in
 *   and went out during the month, and the closing quantity
 *   (opening + the signed month), in ONE set-based query
 *   (StockMovementEffect). A transfer inside the set nets to zero; a
 *   transfer across its edge counts at the in-set end only, without naming
 *   the other Location.
 */
final class InventoryStockHistory
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly InventoryStockAccess $access,
        private readonly DepartmentReference $departments,
    ) {}

    /**
     * @param  array{campus_id?: ?string, location_id?: ?string, item?: ?string, movement_type?: ?string, from?: ?string, to?: ?string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function register(School $school, CampusScope $scope, array $filters, int $page): LengthAwarePaginator
    {
        $query = $this->access->movements($scope);

        if (($filters['campus_id'] ?? null) !== null || ($filters['location_id'] ?? null) !== null) {
            // A movement belongs to a Campus or Location when either end is there (both ends are in scope already).
            $query->where(fn ($q) => $q->whereIn('from_location_id', $this->setAgain($scope, $filters))->orWhereIn('to_location_id', $this->setAgain($scope, $filters)));
        }
        $this->filterItem($query, $filters['item'] ?? null);
        if (($filters['movement_type'] ?? null) !== null) {
            $query->where('movement_type', $filters['movement_type']);
        }
        if (($filters['from'] ?? null) !== null) {
            $query->where('occurred_at', '>=', SchoolTimezone::startOfDayUtc($school, $filters['from']));
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('occurred_at', '<', SchoolTimezone::startOfNextDayUtc($school, $filters['to']));
        }

        $paginator = $query->with(['item:id,code,name,unit_of_measure', 'fromLocation:id,code,name', 'toLocation:id,code,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $names = $this->departments->names($school, $paginator->getCollection()->pluck('recipient_department_id')->filter()->unique()->values()->all());

        /** @var LengthAwarePaginator<int, array<string, mixed>> $rows */
        $rows = $paginator->through(fn (StockMovement $m) => [
            'id' => $m->id,
            'occurredAt' => $m->occurred_at->toIso8601String(),
            'movementType' => $m->movement_type,
            'itemCode' => $m->item->code,
            'itemName' => $m->item->name,
            'unitOfMeasure' => $m->item->unit_of_measure,
            'quantity' => $m->quantity,
            'fromLocation' => $m->fromLocation?->code,
            'toLocation' => $m->toLocation?->code,
            'recipientDepartment' => $m->recipient_department_id === null ? null : ($names[$m->recipient_department_id] ?? null),
            'writeOffReason' => $m->write_off_reason,
        ]);

        return $rows;
    }

    /**
     * @param  array{campus_id?: ?string, location_id?: ?string, item?: ?string}  $filters
     * @return array{month: string, from: string, to: string, rows: LengthAwarePaginator<int, array<string, mixed>>}
     */
    public function month(School $school, CampusScope $scope, string $month, array $filters, int $page): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, SchoolTimezone::resolve($school));
        $today = SchoolTimezone::today($school);
        if ($first === null || $first->toDateString() > $today) {
            throw new InvalidArgumentException('The month must not be in the future.');
        }
        $last = min($first->endOfMonth()->toDateString(), $today);
        $start = SchoolTimezone::startOfDayUtc($school, $first->toDateString());
        $end = SchoolTimezone::startOfNextDayUtc($school, $last);

        // The selected Locations are a handful of ids: resolve them once and test
        // membership against that literal set -- never a per-movement join (the
        // ORG.9C-1 lesson: joins on thin statistics run as nested loops). A
        // whole-School reader with no filter selects every Location of the School.
        $everyLocation = $scope->isWholeSchool() && ($filters['campus_id'] ?? null) === null && ($filters['location_id'] ?? null) === null;
        $ids = $everyLocation ? [] : ($this->filteredLocations($scope, $filters['campus_id'] ?? null, $filters['location_id'] ?? null)
            ?? $this->access->locations($scope)->select('id'))->pluck('id')->all();
        $set = '{'.implode(',', $ids).'}';
        $from = $everyLocation ? 'm.from_location_id IS NOT NULL' : 'm.from_location_id = ANY(?::uuid[])';
        $to = $everyLocation ? 'm.to_location_id IS NOT NULL' : 'm.to_location_id = ANY(?::uuid[])';
        // Every use of $from / $to below carries its own binding of the set.
        $fromBindings = $everyLocation ? [] : [$set];
        $toBindings = $everyLocation ? [] : [$set];
        $signed = StockMovementEffect::signSql('m.movement_type', $from, $to).' * m.quantity';
        $inMonth = 'm.occurred_at >= ?';

        $item = $filters['item'] ?? null;

        $signedBindings = [...$toBindings, ...$fromBindings, ...$fromBindings, ...$toBindings, ...$fromBindings];
        $aggregates = DB::query()
            ->fromSub(DB::table('stock_movements as m')
                ->where('m.school_id', $school->id)
                ->where('m.occurred_at', '<', $end)
                ->when($item !== null, fn ($q) => $q->whereIn('m.inventory_item_id', $this->filterItemColumns(InventoryItem::query(), (string) $item)->select('id')))
                ->when(! $everyLocation, fn ($q) => $q->whereRaw("({$from} OR {$to})", [...$fromBindings, ...$toBindings]))
                ->groupBy('m.inventory_item_id')
                ->select('m.inventory_item_id')
                ->selectRaw("COALESCE(SUM({$signed}) FILTER (WHERE m.occurred_at < ?), 0) AS opening", [...$signedBindings, $start])
                ->selectRaw("COALESCE(SUM(m.quantity) FILTER (WHERE {$inMonth} AND m.movement_type = 'receipt' AND {$to}), 0) AS received", [$start, ...$toBindings])
                ->selectRaw("COALESCE(SUM(m.quantity) FILTER (WHERE {$inMonth} AND m.movement_type = 'transfer' AND {$to} AND NOT ({$from})), 0) AS transferred_in", [$start, ...$toBindings, ...$fromBindings])
                ->selectRaw("COALESCE(SUM(m.quantity) FILTER (WHERE {$inMonth} AND m.movement_type = 'issue' AND {$from}), 0) AS issued", [$start, ...$fromBindings])
                ->selectRaw("COALESCE(SUM(m.quantity) FILTER (WHERE {$inMonth} AND m.movement_type = 'write_off' AND {$from}), 0) AS written_off", [$start, ...$fromBindings])
                ->selectRaw("COALESCE(SUM(m.quantity) FILTER (WHERE {$inMonth} AND m.movement_type = 'transfer' AND {$from} AND NOT ({$to})), 0) AS transferred_out", [$start, ...$fromBindings, ...$toBindings])
                ->selectRaw("COALESCE(SUM({$signed}), 0) AS closing", $signedBindings), 'a')
            ->join('inventory_items as i', 'i.id', '=', 'a.inventory_item_id')
            ->orderBy('i.code')
            ->orderBy('i.id')
            ->get(['a.*', 'i.code', 'i.name', 'i.unit_of_measure']);

        $rows = $aggregates
            ->map(fn (object $r) => [
                'itemId' => $r->inventory_item_id,
                'itemCode' => $r->code,
                'itemName' => $r->name,
                'unitOfMeasure' => $r->unit_of_measure,
                'opening' => self::qty($r->opening),
                'received' => self::qty($r->received),
                'transferredIn' => self::qty($r->transferred_in),
                'issued' => self::qty($r->issued),
                'writtenOff' => self::qty($r->written_off),
                'transferredOut' => self::qty($r->transferred_out),
                'closing' => self::qty($r->closing),
            ])
            // An Item whose only history in the set is internal transfers before and during the month shows nothing.
            ->reject(fn (array $r) => collect($r)->only(['opening', 'received', 'transferredIn', 'issued', 'writtenOff', 'transferredOut', 'closing'])->every(fn (string $q) => bccomp($q, '0', 3) === 0))
            ->values();

        /** @var LengthAwarePaginator<int, array<string, mixed>> $paged */
        $paged = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values()->all(), $rows->count(), self::PER_PAGE, $page);

        return ['month' => $first->format('Y-m'), 'from' => $first->toDateString(), 'to' => $last, 'rows' => $paged];
    }

    /**
     * The reader's Locations narrowed by a Campus and/or Location filter, as an
     * id subquery; null when no filter is given (the whole scope). A filter
     * outside the scope selects nothing -- it never widens.
     *
     * @return Builder<InventoryLocation>|null
     */
    private function filteredLocations(CampusScope $scope, ?string $campusId, ?string $locationId): ?Builder
    {
        if ($campusId === null && $locationId === null) {
            return null;
        }

        return $this->access->locations($scope)
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->when($locationId !== null, fn ($q) => $q->whereKey($locationId))
            ->select('id');
    }

    /**
     * A second, identical id subquery (a query builder joined twice must not be shared).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<InventoryLocation>
     */
    private function setAgain(CampusScope $scope, array $filters): Builder
    {
        return $this->filteredLocations($scope, $filters['campus_id'] ?? null, $filters['location_id'] ?? null)
            ?? $this->access->locations($scope)->select('id');
    }

    /** @param  Builder<StockMovement>  $query */
    private function filterItem(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }
        $query->whereIn('inventory_item_id', $this->filterItemColumns(InventoryItem::query(), $term)->select('id'));
    }

    /**
     * @param  Builder<InventoryItem>  $query
     * @return Builder<InventoryItem>
     */
    private function filterItemColumns(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return $query->where(fn ($w) => $w->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
    }

    private static function qty(mixed $value): string
    {
        return bcadd((string) $value, '0', 3);
    }
}
