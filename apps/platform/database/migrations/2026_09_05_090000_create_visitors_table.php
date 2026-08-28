<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10C -- the Visitor directory/reference entity
     * (docs/modules/VISITOR.md "Visitor directory model"). Deliberately
     * minimal: `full_name` + optional `phone` only -- no date of birth,
     * gender, address, employer, government-ID, photo, or biometric
     * data (docs/security/DATA-CLASSIFICATION.md's new Visitor row;
     * see VISITOR.md "Security / data classification" for the full
     * reasoning).
     *
     * `status` (active|inactive) is an ordinary reference-lifecycle
     * flag, NOT a security blocklist -- see VISITOR.md "Active/inactive
     * is not blocklisting". There is no `blocked`/`banned`/`watchlist`/
     * `risk_level` column and none is planned for this checkpoint.
     */
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 32)->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
        });

        TenantRls::enable('visitors');
    }

    public function down(): void
    {
        TenantRls::disable('visitors');
        Schema::dropIfExists('visitors');
    }
};
