<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: structural foundation only for a future
     * School Group/Trust (ADR 0004). Membership in a group is NOT
     * itself permission to read a member school's tenant-owned data --
     * see school_group_members below and docs/security/AUTHORIZATION.md.
     */
    public function up(): void
    {
        Schema::create('school_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_groups');
    }
};
