<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10E -- a physical stock-holding location
     * (docs/modules/INVENTORY.md "InventoryLocation model").
     * School-owned; `campus_id` is nullable and OPTIONAL -- the exact
     * "Campus scoping decision" precedent `library_copies.campus_id`/
     * `transport_routes.campus_id` already established (a physical
     * object/place that may sit at one specific Campus in a
     * multi-campus School; a single-campus School simply leaves this
     * null). Deliberately NOT Hostel's pattern (Hostel requires
     * Campus) -- a Hostel is inherently one physical building with no
     * School-wide equivalent, whereas a central store genuinely can be
     * School-wide. No generic warehouse/location hierarchy.
     */
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->string('code', 64);
            $table->string('name');
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'status']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        TenantRls::enable('inventory_locations');
    }

    public function down(): void
    {
        TenantRls::disable('inventory_locations');
        Schema::dropIfExists('inventory_locations');
    }
};
