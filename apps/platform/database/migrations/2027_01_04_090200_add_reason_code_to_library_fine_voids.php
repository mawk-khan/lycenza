<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8E (ADR 0076 §63, owner decision 8E-D3): a Library fine void names a
 * closed, code-owned reason (`reason_code`) besides its existing bounded note
 * (`reason`, 1-255 characters, never audited). A void corrects an erroneous
 * fine; a waiver is a Fees concession and is never a void reason.
 *
 * `library_fine_voids_require_reason_code` (BEFORE INSERT) refuses a void
 * without a code. Rows voided before ORG.8E keep a NULL code (development
 * data; never rewritten). The table stays insert-only for the runtime role,
 * so the code is immutable once recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE library_fine_voids ADD COLUMN reason_code varchar(32);
            ALTER TABLE library_fine_voids ADD CONSTRAINT library_fine_voids_reason_code_check CHECK (
                reason_code IS NULL OR reason_code IN ('assessed_in_error', 'loan_record_corrected', 'returned_before_due', 'other')
            );

            CREATE FUNCTION public.library_fine_voids_require_reason_code() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.reason_code IS NULL THEN
                    RAISE EXCEPTION 'library_fine_voids_require_reason_code: a fine void names its reason code'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER library_fine_voids_require_reason_code BEFORE INSERT ON library_fine_voids
                FOR EACH ROW EXECUTE FUNCTION public.library_fine_voids_require_reason_code();

            REVOKE ALL ON FUNCTION public.library_fine_voids_require_reason_code() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('library_fine_voids')->whereNotNull('reason_code')->exists()) {
            throw new RuntimeException('Refusing to roll back: library_fine_voids holds ORG.8E reason codes.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS library_fine_voids_require_reason_code ON library_fine_voids;
            DROP FUNCTION IF EXISTS public.library_fine_voids_require_reason_code();
            ALTER TABLE library_fine_voids DROP CONSTRAINT IF EXISTS library_fine_voids_reason_code_check;
            ALTER TABLE library_fine_voids DROP COLUMN IF EXISTS reason_code;
            SQL);
    }
};
