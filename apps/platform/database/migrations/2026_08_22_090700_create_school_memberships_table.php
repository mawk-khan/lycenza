<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data (deliberately, per this checkpoint's brief):
     * "which Schools does this user belong to" must be answerable
     * without already being inside a School's tenant context (e.g. a
     * school-switcher UI, section 22) -- a chicken-and-egg problem if
     * this table were RLS-protected by school_id. Every School access
     * must originate from an explicit row here (root CLAUDE.md rule:
     * "no cross-tenant leakage" / section 16) -- never inferred from a
     * user id appearing in some unrelated table.
     *
     * What role(s) a membership grants IS tenant-owned and RLS-protected
     * -- see membership_role_assignments below. The `unique(id,
     * school_id)` constraint here exists so that table can enforce a
     * composite foreign key preventing the class of bug in section 34
     * (a role assignment's school_id silently mismatching its
     * membership's actual school_id).
     */
    public function up(): void
    {
        Schema::create('school_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('status')->default('invited'); // invited|active|suspended
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'school_id']);
            $table->unique(['id', 'school_id']); // enables composite FKs from tenant-owned children
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_memberships');
    }
};
