<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.9C-2 (ADR 0076 §70): Inventory issue recipient and stock write-off,
     * on the ONE append-only ledger (no second stock engine).
     *
     * - `write_off` is its own movement type, shaped like an issue (from a
     *   Location, to none), so history tells a write-off from a consumption
     *   structurally -- never from a note. It carries a closed
     *   `write_off_reason` (damaged | expired | lost), required exactly on
     *   write-offs and nowhere else.
     * - `recipient_department_id`: the optional organisational recipient of
     *   an ISSUE, an HR Department (tenant-lifetime School configuration,
     *   no personal data). A composite same-School key, RESTRICT (history
     *   fails closed, rule 97). Person recipients (Employee, Student) are
     *   NOT stored: movements are tenant-lifetime data without personal
     *   data (E21.2G O5), so a person reference is an owner/E21 decision.
     * - `idempotency_key`: the server-issued form key a web issue or
     *   write-off is claimed on (rule 30: the unique index decides).
     *
     * Existing rows (receipt/issue/transfer, all three columns NULL)
     * satisfy every new CHECK; the runtime role keeps INSERT only (H1R).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements ADD COLUMN write_off_reason varchar(16) NULL');
        DB::statement('ALTER TABLE stock_movements ADD COLUMN recipient_department_id uuid NULL');
        DB::statement('ALTER TABLE stock_movements ADD COLUMN idempotency_key uuid NULL');

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_recipient_department_fk '.
            'FOREIGN KEY (recipient_department_id, school_id) REFERENCES hr_departments (id, school_id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX stock_movements_idempotency_unique ON stock_movements (school_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type IN ('receipt', 'issue', 'transfer', 'write_off'))");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK ('.
            "(movement_type = 'receipt' AND from_location_id IS NULL AND to_location_id IS NOT NULL) OR ".
            "(movement_type IN ('issue', 'write_off') AND from_location_id IS NOT NULL AND to_location_id IS NULL) OR ".
            "(movement_type = 'transfer' AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL)".
            ')'
        );
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_write_off_reason_check CHECK ('.
            "(movement_type = 'write_off') = (write_off_reason IS NOT NULL) AND ".
            "(write_off_reason IS NULL OR write_off_reason IN ('damaged', 'expired', 'lost'))".
            ')'
        );
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_recipient_issue_only_check CHECK ('.
            "recipient_department_id IS NULL OR movement_type = 'issue'".
            ')'
        );
    }

    /**
     * Reversible only while nothing uses the new shapes: a recorded write-off,
     * a recipient or a claimed key is append-only evidence, never deleted to
     * roll a migration back.
     */
    public function down(): void
    {
        $used = DB::table('stock_movements')
            ->where(fn ($q) => $q->where('movement_type', 'write_off')->orWhereNotNull('recipient_department_id')->orWhereNotNull('idempotency_key'))
            ->exists();
        if ($used) {
            throw new RuntimeException('stock_movements holds write-offs, issue recipients or claimed keys; that evidence is append-only and is never deleted to roll back.');
        }

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_recipient_issue_only_check');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_write_off_reason_check');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK ('.
            "(movement_type = 'receipt' AND from_location_id IS NULL AND to_location_id IS NOT NULL) OR ".
            "(movement_type = 'issue' AND from_location_id IS NOT NULL AND to_location_id IS NULL) OR ".
            "(movement_type = 'transfer' AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL)".
            ')'
        );
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type IN ('receipt', 'issue', 'transfer'))");
        DB::statement('DROP INDEX IF EXISTS stock_movements_idempotency_unique');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_recipient_department_fk');
        DB::statement('ALTER TABLE stock_movements DROP COLUMN idempotency_key');
        DB::statement('ALTER TABLE stock_movements DROP COLUMN recipient_department_id');
        DB::statement('ALTER TABLE stock_movements DROP COLUMN write_off_reason');
    }
};
