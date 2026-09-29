<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FEE.1 (ADR 0062 §6, decision K1): minimal Finance-owned ledger
     * account administration. The table itself is unchanged; this
     * migration only adds the two database guarantees the new
     * `App\Domain\Finance\Application\LedgerAccountAdministrationService`
     * relies on:
     *
     * - an account's `type`, `currency` and `school_id` never change once
     *   any `journal_lines` row posts to it
     *   (`ledger_accounts_posted_identity_guard`). A type change on an
     *   unposted account stays possible at the database level, but no
     *   application path offers it;
     * - the runtime role can never hard-delete an account
     *   (`TenantRls::revokeDelete`). Accounts are deactivated, never
     *   deleted. A School's own cascade delete is unaffected.
     *
     * The trigger function is SECURITY INVOKER, so its `journal_lines`
     * lookup runs under the caller's RLS visibility. That is safe: an
     * UPDATE of `ledger_accounts` itself already requires the same
     * School's RLS context.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION finance_reject_posted_ledger_account_identity_change() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF (NEW.type IS DISTINCT FROM OLD.type
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id)
                   AND EXISTS (SELECT 1 FROM journal_lines WHERE ledger_account_id = OLD.id)
                THEN
                    RAISE EXCEPTION 'ledger account % type, currency and school are immutable after first posting', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER ledger_accounts_posted_identity_guard '.
            'BEFORE UPDATE ON ledger_accounts '.
            'FOR EACH ROW EXECUTE FUNCTION finance_reject_posted_ledger_account_identity_change()'
        );

        TenantRls::revokeDelete('ledger_accounts');
    }

    public function down(): void
    {
        DB::statement('GRANT DELETE ON ledger_accounts TO school_os_app');
        DB::statement('DROP TRIGGER IF EXISTS ledger_accounts_posted_identity_guard ON ledger_accounts');
        DB::statement('DROP FUNCTION IF EXISTS finance_reject_posted_ledger_account_identity_change()');
    }
};
