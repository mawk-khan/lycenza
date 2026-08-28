<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- a physical/logical canteen counter, backed by exactly
     * one `inventory_locations` row it issues stock from at fulfillment
     * time. `campus_id` is nullable, mirroring `inventory_locations.campus_id`'s
     * own nullability exactly (a School-wide canteen has no single
     * Campus; a multi-campus School's per-campus canteen does).
     *
     * Campus-consistency structural enforcement (checkpoint brief): an
     * Outlet naming BOTH a Campus and an InventoryLocation must never
     * disagree about which Campus the Location itself belongs to. This
     * is enforced with TWO composite foreign keys rather than a
     * trigger:
     *   - `canteen_outlets_location_fk`: `(inventory_location_id, school_id)`
     *     -> `inventory_locations(id, school_id)` -- always enforced,
     *     proves same-School Location existence regardless of Campus.
     *   - `canteen_outlets_location_campus_fk`: `(inventory_location_id, campus_id, school_id)`
     *     -> `inventory_locations(id, campus_id, school_id)` (the WIDER
     *     composite key added by this checkpoint's own
     *     `add_campus_composite_unique_to_inventory_locations_table`
     *     migration) -- when `canteen_outlets.campus_id` is NULL,
     *     PostgreSQL's standard multi-column FK NULL-skip semantics
     *     exempt the row from this SECOND FK's check entirely (a
     *     composite FK is only evaluated when every referencing column
     *     is non-null); when non-null, both FKs are enforced together,
     *     which is only satisfiable if `inventory_locations.campus_id`
     *     for that row equals this Outlet's own `campus_id`. Verified
     *     directly against real PostgreSQL (see
     *     tests/Feature/Postgres/CanteenOutletsRlsIsolationTest.php's
     *     "campus consistency" test) -- a mismatched-campus insert is
     *     rejected at the database level, no trigger needed.
     *
     * Case-insensitive code uniqueness mirrors `ledger_accounts_school_id_code_ci_unique`'s
     * exact expression-index pattern (App\Support\NormalizesCode /
     * NormalizesCodeInput handle the write side; this index is the
     * database-level backstop against a raw insert bypassing them).
     */
    public function up(): void
    {
        Schema::create('canteen_outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->uuid('inventory_location_id');
            $table->string('code', 64);
            $table->string('name');
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['inventory_location_id', 'school_id'], 'canteen_outlets_location_fk')
                ->references(['id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();

            $table->foreign(['inventory_location_id', 'campus_id', 'school_id'], 'canteen_outlets_location_campus_fk')
                ->references(['id', 'campus_id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE canteen_outlets ADD CONSTRAINT canteen_outlets_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX canteen_outlets_school_id_code_ci_unique '.
            'ON canteen_outlets (school_id, upper(code))'
        );

        TenantRls::enable('canteen_outlets');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_outlets');
        Schema::dropIfExists('canteen_outlets');
    }
};
