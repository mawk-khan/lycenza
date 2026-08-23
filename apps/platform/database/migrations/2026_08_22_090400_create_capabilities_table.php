<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: the capability catalog
     * (docs/security/AUTHORIZATION.md). `key` is the primary key --
     * the one deliberate exception to the UUIDv7 convention, see
     * docs/architecture/adr/0019-identifier-strategy.md. Only the
     * minimal platform/school namespace needed to prove the
     * architecture is seeded in Phase 0B (database/seeders); future
     * modules reserve their own namespace (students.*, fees.*, ...).
     */
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->string('key', 150)->primary(); // e.g. "school.settings.manage"
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('namespace'); // platform|school
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
