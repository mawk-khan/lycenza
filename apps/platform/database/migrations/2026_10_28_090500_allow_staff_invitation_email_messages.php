<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.12B (ADR 0059 section 9.2; ADR 0055 consumer note): one new
 * CRITICAL, School-scoped email purpose -- `staff_account_invitation`,
 * produced only from a `staff_account_invitation` source -- and its backlog
 * mirror source. Every other purpose/kind/source rule is unchanged; the
 * existing `email_messages_identity_level_check` already requires a School
 * for it (it is not an identity-level purpose).
 *
 * down(): the new purpose's rows (and their backlog mirror) are deleted
 * first -- ephemeral technical records whose invitations are pruned within
 * days anyway -- then the previous checks are restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->checks(withStaff: true);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE email_messages NO FORCE ROW LEVEL SECURITY');
            DB::statement("DELETE FROM operational_work_backlog WHERE source = 'email:staff_account_invitation'");
            DB::statement("DELETE FROM email_messages WHERE purpose = 'staff_account_invitation'");
            DB::statement('ALTER TABLE email_messages FORCE ROW LEVEL SECURITY');
        });

        $this->checks(withStaff: false);
    }

    private function checks(bool $withStaff): void
    {
        $staffPurpose = $withStaff ? ", 'staff_account_invitation'" : '';
        $staffSource = $withStaff ? " OR (purpose = 'staff_account_invitation' AND source_type = 'staff_account_invitation')" : '';
        $staffBacklog = $withStaff ? ", 'email:staff_account_invitation'" : '';

        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_purpose_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_kind_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_source_check');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_purpose_check CHECK (purpose IN ('account_invitation', 'school_communication', 'account_recovery', 'security_notice'{$staffPurpose}))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_kind_check CHECK ((purpose = 'school_communication' AND kind = 'standard') OR (purpose IN ('account_invitation', 'account_recovery', 'security_notice'{$staffPurpose}) AND kind = 'critical'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_source_check CHECK ((purpose = 'account_invitation' AND source_type = 'guardian_account_invitation') OR (purpose = 'school_communication' AND source_type = 'communication_delivery') OR (purpose = 'account_recovery' AND source_type = 'account_recovery_request') OR (purpose = 'security_notice' AND source_type = 'user_security_notice'){$staffSource})");

        DB::statement('ALTER TABLE operational_work_backlog DROP CONSTRAINT operational_work_backlog_source_check');
        DB::statement("ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('webhook', 'communication', 'automation', 'email:account_invitation', 'email:school_communication', 'email:account_recovery', 'email:security_notice'{$staffBacklog}))");
    }
};
