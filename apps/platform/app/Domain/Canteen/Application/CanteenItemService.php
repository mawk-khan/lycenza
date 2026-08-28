<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Thin CRUD-with-audit for the Canteen menu catalogue, matching
 * App\Domain\AcademicStructure\Application\AcademicYearService's level
 * of complexity (CLAUDE.md rule 76). Recipe (CanteenItemInventoryRequirement)
 * mutation lives in the separate App\Domain\Canteen\Application\CanteenRecipeService
 * because of its own real locking invariant -- see that class's
 * docblock.
 */
class CanteenItemService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, price: string}  $data
     */
    public function create(School $school, array $data, ?User $actor = null): CanteenItem
    {
        return DB::transaction(function () use ($school, $data, $actor) {
            $item = CanteenItem::query()->create([
                ...$data,
                'school_id' => $school->id,
                'currency' => 'INR',
                'status' => 'active',
            ]);

            $this->audit->school($school, 'canteen.item.created', actor: $actor, subject: $item, metadata: [
                'itemId' => $item->id,
                'code' => $item->code,
                'price' => $item->price,
            ]);

            return $item;
        });
    }

    /**
     * @param  array<string, string>  $data  may contain 'name'/'price'/'status' keys
     */
    public function update(School $school, CanteenItem $item, array $data, ?User $actor = null): CanteenItem
    {
        return DB::transaction(function () use ($school, $item, $data, $actor) {
            $before = $item->only(array_keys($data));
            $item->update($data);

            $this->audit->school($school, 'canteen.item.updated', actor: $actor, subject: $item, metadata: [
                'itemId' => $item->id,
                'priceBefore' => $before['price'] ?? null,
                'priceAfter' => $data['price'] ?? null,
                'statusBefore' => $before['status'] ?? null,
                'statusAfter' => $data['status'] ?? null,
            ]);

            return $item->refresh();
        });
    }
}
