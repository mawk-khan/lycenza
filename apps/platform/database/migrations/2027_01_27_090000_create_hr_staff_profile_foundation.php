<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.2A: the Staff Profile & Employment Records foundation.
 *
 * 1. `employee_reference_codes` (D1-03): an Employee's external references
 *    -- a Staff Reference Code, an ERP code, a legacy code -- each of a
 *    closed kind, School-unique per kind and NEVER reused (the unique key
 *    covers retired rows), at most one live code per kind and Employee.
 *    A reference is never the Employee's identity (that is `employees.id`;
 *    the Staff Code is `employee_number`). A value is immutable; a change
 *    is retire + a new code.
 * 2. Archive, never delete (S-16, OD-HR-13): addresses, emergency contacts,
 *    notes, qualifications, previous experience and certifications gain
 *    `archived_at`/`archived_by_user_id`/`archive_reason`. An archived row is
 *    frozen evidence; the runtime role loses DELETE (only retention purges).
 *    "One address per kind" and "one primary emergency contact" now count
 *    live rows only, so an archived address keeps its history beside the
 *    current one (current value + correction history, never fabricated
 *    effective dates).
 * 3. HR document records reference a governed platform Document (S-12,
 *    D1-26): `employee_documents.document_id` (composite same-School key to
 *    `documents`); new records never carry a raw storage path (insert
 *    trigger). Rows registered before HR.2A keep their legacy path metadata,
 *    untouched. `category` becomes a closed catalogue
 *    (EmployeeDocumentCategory); an identity document is always Highly
 *    Sensitive. Legacy `id_proof` maps to `identity_document`; any other
 *    unknown category, or an `id_proof` row that is not Highly Sensitive,
 *    refuses the migration (nothing changed) rather than guessing.
 */
