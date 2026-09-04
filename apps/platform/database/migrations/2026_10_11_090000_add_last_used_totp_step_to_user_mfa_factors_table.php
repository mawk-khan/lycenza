<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replay-security correction (closure blocker) for Phase 0H.4D-P1.
     *
     * The original `user_mfa_factors` migration (2026_10_10_090000)
     * stayed byte-identical -- this is unpublished but already the
     * preserved audited feature commit, so the fix is an additive
     * migration rather than an edit.
     *
     * `last_used_at` was being made to double as google2fa's 30-second
     * TOTP period counter (`time() / 30`, NOT a Unix epoch timestamp)
     * by round-tripping it through Carbon's setTimestamp()/
     * getTimestamp() -- a datetime column has no business holding a
     * period-counter value, and the closure audit proved this
     * corrupts `last_used_at` into a meaningless date (e.g.
     * `1970-01-01 00:00:01`) while also breaking the enrollment-time
     * replay floor (verifyKey() returns boolean `true`, not a step,
     * when no old step is supplied -- `(int) true === 1`).
     *
     * `last_used_totp_step` is the ONLY value ever passed to/read from
     * google2fa's `verifyKeyNewer($secret, $code, $oldTimestamp, ...)`
     * -- despite that parameter's library-given name, it is a period
     * counter (`time() / 30`), never epoch seconds. A `bigInteger` is
     * used (not `integer`) so this never approaches overflow the way a
     * 32-bit Unix timestamp eventually would. Nullable: a factor that
     * has never completed a successful TOTP verification has no floor
     * yet -- the first accepted step establishes it under the
     * transaction lock (see MfaChallengeService::verifyTotp()), it is
     * never derived from `last_used_at`.
     */
    public function up(): void
    {
        Schema::table('user_mfa_factors', function (Blueprint $table) {
            $table->bigInteger('last_used_totp_step')->nullable()->after('last_used_at');
        });

        DB::statement('ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_last_used_totp_step_non_negative CHECK (last_used_totp_step IS NULL OR last_used_totp_step >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_mfa_factors DROP CONSTRAINT IF EXISTS user_mfa_factors_last_used_totp_step_non_negative');

        Schema::table('user_mfa_factors', function (Blueprint $table) {
            $table->dropColumn('last_used_totp_step');
        });
    }
};
