<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.7D-5 (ADR 0076 §41): a Library loan keeps the Campus its physical
     * Copy was on at checkout -- its historical Campus, which Campus-scoped
     * circulation authority and history follow (never the borrower's current
     * Campus, never the Copy's later Campus).
     *
     * - `library_loans.campus_id` (nullable: a School-wide, NULL-Campus Copy
     *   makes a NULL-Campus loan), bound to the same School by a composite FK
     *   (`ON DELETE RESTRICT`, like the Copy's own key).
     * - `library_loans_campus_guard` (BEFORE INSERT): a new loan's Campus must
     *   be its Copy's, read FOR SHARE (a concurrent Copy move waits, or has
     *   committed and is seen); (BEFORE UPDATE): `campus_id` and
     *   `library_copy_id` never change after insert.
     * - `library_copies_campus_move_guard` (BEFORE UPDATE OF campus_id): a
     *   Copy on an active loan never changes Campus -- the backstop for the
     *   application rule (LibraryCopyCampusMove).
     *
     * Not a retention-tracked column: it is immutable, so it can never
     * re-record a row (RetentionAnchors tracks columns that can change).
     *
     * Backfill: each existing loan takes its Copy's current Campus ONLY when
     * that is provably its checkout Campus -- the Copy has not been updated
     * since the checkout (`updated_at`) and no `library.copy.updated` audit
     * event exists at or after it. Any other loan is ambiguous and the
     * migration aborts as a whole, rewriting nothing (an owner remediation
     * decision, never a guess). ORG.7D-5 preflight: every local database
     * unambiguous (0 Copy updates ever).
     */
    public function up(): void
    {
        $ambiguous = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n
              FROM library_loans l
              JOIN library_copies c ON c.id = l.library_copy_id AND c.school_id = l.school_id
             WHERE c.updated_at > l.checked_out_at
                OR EXISTS (SELECT 1 FROM school_audit_events e
                            WHERE e.school_id = l.school_id
                              AND e.event_type = 'library.copy.updated'
                              AND e.subject_id = c.id
                              AND e.occurred_at >= l.checked_out_at)
            SQL)->n;
        if ((int) $ambiguous > 0) {
            throw new RuntimeException("ORG.7D-5: {$ambiguous} Library loan(s) have an ambiguous checkout Campus (their Copy changed after checkout). Nothing was changed; this needs an owner remediation decision (ADR 0076 §41).");
        }

        DB::statement('ALTER TABLE library_loans ADD COLUMN campus_id uuid NULL');
        DB::statement('ALTER TABLE library_loans ADD CONSTRAINT library_loans_campus_fk
            FOREIGN KEY (campus_id, school_id) REFERENCES campuses (id, school_id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX library_loans_school_campus_index ON library_loans (school_id, campus_id)');

        DB::statement('UPDATE library_loans l SET campus_id = c.campus_id
            FROM library_copies c WHERE c.id = l.library_copy_id AND c.school_id = l.school_id');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION library_loans_campus_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_campus uuid;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT c.campus_id INTO v_campus FROM library_copies c
                     WHERE c.id = NEW.library_copy_id AND c.school_id = NEW.school_id
                       FOR SHARE;
                    IF NOT FOUND OR NEW.campus_id IS DISTINCT FROM v_campus THEN
                        RAISE EXCEPTION 'library_loans: a loan''s Campus must be its Copy''s Campus at checkout'
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.campus_id IS DISTINCT FROM OLD.campus_id OR NEW.library_copy_id IS DISTINCT FROM OLD.library_copy_id THEN
                    RAISE EXCEPTION 'library_loans: a loan''s Campus and Copy never change after checkout'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER library_loans_campus_guard BEFORE INSERT OR UPDATE ON library_loans
                FOR EACH ROW EXECUTE FUNCTION library_loans_campus_guard();

            CREATE FUNCTION library_copies_campus_move_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.campus_id IS DISTINCT FROM OLD.campus_id AND EXISTS (
                    SELECT 1 FROM library_loans l
                     WHERE l.library_copy_id = OLD.id AND l.school_id = OLD.school_id AND l.status = 'active'
                ) THEN
                    RAISE EXCEPTION 'library_copies: a Copy on an active loan never changes Campus'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER library_copies_campus_move_guard BEFORE UPDATE OF campus_id ON library_copies
                FOR EACH ROW EXECUTE FUNCTION library_copies_campus_move_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS library_copies_campus_move_guard ON library_copies;
            DROP FUNCTION IF EXISTS library_copies_campus_move_guard();
            DROP TRIGGER IF EXISTS library_loans_campus_guard ON library_loans;
            DROP FUNCTION IF EXISTS library_loans_campus_guard();
            SQL);
        DB::statement('DROP INDEX IF EXISTS library_loans_school_campus_index');
        DB::statement('ALTER TABLE library_loans DROP CONSTRAINT IF EXISTS library_loans_campus_fk');
        DB::statement('ALTER TABLE library_loans DROP COLUMN IF EXISTS campus_id');
    }
};
