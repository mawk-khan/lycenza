<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E21.3A (ADR 0064): the authoritative financial period and the immutable
 * period identity of every journal entry.
 *
 * - `financial_periods`: one School financial year per row. Boundaries
 *   follow the School's financial-year start month (`fee_settings`,
 *   default April), and `period_key` is the receipt series key ("2026-27",
 *   `payments_fy_series_key`). Statuses are `open` and `closed`, with no
 *   intermediate state because close is one transaction. Once written,
 *   boundaries never change, a closed period never changes, nothing is
 *   deleted, and periods never overlap.
 * - `journal_entries.financial_period_id`: assigned on INSERT by
 *   `journal_entries_assign_financial_period` from the School-local
 *   posting date. That covers every posting path (LedgerService, raw SQL).
 *   The period row must already exist: the application creates it first
 *   (`FinancialPeriod::ensureContaining`, a UUIDv7 id per ADR 0019, with
 *   boundaries from `finance_period_bounds`). The trigger holds it FOR
 *   SHARE, which conflicts with a close's FOR UPDATE. Posting into a closed
 *   period is refused (SQLSTATE 55000, `financial_period_closed`), and so is
 *   a posting with no period (`no_financial_period`).
 * - No period may be created before a closed period, so closed history
 *   can never gain a new period in a gap.
 * - Entries posted before this migration keep a NULL period until
 *   `platform:finance-periods-backfill` maps them. The only writer is the
 *   narrow `finance_assign_journal_entry_period`: tenant-tied, NULL ->
 *   containing period only (journal_entries stays append-only for the
 *   runtime role).
 * - The start month can no longer change once any period exists for the
 *   School (i.e. once anything has been posted). That tightens the earlier
 *   "until the first receipt" rule, so existing periods can never be
 *   reinterpreted. A month change and a School's first period creation
 *   serialize on the same advisory lock; the loser is refused.
 *
 * Rollback removes the mechanism. It refuses while any period is closed,
 * because a closed period's baselines are authoritative Finance evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->string('period_key', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->char('close_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'starts_on']);
            $table->unique(['school_id', 'period_key']);
        });

        DB::statement("ALTER TABLE financial_periods ADD CONSTRAINT financial_periods_shape_check CHECK (
                starts_on < ends_on
            AND status IN ('open', 'closed')
            AND (status = 'closed') = (closed_at IS NOT NULL)
            AND (status = 'closed') = (close_fingerprint IS NOT NULL))");
        TenantRls::enable('financial_periods');
        TenantRls::revokeDelete('financial_periods');

        DB::unprepared(<<<'SQL'
            -- Boundaries, School and key are immutable; status moves open -> closed
            -- only; a closed period never changes; periods never overlap; no
            -- period is created before a closed one. Creating the identical
            -- period twice (two first postings racing) is a silent no-op.
            CREATE FUNCTION financial_periods_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    PERFORM pg_advisory_xact_lock(hashtextextended('financial_periods:' || NEW.school_id::text, 0));
                    IF EXISTS (SELECT 1 FROM financial_periods p WHERE p.school_id = NEW.school_id
                                AND p.starts_on = NEW.starts_on AND p.ends_on = NEW.ends_on) THEN
                        RETURN NULL;
                    END IF;
                    IF EXISTS (SELECT 1 FROM financial_periods p WHERE p.school_id = NEW.school_id
                                AND p.status = 'closed' AND p.starts_on > NEW.starts_on) THEN
                        RAISE EXCEPTION 'financial_period_closed: no period may open before a closed period' USING ERRCODE = 'object_not_in_prerequisite_state';
                    END IF;
                    IF EXISTS (SELECT 1 FROM financial_periods p WHERE p.school_id = NEW.school_id
                                AND p.starts_on <= NEW.ends_on AND NEW.starts_on <= p.ends_on) THEN
                        RAISE EXCEPTION 'financial periods may not overlap' USING ERRCODE = 'exclusion_violation';
                    END IF;
                    -- The boundaries must still follow the start month in force now: a
                    -- start-month change takes this same lock (fee_settings trigger).
                    IF NOT EXISTS (SELECT 1 FROM finance_period_bounds(NEW.school_id, NEW.starts_on) b
                                    WHERE b.starts_on = NEW.starts_on AND b.ends_on = NEW.ends_on AND b.period_key = NEW.period_key) THEN
                        RAISE EXCEPTION 'financial period boundaries no longer match the start month' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status <> 'open' THEN
                        RAISE EXCEPTION 'a financial period is created open' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'a closed financial period is immutable' USING ERRCODE = 'object_not_in_prerequisite_state';
                END IF;
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.period_key IS DISTINCT FROM OLD.period_key
                   OR NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.ends_on IS DISTINCT FROM OLD.ends_on THEN
                    RAISE EXCEPTION 'financial period boundaries are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_financial_periods_guard BEFORE INSERT OR UPDATE ON financial_periods
                FOR EACH ROW EXECUTE FUNCTION financial_periods_guard();

            -- The one period-boundary rule: the financial year containing a
            -- School-local date, from the School's start month (default April).
            -- The key is the receipt series key ("2026-27"). Read-only.
            CREATE FUNCTION finance_period_bounds(p_school_id uuid, p_local date)
                RETURNS TABLE (period_key text, starts_on date, ends_on date) LANGUAGE plpgsql STABLE AS $$
            DECLARE
                v_month integer;
                v_year integer;
                v_start date;
            BEGIN
                SELECT financial_year_start_month INTO v_month FROM fee_settings WHERE school_id = p_school_id;
                v_month := coalesce(v_month, 4);
                v_year := extract(year FROM p_local)::integer;
                IF extract(month FROM p_local)::integer < v_month THEN
                    v_year := v_year - 1;
                END IF;
                v_start := make_date(v_year, v_month, 1);
                RETURN QUERY SELECT payments_fy_series_key(v_start::timestamp, v_month)::text, v_start,
                    (v_start + interval '1 year' - interval '1 day')::date;
            END;
            $$;

            -- Every new journal entry resolves to exactly one OPEN period of its
            -- School, held FOR SHARE until the posting commits.
            CREATE FUNCTION journal_entries_assign_financial_period() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_tz text;
                v_local date;
                v_period financial_periods%ROWTYPE;
            BEGIN
                SELECT timezone INTO v_tz FROM schools WHERE id = NEW.school_id;
                v_local := ((NEW.posted_at AT TIME ZONE 'UTC') AT TIME ZONE coalesce(v_tz, 'UTC'))::date;

                IF NEW.financial_period_id IS NULL THEN
                    SELECT id INTO NEW.financial_period_id FROM financial_periods
                     WHERE school_id = NEW.school_id AND starts_on <= v_local AND v_local <= ends_on;
                    IF NEW.financial_period_id IS NULL THEN
                        RAISE EXCEPTION 'no_financial_period: no financial period contains %', v_local USING ERRCODE = 'object_not_in_prerequisite_state';
                    END IF;
                END IF;

                SELECT * INTO v_period FROM financial_periods WHERE id = NEW.financial_period_id AND school_id = NEW.school_id FOR SHARE;
                IF v_period.id IS NULL OR v_local < v_period.starts_on OR v_local > v_period.ends_on THEN
                    RAISE EXCEPTION 'journal entry date % is outside its financial period', v_local USING ERRCODE = 'check_violation';
                END IF;
                IF v_period.status <> 'open' THEN
                    RAISE EXCEPTION 'financial_period_closed: % is closed', v_period.period_key USING ERRCODE = 'object_not_in_prerequisite_state';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->uuid('financial_period_id')->nullable();
            $table->index(['school_id', 'financial_period_id']);
        });
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_financial_period_fk
            FOREIGN KEY (financial_period_id, school_id) REFERENCES financial_periods (id, school_id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_journal_entries_assign_financial_period BEFORE INSERT ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_entries_assign_financial_period();

            -- Backfill only: sets a NULL period to the period that contains the
            -- entry's School-local date, for the caller's own School. Nothing else.
            CREATE FUNCTION finance_assign_journal_entry_period(p_entry_id uuid, p_period_id uuid) RETURNS boolean
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_school uuid := NULLIF(current_setting('app.current_school_id', true), '')::uuid;
                v_tz text;
                v_local date;
                v_entry public.journal_entries%ROWTYPE;
                v_period public.financial_periods%ROWTYPE;
            BEGIN
                IF v_school IS NULL THEN
                    RAISE EXCEPTION 'finance backfill needs the School context' USING ERRCODE = 'insufficient_privilege';
                END IF;
                SELECT * INTO v_entry FROM public.journal_entries WHERE id = p_entry_id AND school_id = v_school FOR UPDATE;
                IF v_entry.id IS NULL OR v_entry.financial_period_id IS NOT NULL THEN
                    RETURN false;
                END IF;
                SELECT * INTO v_period FROM public.financial_periods WHERE id = p_period_id AND school_id = v_school FOR SHARE;
                SELECT timezone INTO v_tz FROM public.schools WHERE id = v_school;
                v_local := ((v_entry.posted_at AT TIME ZONE 'UTC') AT TIME ZONE coalesce(v_tz, 'UTC'))::date;
                IF v_period.id IS NULL OR v_local < v_period.starts_on OR v_local > v_period.ends_on OR v_period.status <> 'open' THEN
                    RAISE EXCEPTION 'the period does not contain this entry or is closed' USING ERRCODE = 'check_violation';
                END IF;
                UPDATE public.journal_entries SET financial_period_id = p_period_id WHERE id = p_entry_id AND financial_period_id IS NULL;
                RETURN true;
            END;
            $$;

            -- The start month may not change once any period exists for the School
            -- (a first settings row counts as a change from the April default).
            -- It takes the period-creation lock, so it serializes with a period
            -- being created: whichever commits first wins, and the other is
            -- refused rather than leaving a period built on the old month. It only
            -- TRIES the lock: the settings path already holds the Fees settings
            -- lock that a posting's receipt takes later, so waiting here could
            -- deadlock. A busy lock means a period is being created right now,
            -- which refuses the change anyway.
            CREATE FUNCTION fee_settings_lock_start_month() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_old integer := 4;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    v_old := coalesce(OLD.financial_year_start_month, 4);
                END IF;
                IF coalesce(NEW.financial_year_start_month, 4) <> v_old THEN
                    IF NOT pg_try_advisory_xact_lock(hashtextextended('financial_periods:' || NEW.school_id::text, 0)) THEN
                        RAISE EXCEPTION 'the financial-year start month cannot change once financial periods exist (one is being created)'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM financial_periods WHERE school_id = NEW.school_id) THEN
                        RAISE EXCEPTION 'the financial-year start month cannot change once financial periods exist'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_fee_settings_lock_start_month BEFORE INSERT OR UPDATE ON fee_settings
                FOR EACH ROW EXECUTE FUNCTION fee_settings_lock_start_month();
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION finance_assign_journal_entry_period(uuid, uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION finance_assign_journal_entry_period(uuid, uuid) TO school_os_app');
    }

    public function down(): void
    {
        if (DB::table('financial_periods')->where('status', 'closed')->exists()) {
            throw new RuntimeException('Refusing to roll back: closed financial periods hold authoritative baselines (ADR 0064).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_fee_settings_lock_start_month ON fee_settings;
            DROP FUNCTION IF EXISTS fee_settings_lock_start_month();
            DROP FUNCTION IF EXISTS finance_assign_journal_entry_period(uuid, uuid);
            DROP TRIGGER IF EXISTS trg_journal_entries_assign_financial_period ON journal_entries;
            SQL);
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_financial_period_fk');
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'financial_period_id']);
            $table->dropColumn('financial_period_id');
        });
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS journal_entries_assign_financial_period();
            DROP FUNCTION IF EXISTS finance_period_bounds(uuid, date);
            DROP TRIGGER IF EXISTS trg_financial_periods_guard ON financial_periods;
            DROP FUNCTION IF EXISTS financial_periods_guard();
            SQL);
        TenantRls::disable('financial_periods');
        Schema::dropIfExists('financial_periods');
    }
};
