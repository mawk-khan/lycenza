<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POR.4 (ADR 0070 §27): a retried Guardian reply must create one message,
 * never two. The reply form carries a server-issued key (the Finance/Fees
 * web-form convention, rules 30-31); it is claimed on the message itself:
 *
 * - `communication_messages_sender_idempotency_unique`: one message per
 *   (School, sender, key). The database, not a pre-check, is the claim.
 *   Two Schools, or two senders, reusing one literal key never collide.
 * - `communication_messages_idempotency_thread_check`: a key only ever sits
 *   on a conversation message (never an announcement's).
 *
 * Existing rows and the staff Hub path keep `idempotency_key` NULL. The
 * table is already tenant-owned with forced RLS (TenantRls::enable in its
 * creating migration); no policy changes here.
 *
 * Rollback: drops the index, the check and the column. Only de-duplication
 * metadata is lost; no message is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_messages ADD COLUMN idempotency_key uuid NULL');
        DB::statement(
            'ALTER TABLE communication_messages ADD CONSTRAINT communication_messages_idempotency_thread_check '.
            'CHECK (idempotency_key IS NULL OR thread_id IS NOT NULL)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX communication_messages_sender_idempotency_unique '.
            'ON communication_messages (school_id, sender_user_id, idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS communication_messages_sender_idempotency_unique');
        DB::statement('ALTER TABLE communication_messages DROP CONSTRAINT IF EXISTS communication_messages_idempotency_thread_check');

        if (Schema::hasColumn('communication_messages', 'idempotency_key')) {
            DB::statement('ALTER TABLE communication_messages DROP COLUMN idempotency_key');
        }
    }
};
