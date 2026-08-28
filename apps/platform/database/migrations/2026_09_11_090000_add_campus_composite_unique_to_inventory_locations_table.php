<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 10F (Canteen foundation) -- purely additive. `canteen_outlets`
     * needs to structurally enforce that an Outlet's `campus_id` (when
     * set) actually matches its `inventory_location_id`'s own
     * `campus_id` -- i.e. an Outlet cannot claim a Campus different from
     * the Campus its backing InventoryLocation actually belongs to.
     * `inventory_locations` only carries `unique(['id', 'school_id'])`
     * from its own Phase 10E migration (see that migration) -- not
     * enough to let a THIRD table's composite foreign key also pin
     * `campus_id`. This migration adds the second, WIDER composite
     * unique key `(id, campus_id, school_id)` alongside (never
     * replacing) the existing one -- `canteen_outlets`' own migration
     * then declares a nullable composite FK against this new key; when
     * `canteen_outlets.campus_id IS NULL`, PostgreSQL's standard
     * multi-column FK NULL-skip semantics exempt the row from that
     * second FK's check entirely, while the FIRST (existing)
     * `(inventory_location_id, school_id)` FK still always enforces
     * same-School Location existence.
     *
     * Safe/additive: `id` is already the primary key (globally unique
     * on its own), so this new index does not change what rows are
     * PERMITTED in `inventory_locations` -- it only adds a second
     * reachable composite key surface for a future child table's FK.
     * No existing query, model, or migration is affected.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_locations ADD CONSTRAINT inventory_locations_id_campus_id_school_id_unique UNIQUE (id, campus_id, school_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_locations DROP CONSTRAINT IF EXISTS inventory_locations_id_campus_id_school_id_unique');
    }
};
