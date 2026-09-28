<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.10A (ADR 0056 section 14): Laravel's stock password-reset token
 * table is removed. Nothing ever wrote it (the stock broker was dormant --
 * no route, no controller, no call); account recovery credentials live in
 * `account_recovery_requests`. Without the table the stock broker cannot
 * work even if someone reintroduced a call (the architecture test forbids
 * that as well).
 *
 * down() recreates the original empty shape. Any row the table might have
 * held was an unusable stock token (nothing could consume it), so nothing of
 * value is lost in either direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
