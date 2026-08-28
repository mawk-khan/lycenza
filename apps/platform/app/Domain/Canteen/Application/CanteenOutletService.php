<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Thin CRUD-with-audit for the Canteen Outlet directory, matching
 * App\Domain\AcademicStructure\Application\AcademicYearService's level
 * of complexity (CLAUDE.md rule 76) -- no state machine, campus/
 * Location consistency is enforced structurally by the migration's
 * composite foreign keys (see that migration's docblock), not
 * re-validated here.
 */
class CanteenOutletService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, campus_id: ?string, inventory_location_id: string}  $data
     */
    public function create(School $school, array $data, ?User $actor = null): CanteenOutlet
    {
        return DB::transaction(function () use ($school, $data, $actor) {
            $outlet = CanteenOutlet::query()->create([
                ...$data,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            $this->audit->school($school, 'canteen.outlet.created', actor: $actor, subject: $outlet, metadata: [
                'outletId' => $outlet->id,
                'code' => $outlet->code,
                'inventoryLocationId' => $outlet->inventory_location_id,
            ]);

            return $outlet;
        });
    }

    /**
     * @param  array<string, string>  $data  may contain 'name'/'status' keys
     */
    public function update(School $school, CanteenOutlet $outlet, array $data, ?User $actor = null): CanteenOutlet
    {
        return DB::transaction(function () use ($school, $outlet, $data, $actor) {
            $before = $outlet->only(array_keys($data));
            $outlet->update($data);

            $this->audit->school($school, 'canteen.outlet.updated', actor: $actor, subject: $outlet, metadata: [
                'outletId' => $outlet->id,
                'statusBefore' => $before['status'] ?? null,
                'statusAfter' => $data['status'] ?? null,
            ]);

            return $outlet->refresh();
        });
    }
}
