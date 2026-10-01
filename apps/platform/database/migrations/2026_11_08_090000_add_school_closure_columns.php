<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E21.2F (E21-D11, docs/security/E21-RETENTION-DETERMINATION.md): the
 * durable record that a School is CLOSED. Closure is freeze -> retain ->
 * controlled purge.
 *
 * The freeze is the existing ADR 0047 `suspended` status. These columns
 * record that the suspension is a closure: when, why (a closed reason
 * code) and by whom. They are the current state; the history is the
 * platform audit.
 *
 * The database keeps the two consistent:
 * - a closed School is always `suspended`, so it cannot become `active`
 *   again without its closure being withdrawn in the same write (a
 *   reopen);
 * - the closure columns are set or cleared together.
 *
 * Only SchoolLifecycleService writes them. Nothing here deletes a School
 * (the runtime role still has no DELETE on `schools`).
 *
 * Rollback removes the mechanism only. A closed School stays `suspended`
 * (frozen, nothing deleted); it simply loses the closure marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE schools ADD CONSTRAINT schools_closure_shape_check
            CHECK ((closed_at IS NULL) = (closure_reason IS NULL)
               AND (closure_reason IS NULL OR closure_reason IN ('ceased_operations', 'contract_ended', 'merged_or_transferred')))");
        DB::statement("ALTER TABLE schools ADD CONSTRAINT schools_closed_is_frozen_check
            CHECK (closed_at IS NULL OR status = 'suspended')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE schools DROP CONSTRAINT IF EXISTS schools_closed_is_frozen_check');
        DB::statement('ALTER TABLE schools DROP CONSTRAINT IF EXISTS schools_closure_shape_check');

        Schema::table('schools', function (Blueprint $table): void {
            $table->dropForeign(['closed_by_user_id']);
            $table->dropColumn(['closed_at', 'closure_reason', 'closed_by_user_id']);
        });
    }
};
