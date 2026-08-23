<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: a machine/service identity (e.g. "AI
     * Gateway"), distinguishable from a human User (section 25) --
     * never reuse `users` to represent an internal service. Only a
     * SHA-256 hash of the credential is stored, never the raw value
     * (section 27); the raw value is only ever visible to the operator
     * once, at generation time (see ServiceIdentityIssuer).
     */
    public function up(): void
    {
        Schema::create('service_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique(); // e.g. "ai-gateway"
            $table->string('name');
            $table->string('credential_hash');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_identities');
    }
};
