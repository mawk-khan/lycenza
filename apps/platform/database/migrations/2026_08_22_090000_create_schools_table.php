<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: the tenant catalog itself. A School row is
     * not "owned by" a school_id -- its own `id` IS the tenant boundary
     * (ADR 0004, ADR 0020) -- so this table carries no RLS.
     */
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active'); // active|suspended|archived
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('default_locale')->default('en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
