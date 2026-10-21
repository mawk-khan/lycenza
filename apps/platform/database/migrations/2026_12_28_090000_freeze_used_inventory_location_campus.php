<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7H-1 (ADR 0076 §54): an Inventory Location's Campus is operational
 * provenance. Once the Location has operational history its `campus_id` is
 * frozen -- A -> B, A -> NULL and NULL -> A are all refused -- so stock
 * history written at a Location can never be re-attributed to another
 * Campus (or to the whole School) by moving the Location. Before any use
 * the Campus may still be corrected. Database-enforced, race-free; the
 * ADR 0069 (S1) SubjectOffering classification freeze is the precedent.
 *
 * Evidence (any row, any state -- a cancelled Canteen Order is history too):
 *   stock_movements          from_location_id, to_location_id
 *   inventory_stock_balances inventory_location_id (current stock state)
 *   canteen_orders           inventory_location_id (the placement snapshot)
 * Canteen stock consumptions reference movements, so they are covered.
 * A Canteen Outlet naming the Location is configuration, not history (its
 * Campus-bound composite key already pins the Location's Campus).
 *
 * 1. inventory_location_has_operational_history(location, school): the ONE
 *    definition of "used", called by the trigger below and by
 *    InventoryLocationService. VOLATILE, so each call sees rows committed by
 *    writers it waited for.
 * 2. inventory_location_campus_freeze (BEFORE UPDATE OF campus_id on
 *    inventory_locations): an actual change (not a no-op) is refused while
 *    the predicate holds. The UPDATE has locked the Location row first, so
 *    the check runs after every concurrent evidence writer holding the row
 *    FOR SHARE has committed (and sees its row).
 * 3. inventory_location_evidence_guard(columns...) (BEFORE INSERT OR UPDATE
 *    OF those columns on each evidence table): takes each named Location FOR
 *    SHARE, so the first evidence and a Campus change always serialize --
 *    independent of which unique keys happen to include `campus_id`.
 *
 * Existing values are accepted as the provenance (the ORG.7H-1 audit found
 * no conflict and no recorded Campus change); nothing is rewritten. Rolling
 * back drops the rule and re-opens Campus changes of used Locations; it
 * changes no data.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> evidence table => Location columns */
    private const EVIDENCE = [
        'stock_movements' => ['from_location_id', 'to_location_id'],
        'inventory_stock_balances' => ['inventory_location_id'],
        'canteen_orders' => ['inventory_location_id'],
    ];

    public function up(): void
    {
        $exists = implode("\n                    OR ", array_map(
            fn (string $table, array $columns) => "EXISTS (SELECT 1 FROM public.{$table} e WHERE e.school_id = p_school AND ("
                .implode(' OR ', array_map(fn (string $column) => "e.{$column} = p_location", $columns)).'))',
            array_keys(self::EVIDENCE),
            self::EVIDENCE,
        ));

        DB::unprepared(<<<SQL
            CREATE FUNCTION inventory_location_has_operational_history(p_location uuid, p_school uuid) RETURNS boolean
                LANGUAGE sql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
                SELECT {$exists}
            \$\$;
            REVOKE ALL ON FUNCTION inventory_location_has_operational_history(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION inventory_location_has_operational_history(uuid, uuid) TO school_os_app;

            CREATE FUNCTION inventory_location_campus_freeze() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
            BEGIN
                IF NEW.campus_id IS NOT DISTINCT FROM OLD.campus_id THEN
                    RETURN NEW;
                END IF;
                IF public.inventory_location_has_operational_history(OLD.id, OLD.school_id) THEN
                    RAISE EXCEPTION 'inventory_location_campus_frozen: the Inventory Location has operational history; its Campus cannot change'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION inventory_location_campus_freeze() FROM PUBLIC;

            CREATE TRIGGER trg_inventory_location_campus_freeze
                BEFORE UPDATE OF campus_id ON inventory_locations
                FOR EACH ROW EXECUTE FUNCTION inventory_location_campus_freeze();

            CREATE FUNCTION inventory_location_evidence_guard() RETURNS trigger
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
                    IF location_id IS NULL OR (TG_OP = 'UPDATE' AND location_id IS NOT DISTINCT FROM (to_jsonb(OLD) ->> col)::uuid) THEN
                        CONTINUE;
                    END IF;
                    -- FOR SHARE: waits for (and then sees) a concurrent Campus change; makes a later one wait for this row.
                    -- A Location not visible here (another School, no context) is left to RLS and the composite keys.
                    PERFORM 1 FROM public.inventory_locations l
                     WHERE l.id = location_id AND l.school_id = NEW.school_id
                     FOR SHARE;
                END LOOP;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION inventory_location_evidence_guard() FROM PUBLIC;
            SQL);

        foreach (self::EVIDENCE as $table => $columns) {
            $of = implode(', ', $columns);
            $args = implode(', ', array_map(fn (string $column) => "'{$column}'", $columns));
            DB::unprepared("CREATE TRIGGER trg_inventory_location_evidence BEFORE INSERT OR UPDATE OF {$of} ON {$table}
                FOR EACH ROW EXECUTE FUNCTION inventory_location_evidence_guard({$args})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::EVIDENCE) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_inventory_location_evidence ON {$table}");
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_inventory_location_campus_freeze ON inventory_locations;
            DROP FUNCTION IF EXISTS inventory_location_evidence_guard();
            DROP FUNCTION IF EXISTS inventory_location_campus_freeze();
            DROP FUNCTION IF EXISTS inventory_location_has_operational_history(uuid, uuid);
            SQL);
    }
};
