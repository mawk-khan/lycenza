<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: which capabilities a service identity is
     * granted -- reuses the existing `capabilities` catalog (ADR 0019's
     * string-PK exception) rather than inventing a parallel scope
     * system. A service token authenticating successfully must NOT
     * mean "access everything" (section 26) -- this table is what makes
     * that check possible.
     */
    public function up(): void
    {
        Schema::create('service_identity_capabilities', function (Blueprint $table) {
            $table->foreignUuid('service_identity_id')->constrained('service_identities')->cascadeOnDelete();
            $table->string('capability_key', 150);
            $table->foreign('capability_key')->references('key')->on('capabilities')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['service_identity_id', 'capability_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_identity_capabilities');
    }
};
