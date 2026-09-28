<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.10A (ADR 0056 section 10.1): a password reset (self-service or
 * operator) terminates the User's active elevation with the closed reason
 * `credential_reset`, mirrored here in `school_elevations_end_check`.
 *
 * down(): refuses while any elevation carries the reason (elevation rows
 * are history, never rewritten); otherwise restores the previous check.
 */
return new class extends Migration
{
    private const PREVIOUS = "'actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked', 'school_left_group', 'group_authority_revoked', 'group_inactive', 'school_suspended'";

    public function up(): void
    {
        $this->replaceCheck(self::PREVIOUS.", 'credential_reset'");
    }

    public function down(): void
    {
        if (DB::table('school_elevations')->where('end_reason', 'credential_reset')->exists()) {
            throw new RuntimeException('school_elevations: rows ended by credential_reset are history; this migration cannot be rolled back while they exist.');
        }

        $this->replaceCheck(self::PREVIOUS);
    }

    private function replaceCheck(string $terminated): void
    {
        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_end_check');
        DB::statement(<<<SQL
            ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_end_check CHECK (
                (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
                OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
                OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
                OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ({$terminated}))
            )
            SQL);
    }
};
