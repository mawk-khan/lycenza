<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10D -- the Hostel reference entity
     * (docs/modules/HOSTEL.md "Hostel model"). A Hostel belongs to
     * exactly ONE Campus in this checkpoint (`campus_id` required,
     * unlike Transport's optional Route/Vehicle campus association --
     * a Hostel is a physical building that genuinely exists at one
     * Campus, it has no "School-wide shared" equivalent). Deliberately
     * minimal: `code`/`name`/`status` only -- no fees, warden/manager,
     * meal plan, gender policy, curfew, visitor policy, or medical
     * fields (none was an explicit product requirement for this
     * checkpoint).
     *
     * `status` (active|inactive) is an ordinary reference-lifecycle
     * flag, the same convention `TransportRoute`/`LibraryTitle`/
     * `Visitor` already use -- not a security/safety signal.
     */
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id');
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

        TenantRls::enable('hostels');
    }

    public function down(): void
    {
        TenantRls::disable('hostels');
        Schema::dropIfExists('hostels');
    }
};