return new class extends Migration
{
    private const ARCHIVABLE = [
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications',
    ];

    private const ARCHIVE_REASONS = "'recorded_in_error', 'no_longer_current', 'superseded'";

    private const REFERENCE_KINDS = "'staff_reference', 'erp', 'legacy'";

    private const RETIRE_REASONS = "'recorded_in_error', 'replaced', 'no_longer_used'";

    private const DOCUMENT_CATEGORIES = "'identity_document', 'employment_contract', 'appointment_letter', 'qualification_evidence', 'experience_evidence', 'certification_evidence'";

    public function up(): void
    {
        $this->refuseUnmappableDocumentCategories();

        // --- 1. Reference codes -------------------------------------------
        Schema::create('employee_reference_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('kind', 20);
            $table->string('value', 64);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignUuid('retired_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('retire_reason', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            // Never reused: retired rows keep their value.
            $table->unique(['school_id', 'kind', 'value'], 'employee_reference_codes_value_unique');
            $table->index(['school_id', 'value'], 'employee_reference_codes_lookup_idx');
            $table->index(['school_id', 'employee_id'], 'employee_reference_codes_employee_idx');
            $table->index('recorded_by_user_id');
            $table->index('retired_by_user_id');
            $table->foreign(['employee_id', 'school_id'])->references(['id', 'school_id'])->on('employees')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE employee_reference_codes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('CREATE UNIQUE INDEX employee_reference_codes_one_live_per_kind ON employee_reference_codes (school_id, employee_id, kind) WHERE retired_at IS NULL');
        DB::statement('ALTER TABLE employee_reference_codes ADD CONSTRAINT employee_reference_codes_kind_check CHECK (kind IN ('.self::REFERENCE_KINDS.'))');
        DB::statement("ALTER TABLE employee_reference_codes ADD CONSTRAINT employee_reference_codes_value_check CHECK (value ~ '^[A-Z0-9][A-Z0-9/._-]{0,63}$')");
        DB::statement('ALTER TABLE employee_reference_codes ADD CONSTRAINT employee_reference_codes_retire_check CHECK ((retired_at IS NULL) = (retired_by_user_id IS NULL) AND (retired_at IS NULL) = (retire_reason IS NULL) AND (retire_reason IS NULL OR retire_reason IN ('.self::RETIRE_REASONS.')))');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_employee_reference_codes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.retired_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a reference code is recorded live (hr_employee_reference_codes_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.retired_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a retired reference code is final (hr_employee_reference_codes_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF (to_jsonb(NEW) - ARRAY['retired_at', 'retired_by_user_id', 'retire_reason', 'updated_at'])
                   IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['retired_at', 'retired_by_user_id', 'retire_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'a reference code is immutable; retire it and record a new one (hr_employee_reference_codes_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employee_reference_codes_guard BEFORE INSERT OR UPDATE ON employee_reference_codes
                FOR EACH ROW EXECUTE FUNCTION hr_employee_reference_codes_guard();

            -- --- 2. Archive, never delete (shared by the six ancillary tables) ---
            CREATE FUNCTION hr_profile_record_archive_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.archived_at IS NOT NULL THEN
                        RAISE EXCEPTION '% rows are recorded live (hr_profile_record_archive_guard)', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'an archived % row is final evidence (hr_profile_record_archive_guard)', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.archived_at IS NOT NULL AND (to_jsonb(NEW) - ARRAY['archived_at', 'archived_by_user_id', 'archive_reason', 'updated_at', 'is_primary'])
                   IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['archived_at', 'archived_by_user_id', 'archive_reason', 'updated_at', 'is_primary']) THEN
                    RAISE EXCEPTION 'archiving a % row changes nothing else (hr_profile_record_archive_guard)', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.employee_id IS DISTINCT FROM OLD.employee_id THEN
                    RAISE EXCEPTION 'a % row never changes its Employee (hr_profile_record_archive_guard)', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            SQL);

        foreach (self::ARCHIVABLE as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestampTz('archived_at')->nullable();
                $t->foreignUuid('archived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $t->string('archive_reason', 30)->nullable();
                $t->index('archived_by_user_id');
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_archive_check CHECK ((archived_at IS NULL) = (archived_by_user_id IS NULL) AND (archived_at IS NULL) = (archive_reason IS NULL) AND (archive_reason IS NULL OR archive_reason IN (".self::ARCHIVE_REASONS.')))');
            DB::statement("CREATE TRIGGER trg_{$table}_archive_guard BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION hr_profile_record_archive_guard()");
            DB::statement("REVOKE DELETE ON {$table} FROM school_os_app");
        }
        DB::statement('DROP INDEX employee_addresses_one_per_type_per_employee');
        DB::statement("CREATE UNIQUE INDEX employee_addresses_one_per_type_per_employee ON employee_addresses (school_id, employee_id, address_type) WHERE address_type <> 'other' AND archived_at IS NULL");
        DB::statement('DROP INDEX employee_emergency_contacts_one_primary_per_employee');
        DB::statement('CREATE UNIQUE INDEX employee_emergency_contacts_one_primary_per_employee ON employee_emergency_contacts (school_id, employee_id) WHERE is_primary = true AND archived_at IS NULL');
        DB::statement('ALTER TABLE employee_emergency_contacts ADD CONSTRAINT employee_emergency_contacts_archived_not_primary_check CHECK (archived_at IS NULL OR is_primary = false)');

        // --- 3. HR document records reference a governed Document --------------
        DB::statement("UPDATE employee_documents SET category = 'identity_document' WHERE category = 'id_proof'");
        Schema::table('employee_documents', function (Blueprint $t) {
            $t->uuid('document_id')->nullable();
            $t->unique('document_id');
            $t->foreign(['document_id', 'school_id'])->references(['id', 'school_id'])->on('documents');
        });
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN storage_disk DROP NOT NULL');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN storage_path DROP NOT NULL');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_storage_shape_check CHECK ((document_id IS NULL) = (storage_path IS NOT NULL) AND (storage_path IS NULL) = (storage_disk IS NULL))');
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_category_check CHECK (category IN ('.self::DOCUMENT_CATEGORIES.'))');
        DB::statement("ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_identity_tier_check CHECK (category <> 'identity_document' OR classification_tier = 'highly_sensitive')");
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_employee_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.document_id IS NULL THEN
                        RAISE EXCEPTION 'a new HR document record references a governed Document, never a raw path (hr_employee_documents_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.document_id IS DISTINCT FROM OLD.document_id OR NEW.storage_disk IS DISTINCT FROM OLD.storage_disk
                   OR NEW.storage_path IS DISTINCT FROM OLD.storage_path OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
                   OR NEW.school_id IS DISTINCT FROM OLD.school_id THEN
                    RAISE EXCEPTION 'an HR document record never changes its file or its Employee (hr_employee_documents_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'archived' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'an archived HR document record is final (hr_employee_documents_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employee_documents_guard BEFORE INSERT OR UPDATE ON employee_documents
                FOR EACH ROW EXECUTE FUNCTION hr_employee_documents_guard();
            SQL);
        DB::statement('REVOKE DELETE ON employee_documents FROM school_os_app');

        TenantRls::enable('employee_reference_codes');
        DB::statement('REVOKE DELETE ON employee_reference_codes FROM school_os_app');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employee_documents_guard ON employee_documents;
            DROP FUNCTION IF EXISTS hr_employee_documents_guard();
            SQL);
        if (DB::table('employee_documents')->whereNotNull('document_id')->exists()) {
            // A governed record has no raw path to fall back to: the pre-HR.2A
            // schema cannot hold it. Refuse rather than drop evidence.
            throw new RuntimeException('HR.2A rollback refused: HR document records reference governed Documents (the pre-HR.2A schema needs a storage path). Archive their Documents first or roll forward.');
        }
        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT employee_documents_identity_tier_check');
        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT employee_documents_category_check');
        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT employee_documents_storage_shape_check');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN storage_path SET NOT NULL');
        DB::statement('ALTER TABLE employee_documents ALTER COLUMN storage_disk SET NOT NULL');
        Schema::table('employee_documents', function (Blueprint $t) {
            $t->dropForeign(['document_id', 'school_id']);
            $t->dropUnique(['document_id']);
            $t->dropColumn('document_id');
        });
        DB::statement("UPDATE employee_documents SET category = 'id_proof' WHERE category = 'identity_document'");
        DB::statement('GRANT DELETE ON employee_documents TO school_os_app');

        DB::statement('ALTER TABLE employee_emergency_contacts DROP CONSTRAINT employee_emergency_contacts_archived_not_primary_check');
        DB::statement('DROP INDEX employee_emergency_contacts_one_primary_per_employee');
        DB::statement('DROP INDEX employee_addresses_one_per_type_per_employee');
        // Archived rows are history the pre-HR.2A schema cannot mark; they go
        // (documented lossy rollback), so the restored unique indexes hold.
        foreach (self::ARCHIVABLE as $table) {
            DB::statement("DROP TRIGGER trg_{$table}_archive_guard ON {$table}");
            DB::table($table)->whereNotNull('archived_at')->delete();
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_archive_check");
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['archived_by_user_id']);
                $t->dropIndex(['archived_by_user_id']);
                $t->dropColumn(['archived_at', 'archived_by_user_id', 'archive_reason']);
            });
            DB::statement("GRANT DELETE ON {$table} TO school_os_app");
        }
        DB::statement("CREATE UNIQUE INDEX employee_addresses_one_per_type_per_employee ON employee_addresses (school_id, employee_id, address_type) WHERE address_type <> 'other'");
        DB::statement('CREATE UNIQUE INDEX employee_emergency_contacts_one_primary_per_employee ON employee_emergency_contacts (school_id, employee_id) WHERE is_primary = true');
        DB::unprepared('DROP FUNCTION IF EXISTS hr_profile_record_archive_guard()');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employee_reference_codes_guard ON employee_reference_codes;
            DROP FUNCTION IF EXISTS hr_employee_reference_codes_guard();
            SQL);
        TenantRls::disable('employee_reference_codes');
        Schema::dropIfExists('employee_reference_codes');
    }

    /** Legacy free-text categories are mapped only where the meaning is certain; anything else refuses. */
    private function refuseUnmappableDocumentCategories(): void
    {
        $known = ['id_proof', 'identity_document', 'employment_contract', 'appointment_letter', 'qualification_evidence', 'experience_evidence', 'certification_evidence'];
        $unknown = DB::table('employee_documents')->whereNotIn('category', $known)
            ->selectRaw('category, count(*) as n')->groupBy('category')->orderBy('category')->get();
        $weakIdentity = DB::table('employee_documents')->whereIn('category', ['id_proof', 'identity_document'])
            ->where('classification_tier', '<>', 'highly_sensitive')->count();

        $problems = $unknown->map(fn ($r) => "{$r->n} record(s) with the unmapped category \"{$r->category}\"")->all();
        if ($weakIdentity > 0) {
            $problems[] = "{$weakIdentity} identity document record(s) not classified Highly Sensitive (reclassify them first)";
        }
        if ($problems !== []) {
            throw new RuntimeException('HR.2A staff profile foundation refused (nothing changed): '.implode('; ', $problems).'. Map each to a closed EmployeeDocumentCategory before migrating.');
        }
    }
};
