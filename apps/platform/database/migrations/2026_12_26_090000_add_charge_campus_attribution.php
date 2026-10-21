<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.7G-1A (ADR 0076 §49): every Charge stores the historical Campus its
     * obligation was attributed to when it was created -- `charges.campus_id`,
     * written once, frozen, NULL = whole-School only (never "every Campus").
     * It replaces re-deriving a Charge's Campus from Enrollments on every
     * read, which a later Enrollment cancellation or same-year placement could
     * move (ADR 0076 §48.5, finding F-1).
     *
     * - Column: nullable `campus_id`, bound to the Charge's School by the
     *   composite key `charges_campus_fk` (`ON DELETE RESTRICT`; Campuses are
     *   deactivated, never deleted, and deactivation never touches it).
     * - Frozen: `fees_reject_recognized_charge_mutation` (the existing
     *   recognized-fields trigger) now also refuses any `campus_id` change;
     *   the one-time cancellation stays the only UPDATE.
     * - Source-bound: a new Charge's Campus must equal the source row that
     *   records it -- the fee assessment's Enrollment, the late fee's source
     *   Charge, the Library fine's Loan, the Canteen order's Outlet
     *   (`*_charge_campus_guard`). A manual Charge has no source row: its
     *   Campus is decided by ChargeService::assess() from the placement on
     *   the assessment date, under lock.
     *
     * Backfill by provenance (never the current Campus, never the latest
     * Enrollment): fee run -> the stored assessment Enrollment; late fee ->
     * its source Charge; Canteen -> the Outlet (fixed at creation); Library
     * -> the Loan (frozen at checkout); manual -> the one Campus of the
     * Student's non-cancelled placements in effect on the School-local
     * creation date, else NULL. A Charge whose context is ambiguous (several
     * provenance rows, several placements that day, a placement in effect
     * then that has since changed, or one recorded later but backdated over
     * that day) aborts the WHOLE migration,
     * rewriting nothing -- an owner remediation decision, never a guess.
     * ORG.7G-1A preflight: every local database unambiguous.
     *
     * Rollback is refused once a stored Campus differs from what the
     * pre-ORG.7G-1A runtime derivation would now return (dropping the
     * column would silently re-home that debt): see rollbackBlockers().
     */
    public function up(): void
    {
        $db = DB::connection();
        $this->assertBypassesRls($db);

        // Nothing that decides an attribution changes between the check and
        // the backfill (released at the migration's commit).
        $db->statement('LOCK TABLE charges, student_enrollments, fee_assessments, late_fee_assessments, canteen_orders, canteen_outlets, library_fines, library_loans IN SHARE MODE');

        $ambiguous = $this->ambiguousCharges($db);
        if ($ambiguous !== []) {
            $listed = implode('; ', array_map(fn ($r) => "charge {$r->id} ({$r->source}): {$r->ambiguity}", array_slice($ambiguous, 0, 20)));
            throw new RuntimeException('ORG.7G-1A: '.count($ambiguous)." Charge(s) have an ambiguous historical Campus. Nothing was changed; this needs an owner remediation decision (ADR 0076 §49). {$listed}");
        }

        $db->statement('ALTER TABLE charges ADD COLUMN campus_id uuid NULL');
        $db->statement('ALTER TABLE charges ADD CONSTRAINT charges_campus_fk
            FOREIGN KEY (campus_id, school_id) REFERENCES campuses (id, school_id) ON DELETE RESTRICT');
        $db->statement('CREATE INDEX charges_school_campus_index ON charges (school_id, campus_id)');

        $db->update('UPDATE charges c SET campus_id = r.campus_id FROM ('.$this->derivationSql().') r
            WHERE r.id = c.id AND r.campus_id IS NOT NULL AND r.ambiguity IS NULL');

        $db->unprepared(self::immutabilityFunction(withCampus: true));

        $db->unprepared(<<<'SQL'
            CREATE FUNCTION fees_charge_campus_matches(p_charge uuid, p_school uuid, p_campus uuid, p_source text) RETURNS void
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                SELECT c.campus_id INTO v_campus FROM charges c WHERE c.id = p_charge AND c.school_id = p_school;
                IF NOT FOUND OR v_campus IS DISTINCT FROM p_campus THEN
                    RAISE EXCEPTION 'charges: a % charge''s Campus must be its source''s historical Campus', p_source
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;
            REVOKE ALL ON FUNCTION fees_charge_campus_matches(uuid, uuid, uuid, text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION fees_charge_campus_matches(uuid, uuid, uuid, text) TO school_os_app;

            CREATE FUNCTION fee_assessments_charge_campus_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                SELECT e.campus_id INTO v_campus FROM student_enrollments e
                 WHERE e.id = NEW.student_enrollment_id AND e.school_id = NEW.school_id;
                PERFORM fees_charge_campus_matches(NEW.charge_id, NEW.school_id, v_campus, 'fee-run');
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER fee_assessments_charge_campus_guard BEFORE INSERT ON fee_assessments
                FOR EACH ROW EXECUTE FUNCTION fee_assessments_charge_campus_guard();

            CREATE FUNCTION late_fee_assessments_charge_campus_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                SELECT c.campus_id INTO v_campus FROM charges c
                 WHERE c.id = NEW.source_charge_id AND c.school_id = NEW.school_id;
                PERFORM fees_charge_campus_matches(NEW.charge_id, NEW.school_id, v_campus, 'late-fee');
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER late_fee_assessments_charge_campus_guard BEFORE INSERT ON late_fee_assessments
                FOR EACH ROW EXECUTE FUNCTION late_fee_assessments_charge_campus_guard();

            CREATE FUNCTION library_fines_charge_campus_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                IF NEW.charge_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT l.campus_id INTO v_campus FROM library_loans l
                 WHERE l.id = NEW.library_loan_id AND l.school_id = NEW.school_id;
                PERFORM fees_charge_campus_matches(NEW.charge_id, NEW.school_id, v_campus, 'Library fine');
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER library_fines_charge_campus_guard BEFORE INSERT OR UPDATE OF charge_id ON library_fines
                FOR EACH ROW EXECUTE FUNCTION library_fines_charge_campus_guard();

            CREATE FUNCTION canteen_orders_charge_campus_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                IF NEW.charge_id IS NULL OR (TG_OP = 'UPDATE' AND NEW.charge_id IS NOT DISTINCT FROM OLD.charge_id) THEN
                    RETURN NEW;
                END IF;
                SELECT o.campus_id INTO v_campus FROM canteen_outlets o
                 WHERE o.id = NEW.outlet_id AND o.school_id = NEW.school_id
                   FOR SHARE;
                PERFORM fees_charge_campus_matches(NEW.charge_id, NEW.school_id, v_campus, 'Canteen');
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER canteen_orders_charge_campus_guard BEFORE INSERT OR UPDATE OF charge_id ON canteen_orders
                FOR EACH ROW EXECUTE FUNCTION canteen_orders_charge_campus_guard();
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection();
        $this->assertBypassesRls($db);

        $blockers = $this->rollbackBlockers($db);
        if ($blockers > 0) {
            throw new RuntimeException("ORG.7G-1A: rollback refused -- {$blockers} Charge(s) store a historical Campus that the pre-ORG.7G-1A runtime derivation would no longer return; dropping charges.campus_id would re-home that debt (ADR 0076 §49). Nothing was changed.");
        }

        $db->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS canteen_orders_charge_campus_guard ON canteen_orders;
            DROP FUNCTION IF EXISTS canteen_orders_charge_campus_guard();
            DROP TRIGGER IF EXISTS library_fines_charge_campus_guard ON library_fines;
            DROP FUNCTION IF EXISTS library_fines_charge_campus_guard();
            DROP TRIGGER IF EXISTS late_fee_assessments_charge_campus_guard ON late_fee_assessments;
            DROP FUNCTION IF EXISTS late_fee_assessments_charge_campus_guard();
            DROP TRIGGER IF EXISTS fee_assessments_charge_campus_guard ON fee_assessments;
            DROP FUNCTION IF EXISTS fee_assessments_charge_campus_guard();
            DROP FUNCTION IF EXISTS fees_charge_campus_matches(uuid, uuid, uuid, text);
            SQL);
        $db->unprepared(self::immutabilityFunction(withCampus: false));
        $db->statement('DROP INDEX IF EXISTS charges_school_campus_index');
        $db->statement('ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_campus_fk');
        $db->statement('ALTER TABLE charges DROP COLUMN IF EXISTS campus_id');
    }

    /**
     * Every Charge's historical Campus by provenance: (id, source, campus_id,
     * ambiguity). Read-only; also used by the ORG.7G-1A tests.
     */
    public function derivationSql(): string
    {
        return <<<'SQL'
            WITH prov AS (
                SELECT c.id, c.school_id, c.student_id, c.academic_year_id, c.created_at,
                       (c.created_at AT TIME ZONE 'UTC' AT TIME ZONE s.timezone)::date AS d,
                       (SELECT count(*) FROM fee_assessments x WHERE x.charge_id = c.id)
                         + (SELECT count(*) FROM late_fee_assessments x WHERE x.charge_id = c.id)
                         + (SELECT count(*) FROM canteen_orders x WHERE x.charge_id = c.id)
                         + (SELECT count(*) FROM library_fines x WHERE x.charge_id = c.id) AS links,
                       (SELECT x.student_enrollment_id FROM fee_assessments x WHERE x.charge_id = c.id LIMIT 1) AS enrollment_id,
                       (SELECT x.source_charge_id FROM late_fee_assessments x WHERE x.charge_id = c.id LIMIT 1) AS source_charge_id,
                       (SELECT x.outlet_id FROM canteen_orders x WHERE x.charge_id = c.id LIMIT 1) AS outlet_id,
                       (SELECT x.library_loan_id FROM library_fines x WHERE x.charge_id = c.id LIMIT 1) AS loan_id
                  FROM charges c
                  JOIN schools s ON s.id = c.school_id
            ), base AS (
                SELECT p.id, p.source_charge_id,
                       CASE WHEN p.links > 1 THEN 'several'
                            WHEN p.enrollment_id IS NOT NULL THEN 'fee_run'
                            WHEN p.source_charge_id IS NOT NULL THEN 'late_fee'
                            WHEN p.outlet_id IS NOT NULL THEN 'canteen'
                            WHEN p.loan_id IS NOT NULL THEN 'library'
                            ELSE 'manual' END AS source,
                       (SELECT e.campus_id FROM student_enrollments e WHERE e.id = p.enrollment_id) AS run_campus,
                       (SELECT o.campus_id FROM canteen_outlets o WHERE o.id = p.outlet_id) AS outlet_campus,
                       (SELECT l.campus_id FROM library_loans l WHERE l.id = p.loan_id) AS loan_campus,
                       (SELECT array_agg(DISTINCT e.campus_id) FROM student_enrollments e
                         WHERE e.school_id = p.school_id AND e.student_id = p.student_id AND e.academic_year_id = p.academic_year_id
                           AND e.status <> 'cancelled' AND e.starts_on <= p.d AND (e.ends_on IS NULL OR e.ends_on >= p.d)) AS valid_now,
                       (SELECT array_agg(DISTINCT e.campus_id) FROM student_enrollments e
                         WHERE e.school_id = p.school_id AND e.student_id = p.student_id AND e.academic_year_id = p.academic_year_id
                           AND e.created_at <= p.created_at AND e.starts_on <= p.d
                           AND (e.ends_on IS NULL OR e.ends_on >= p.d OR e.updated_at > p.created_at)) AS open_then,
                       EXISTS (SELECT 1 FROM student_enrollments e
                         WHERE e.school_id = p.school_id AND e.student_id = p.student_id AND e.academic_year_id = p.academic_year_id
                           AND e.status <> 'cancelled' AND e.starts_on <= p.d AND (e.ends_on IS NULL OR e.ends_on >= p.d)
                           AND e.created_at > p.created_at) AS backdated
                  FROM prov p
            ), own AS (
                SELECT b.id, b.source, b.source_charge_id,
                       CASE b.source
                            WHEN 'fee_run' THEN b.run_campus
                            WHEN 'canteen' THEN b.outlet_campus
                            WHEN 'library' THEN b.loan_campus
                            WHEN 'manual' THEN CASE WHEN cardinality(b.valid_now) = 1 THEN b.valid_now[1] END
                       END AS campus_id,
                       CASE
                            WHEN b.source = 'several' THEN 'several provenance records'
                            WHEN b.source <> 'manual' THEN NULL
                            WHEN coalesce(cardinality(b.valid_now), 0) > 1 THEN 'several placements in effect on the creation date'
                            WHEN b.backdated THEN 'a placement covering the creation date was recorded after it'
                            WHEN b.open_then IS NOT NULL AND NOT (b.open_then <@ coalesce(b.valid_now, '{}'::uuid[])) THEN 'a placement in effect when it was created has since changed'
                       END AS ambiguity
                  FROM base b
            )
            SELECT o.id, o.source,
                   CASE WHEN o.source = 'late_fee' THEN src.campus_id ELSE o.campus_id END AS campus_id,
                   CASE WHEN o.source = 'late_fee' THEN
                            CASE WHEN src.id IS NULL THEN 'source charge not found'
                                 WHEN src.ambiguity IS NOT NULL OR src.source = 'late_fee' THEN 'source charge is ambiguous'
                            END
                        ELSE o.ambiguity END AS ambiguity
              FROM own o
              LEFT JOIN own src ON src.id = o.source_charge_id
            SQL;
    }

    /**
     * The backfill and the rollback guard read every School's rows: on a role
     * subject to FORCE RLS they would silently see none (and backfill
     * nothing, or allow a lossy rollback). Fail closed instead.
     */
    private function assertBypassesRls(ConnectionInterface $db): void
    {
        $role = $db->selectOne('SELECT rolsuper OR rolbypassrls AS bypass FROM pg_roles WHERE rolname = current_user');
        if ($role === null || ! $role->bypass) {
            throw new RuntimeException('ORG.7G-1A: this migration must run on the admin connection with RLS bypass (ADR 0021); nothing was changed.');
        }
    }

    /** @return list<object{id: string, source: string, ambiguity: string}> */
    public function ambiguousCharges(ConnectionInterface $db): array
    {
        return $db->select('SELECT r.id, r.source, r.ambiguity FROM ('.$this->derivationSql().') r WHERE r.ambiguity IS NOT NULL ORDER BY r.id');
    }

    /**
     * Charges whose stored Campus the pre-ORG.7G-1A runtime derivation
     * (StudentEnrollmentReadService::campusAttribution(): one Campus among
     * the year's non-cancelled placements, else the one in effect on the
     * creation date, else none) would not reproduce.
     */
    public function rollbackBlockers(ConnectionInterface $db): int
    {
        return (int) $db->selectOne(<<<'SQL'
            SELECT count(*) AS n
              FROM charges c
              JOIN schools s ON s.id = c.school_id
             WHERE c.campus_id IS DISTINCT FROM (
                    SELECT CASE
                             WHEN count(DISTINCT e.campus_id) = 1 THEN min(e.campus_id::text)::uuid
                             ELSE (SELECT CASE WHEN count(DISTINCT o.campus_id) = 1 THEN min(o.campus_id::text)::uuid END
                                     FROM student_enrollments o
                                    WHERE o.student_id = c.student_id AND o.academic_year_id = c.academic_year_id
                                      AND o.status <> 'cancelled'
                                      AND o.starts_on <= (c.created_at AT TIME ZONE 'UTC' AT TIME ZONE s.timezone)::date
                                      AND (o.ends_on IS NULL OR o.ends_on >= (c.created_at AT TIME ZONE 'UTC' AT TIME ZONE s.timezone)::date))
                           END
                      FROM student_enrollments e
                     WHERE e.student_id = c.student_id AND e.academic_year_id = c.academic_year_id AND e.status <> 'cancelled')
            SQL)->n;
    }

    /** The recognized-fields trigger function, with or without the ORG.7G-1A `campus_id` freeze. */
    private static function immutabilityFunction(bool $withCampus): string
    {
        $campus = $withCampus ? "\n                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id" : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION fees_reject_recognized_charge_mutation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS \$\$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.receivable_ledger_account_id IS DISTINCT FROM OLD.receivable_ledger_account_id
                    OR NEW.revenue_ledger_account_id IS DISTINCT FROM OLD.revenue_ledger_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at{$campus}
                THEN
                    RAISE EXCEPTION 'charge % recognized financial fields are immutable after creation', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancelled_at IS NOT NULL AND (
                    NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                    OR NEW.cancellation_journal_entry_id IS DISTINCT FROM OLD.cancellation_journal_entry_id
                ) THEN
                    RAISE EXCEPTION 'charge % cancellation is final and cannot be altered', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancellation_journal_entry_id IS NULL AND NEW.cancellation_journal_entry_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM journal_entries
                        WHERE id = NEW.cancellation_journal_entry_id
                          AND school_id = NEW.school_id
                          AND reversal_of_journal_entry_id = NEW.journal_entry_id
                    ) THEN
                        RAISE EXCEPTION 'cancellation_journal_entry_id % is not the reversal of charge %''s recognition journal %',
                            NEW.cancellation_journal_entry_id, NEW.id, NEW.journal_entry_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$;
            SQL;
    }
};
