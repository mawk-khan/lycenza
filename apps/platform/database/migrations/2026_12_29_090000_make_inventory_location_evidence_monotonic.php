<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7H-1R (ADR 0076 §55): "used" is monotonic. The ORG.7H-1 Campus freeze
 * (2026_12_28_090000) decides "used" from the rows its one predicate,
 * inventory_location_has_operational_history(), reads -- so a runtime
 * session that could delete or re-point those rows could make a used
 * Location look unused and then move its Campus. This closes every such
 * path without a new table, column, marker or rewritten row:
 *
 *   stock_movements          the authoritative ledger: append-only for the
 *                            runtime role (no UPDATE, no DELETE). Nothing
 *                            updates or deletes a movement (INVENTORY.md
 *                            §16); reversal stays deferred.
 *   inventory_stock_balances current stock state: the quantity stays
 *                            writable, but a row is never removed (no
 *                            DELETE -- Inventory is tenant lifetime, E21)
 *                            and never moved to another Location.
 *   canteen_orders           DELETE was already revoked (Phase 10F); its
 *                            Location snapshot can no longer be re-pointed.
 *
 * The re-point refusal lives in the existing evidence guard (one function,
 * already fired BEFORE UPDATE OF exactly the Location columns): an UPDATE
 * that changes (or clears) an evidence row's Location raises
 * `inventory_location_evidence_immutable` for every role. A no-op write
 * still passes. The INSERT path (FOR SHARE, the first-use serialization)
 * is unchanged.
 *
 * Rolling back restores the ORG.7H-1 guard body and the runtime grants and
 * re-opens the evidence-erasure bypass (platform:verify-database then
 * reports the restored DELETE/UPDATE grants); it changes no data.
 */
return new class extends Migration
{
    /** The ORG.7H-1 INSERT-path body, shared by up() and down(). */
    private const LOCK = <<<'SQL'
                -- FOR SHARE: waits for (and then sees) a concurrent Campus change; makes a later one wait for this row.
                -- A Location not visible here (another School, no context) is left to RLS and the composite keys.
                PERFORM 1 FROM public.inventory_locations l
                 WHERE l.id = location_id AND l.school_id = NEW.school_id
                 FOR SHARE;
        SQL;

    public function up(): void
    {
        TenantRls::makeAppendOnly('stock_movements');
        TenantRls::revokeDelete('inventory_stock_balances');

        $this->replaceGuard(<<<'SQL'
                    IF TG_OP = 'UPDATE' AND location_id IS DISTINCT FROM (to_jsonb(OLD) ->> col)::uuid THEN
                        -- ORG.7H-1R: evidence is history; re-pointing (or clearing) it would let a used Location look unused.
                        RAISE EXCEPTION 'inventory_location_evidence_immutable: %.% is operational history; its Location cannot change', TG_TABLE_NAME, col
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF location_id IS NULL OR TG_OP = 'UPDATE' THEN
                        CONTINUE;
                    END IF;
            SQL);
    }

    public function down(): void
    {
        $this->replaceGuard(<<<'SQL'
                    IF location_id IS NULL OR (TG_OP = 'UPDATE' AND location_id IS NOT DISTINCT FROM (to_jsonb(OLD) ->> col)::uuid) THEN
                        CONTINUE;
                    END IF;
            SQL);

        DB::statement('GRANT DELETE ON inventory_stock_balances TO school_os_app');
        DB::statement('GRANT UPDATE, DELETE ON stock_movements TO school_os_app');
    }

    private function replaceGuard(string $skip): void
    {
        $lock = self::LOCK;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION inventory_location_evidence_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
            DECLARE
                col text;
                location_id uuid;
            BEGIN
                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF NOT (to_jsonb(NEW) ? col) THEN
                        -- A renamed column must never silently drop the lock (and reopen the first-use race).
                        RAISE EXCEPTION 'inventory_location_evidence_guard: %.% does not exist', TG_TABLE_NAME, col;
                    END IF;
                    location_id := (to_jsonb(NEW) ->> col)::uuid;
            {$skip}
            {$lock}
                END LOOP;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }
};
