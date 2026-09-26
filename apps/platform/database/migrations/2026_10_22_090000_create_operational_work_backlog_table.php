<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.5A (ADR 0051 §11, "a permitted platform operational
 * aggregate"): the scheduling state of UNFINISHED durable work, readable
 * by operations status and the metrics scrape WITHOUT a School context
 * and without bypassing row-level security.
 *
 * `webhook_deliveries`, `communication_deliveries` and
 * `automation_executions` are tenant tables under forced RLS; counting
 * their backlog globally would need either a TenantContext per School or
 * an RLS bypass -- both refused (rule 26). Instead a trigger on each
 * mirrors ONLY the scheduling columns of unfinished rows into this table:
 * source, item id, state, when it entered that state, next attempt and
 * lease expiry. It holds no school_id, no payload, no destination, no
 * person and no content -- nothing a tenant could be harmed by -- so it is
 * a platform table without RLS (like `domain_event_outbox`). A row is
 * deleted as soon as its source row reaches a final state or is deleted.
 *
 * `state_since` is taken from the source row's `updated_at` when the
 * state CHANGES (and kept while the state is unchanged), so the 0O.4A
 * redispatch "updated_at bump" never resets how long an item has been
 * waiting.
 *
 * The triggers run as the invoking (runtime) role, which holds the
 * ordinary default privileges on this table. The backfill sets each
 * School's context in turn, so it works for a non-superuser migration
 * role under forced RLS as well.
 */
return new class extends Migration
{
    /** source => [table, unfinished states] */
    private const SOURCES = [
        'webhook' => ['webhook_deliveries', ['pending', 'retrying', 'delivering']],
        'communication' => ['communication_deliveries', ['pending', 'queued', 'sending']],
        'automation' => ['automation_executions', ['pending', 'running']],
    ];

    public function up(): void
    {
        Schema::create('operational_work_backlog', function (Blueprint $table) {
            $table->string('source', 16);
            $table->uuid('item_id');
            $table->string('state', 16);
            $table->timestamp('state_since');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();

            $table->primary(['source', 'item_id']);
            $table->index(['source', 'state']);
        });

        DB::statement("ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('webhook', 'communication', 'automation'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_operational_work_backlog() RETURNS trigger AS $$
            DECLARE
                work_source text := TG_ARGV[0];
                unfinished text[] := string_to_array(TG_ARGV[1], ',');
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM operational_work_backlog WHERE source = work_source AND item_id = OLD.id;
                    RETURN OLD;
                END IF;

                IF NOT (NEW.status = ANY (unfinished)) THEN
                    DELETE FROM operational_work_backlog WHERE source = work_source AND item_id = NEW.id;
                    RETURN NEW;
                END IF;

                INSERT INTO operational_work_backlog (source, item_id, state, state_since, next_attempt_at, lease_expires_at)
                VALUES (work_source, NEW.id, NEW.status, COALESCE(NEW.updated_at, now()), NEW.next_attempt_at, NEW.processing_lease_expires_at)
                ON CONFLICT (source, item_id) DO UPDATE SET
                    state_since = CASE WHEN operational_work_backlog.state = EXCLUDED.state
                        THEN operational_work_backlog.state_since ELSE EXCLUDED.state_since END,
                    state = EXCLUDED.state,
                    next_attempt_at = EXCLUDED.next_attempt_at,
                    lease_expires_at = EXCLUDED.lease_expires_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (self::SOURCES as $source => [$table, $states]) {
            DB::statement(sprintf(
                "CREATE TRIGGER trg_%s_operational_backlog AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION sync_operational_work_backlog('%s', '%s')",
                $table, $table, $source, implode(',', $states),
            ));
        }

        // Backfill unfinished work, one School context at a time (RLS applies
        // to a non-superuser migration role too).
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);

            foreach (self::SOURCES as $source => [$table, $states]) {
                $placeholders = implode(',', array_fill(0, count($states), '?'));
                DB::insert(
                    "INSERT INTO operational_work_backlog (source, item_id, state, state_since, next_attempt_at, lease_expires_at)
                     SELECT ?, id, status, COALESCE(updated_at, now()), next_attempt_at, processing_lease_expires_at
                     FROM {$table} WHERE status IN ({$placeholders})
                     ON CONFLICT (source, item_id) DO NOTHING",
                    [$source, ...$states],
                );
            }
        }

        DB::select("SELECT set_config('app.current_school_id', '', true)");
    }

    public function down(): void
    {
        foreach (self::SOURCES as [$table]) {
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_operational_backlog ON {$table}");
        }

        DB::statement('DROP FUNCTION IF EXISTS sync_operational_work_backlog()');
        Schema::dropIfExists('operational_work_backlog');
    }
};
