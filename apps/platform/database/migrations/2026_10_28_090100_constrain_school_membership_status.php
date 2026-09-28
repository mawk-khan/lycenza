<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.12B (ADR 0059 section 6.4): the documented membership status
 * convention -- `invited | active | suspended`, until now a code comment
 * only -- becomes a database guarantee. No new state is introduced:
 * `suspended` is the School-access kill switch that off-boarding uses, and
 * `invited` stays reserved (staff invitations keep their pending state on
 * the invitation row, never on a pre-created membership).
 *
 * The table is audited first: any other status value makes the migration
 * refuse (counts only), never rewrite a membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unknown = (int) DB::selectOne("SELECT count(*) AS c FROM school_memberships WHERE status NOT IN ('invited', 'active', 'suspended')")->c;

        if ($unknown > 0) {
            throw new RuntimeException("school_memberships_status: refusing -- {$unknown} membership(s) carry a status outside invited/active/suspended. No row was changed.");
        }

        DB::statement("ALTER TABLE school_memberships ADD CONSTRAINT school_memberships_status_check CHECK (status IN ('invited', 'active', 'suspended'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school_memberships DROP CONSTRAINT IF EXISTS school_memberships_status_check');
    }
};
