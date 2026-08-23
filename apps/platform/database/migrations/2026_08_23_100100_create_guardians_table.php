<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A: a Guardian is a permanent School-level identity, never
     * duplicated once per child -- a Guardian with several Students at
     * the same School is one row, linked to each Student through a
     * future StudentGuardianRelationship join table (deferred to the
     * next Phase 1A slice; see docs/modules/STUDENT-GUARDIAN-IDENTITY.md).
     * Deliberately independent of `users`: a Guardian is a domain
     * identity a User may later link to for portal access (future
     * GuardianUserLink, also deferred) -- this table carries no
     * user_id column.
     *
     * No contact fields (email/phone/address) on this table -- those
     * belong to a future GuardianContact model once this checkpoint's
     * architecture inspection has designed its PII/searchability
     * handling deliberately, not bolted on here.
     *
     * `unique(['id', 'school_id'])` follows the same forward-looking
     * composite-FK convention as the students migration.
     */
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
        });

        TenantRls::enable('guardians');
    }

    public function down(): void
    {
        TenantRls::disable('guardians');
        Schema::dropIfExists('guardians');
    }
};
