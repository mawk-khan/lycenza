<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0O.11A (ADR 0031 "Implementation amendment -- manual / offline
     * settlement recording", ADR 0057 section 3): a `payments` row now has
     * exactly one of two provenances, discriminated by `source`.
     *
     *   provider -- the 0G.5 shape, unchanged: `provider`,
     *               `provider_payment_reference` and `provider_event_id`
     *               all set (a real `payment_provider_events` row), no
     *               manual column.
     *   manual   -- a School user recorded a payment that already happened
     *               outside Lycenza: `method` (closed catalog),
     *               `recorded_by_user_id`, `idempotency_key` set, an
     *               optional bounded `manual_reference`, and NO provider
     *               column -- a manual Payment never manufactures provider
     *               evidence.
     *
     * `payments_source_shape_check` makes the two shapes mutually exclusive
     * for every role. The existing provider uniqueness constraints are
     * untouched (NULLs are distinct, so manual rows never meet them).
     * `settled_at` is the occurred-at instant for both sources and
     * `created_at` the server-set recorded-at instant; the table stays
     * append-only (the table-level REVOKE covers the new columns).
     *
     * `payments_manual_idempotency_unique (school_id, idempotency_key)` is
     * the authoritative claim for one logical manual recording request
     * (rule 30) -- stored on the Payment itself, so the claim and the
     * business effect commit atomically.
     *
     * `journal_entries_payment_reversal_guard`: a Payment's settlement
     * journal entry can no longer be reversed through the generic ledger
     * reversal (that left the Payment and its allocations posted against
     * a reversed entry). Payments owns the guard because Payments already
     * depends on Finance; Finance never reads `payments`. SECURITY INVOKER:
     * the reversal runs under the School's own TenantContext, so the
     * School's Payments are visible to the subquery under RLS.
     *
     * Reversible: down() refuses while a manual Payment exists (its row
     * cannot be represented in the 0G.5 NOT NULL shape) -- delete those
     * rows deliberately first; nothing is dropped silently.
     */
    public function up(): void
    {
        // DEFAULT 'provider' keeps every 0G.5-shaped insert valid
        // unchanged; it can never disguise a manual row, because a
        // 'provider' row without provider evidence fails
        // payments_source_shape_check.
        DB::statement("ALTER TABLE payments ADD COLUMN source varchar(16) NOT NULL DEFAULT 'provider'");
        DB::statement('ALTER TABLE payments ADD COLUMN method varchar(32) NULL');
        DB::statement('ALTER TABLE payments ADD COLUMN manual_reference varchar(64) NULL');
        DB::statement('ALTER TABLE payments ADD COLUMN recorded_by_user_id uuid NULL');
        DB::statement('ALTER TABLE payments ADD COLUMN idempotency_key uuid NULL');

        DB::statement('ALTER TABLE payments ALTER COLUMN provider DROP NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN provider_payment_reference DROP NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN provider_event_id DROP NOT NULL');

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_recorded_by_user_fk '.
            'FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_source_check '.
            "CHECK (source IN ('provider', 'manual'))"
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_method_check '.
            "CHECK (method IS NULL OR method IN ('cash', 'bank_transfer', 'cheque'))"
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_manual_reference_format_check '.
            "CHECK (manual_reference IS NULL OR manual_reference ~ '^[A-Za-z0-9]([A-Za-z0-9 ./_-]{0,62}[A-Za-z0-9])?\$')"
        );

        DB::statement(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_source_shape_check CHECK (
                (
                    source = 'provider'
                    AND provider IS NOT NULL
                    AND provider_payment_reference IS NOT NULL
                    AND provider_event_id IS NOT NULL
                    AND method IS NULL
                    AND manual_reference IS NULL
                    AND recorded_by_user_id IS NULL
                    AND idempotency_key IS NULL
                ) OR (
                    source = 'manual'
                    AND provider IS NULL
                    AND provider_payment_reference IS NULL
                    AND provider_event_id IS NULL
                    AND method IS NOT NULL
                    AND recorded_by_user_id IS NOT NULL
                    AND idempotency_key IS NOT NULL
                )
            )
        SQL);

        DB::statement(
            'CREATE UNIQUE INDEX payments_manual_idempotency_unique '.
            'ON payments (school_id, idempotency_key)'
        );

        DB::statement('CREATE INDEX payments_school_source_index ON payments (school_id, source)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_reject_payment_journal_reversal() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM payments
                    WHERE journal_entry_id = NEW.reversal_of_journal_entry_id
                ) THEN
                    RAISE EXCEPTION
                        'journal_entries_payment_reversal_guard: journal entry % is owned by a Payment and cannot be reversed directly',
                        NEW.reversal_of_journal_entry_id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER journal_entries_payment_reversal_guard '.
            'BEFORE INSERT ON journal_entries '.
            'FOR EACH ROW WHEN (NEW.reversal_of_journal_entry_id IS NOT NULL) '.
            'EXECUTE FUNCTION payments_reject_payment_journal_reversal()'
        );
    }

    public function down(): void
    {
        if (DB::table('payments')->where('source', 'manual')->exists()) {
            throw new RuntimeException(
                'Cannot roll back manual payment recording: manual Payments exist and have no 0G.5 provider shape. '.
                'Remove them deliberately first.'
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS journal_entries_payment_reversal_guard ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS payments_reject_payment_journal_reversal()');

        DB::statement('DROP INDEX IF EXISTS payments_school_source_index');
        DB::statement('DROP INDEX IF EXISTS payments_manual_idempotency_unique');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_source_shape_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_manual_reference_format_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_source_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_recorded_by_user_fk');

        DB::statement('ALTER TABLE payments ALTER COLUMN provider SET NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN provider_payment_reference SET NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN provider_event_id SET NOT NULL');

        DB::statement('ALTER TABLE payments DROP COLUMN idempotency_key');
        DB::statement('ALTER TABLE payments DROP COLUMN recorded_by_user_id');
        DB::statement('ALTER TABLE payments DROP COLUMN manual_reference');
        DB::statement('ALTER TABLE payments DROP COLUMN method');
        DB::statement('ALTER TABLE payments DROP COLUMN source');
    }
};
