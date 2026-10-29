<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12G (ADR 0080 §12.4): two plain btree indexes so the Integration &
 * Device Readiness Hub's windowed EXISTS probes read only one School's recent
 * rows instead of every retained row for the School. No new table, column,
 * constraint, policy or state: RLS and every trigger are unchanged.
 *
 * - email_messages (school_id, created_at): "accepted in the last 7 days".
 * - webhook_deliveries (school_id, updated_at): "failed / delivered in the
 *   last 7 days".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->index(['school_id', 'created_at'], 'email_messages_school_id_created_at_index');
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->index(['school_id', 'updated_at'], 'webhook_deliveries_school_id_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('webhook_deliveries_school_id_updated_at_index');
        });
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropIndex('email_messages_school_id_created_at_index');
        });
    }
};
