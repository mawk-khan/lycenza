<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.10A (ADR 0056 section 9.3; ADR 0055 consumer note): IDENTITY-
 * level email -- account recovery and the post-reset security notice --
 * belongs to no School.
 *
 * - `email_messages.school_id` (and the attempts' and provider references')
 *   becomes nullable, and NULL IF AND ONLY IF the purpose is
 *   `account_recovery` or `security_notice`
 *   (`email_messages_identity_level_check`): School mail can never become
 *   unscoped.
 * - The two reserved purposes become real (purpose/kind/source checks).
 * - RLS: the ordinary School policy is replaced by
 *   TenantRls::enableWithPlatformScope(): School rows exactly as before; a
 *   School-less row only inside App\Support\Email\PlatformEmailScope. No
 *   School context -- and no context at all -- sees one.
 * - Attempts gain a plain FK to their message: the composite
 *   (message, school) FK is not checked when school_id is NULL.
 * - The backlog mirror accepts the two new sources.
 *
 * down(): identity-level email rows are deleted first (ephemeral technical
 * records; their recovery credentials are pruned within 24 h anyway), then
 * the previous schema is restored.
 */
return new class extends Migration
{
    private const IDENTITY_PURPOSES = "'account_recovery', 'security_notice'";

    public function up(): void
    {
        foreach (['email_messages', 'email_submission_attempts', 'email_provider_references'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN school_id DROP NOT NULL");
        }

        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_purpose_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_kind_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_source_check');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_purpose_check CHECK (purpose IN ('account_invitation', 'school_communication', 'account_recovery', 'security_notice'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_kind_check CHECK ((purpose = 'school_communication' AND kind = 'standard') OR (purpose IN ('account_invitation', 'account_recovery', 'security_notice') AND kind = 'critical'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_source_check CHECK ((purpose = 'account_invitation' AND source_type = 'guardian_account_invitation') OR (purpose = 'school_communication' AND source_type = 'communication_delivery') OR (purpose = 'account_recovery' AND source_type = 'account_recovery_request') OR (purpose = 'security_notice' AND source_type = 'user_security_notice'))");
        DB::statement('ALTER TABLE email_messages ADD CONSTRAINT email_messages_identity_level_check CHECK ((school_id IS NULL) = (purpose IN ('.self::IDENTITY_PURPOSES.')))');

        DB::statement('ALTER TABLE email_submission_attempts ADD CONSTRAINT email_submission_attempts_message_fk FOREIGN KEY (email_message_id) REFERENCES email_messages (id) ON DELETE CASCADE');

        foreach (['email_messages', 'email_submission_attempts'] as $table) {
            TenantRls::disable($table);
            TenantRls::enableWithPlatformScope($table);
        }

        DB::unprepared($this->guardFunction('NEW.school_id IS DISTINCT FROM OLD.school_id'));

        DB::statement('ALTER TABLE operational_work_backlog DROP CONSTRAINT operational_work_backlog_source_check');
        DB::statement("ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('webhook', 'communication', 'automation', 'email:account_invitation', 'email:school_communication', 'email:account_recovery', 'email:security_notice'))");
    }

    public function down(): void
    {
        // Identity-level rows are visible only in the platform-email scope,
        // also to this (owner, FORCE RLS) connection.
        DB::transaction(function (): void {
            DB::select("SELECT set_config('".TenantRls::PLATFORM_EMAIL_SCOPE_VAR."', 'on', true)");
            DB::statement("DELETE FROM operational_work_backlog WHERE source IN ('email:account_recovery', 'email:security_notice')");
            DB::statement('DELETE FROM email_provider_references WHERE school_id IS NULL');
            DB::statement('DELETE FROM email_messages WHERE school_id IS NULL');
        });

        DB::statement('ALTER TABLE operational_work_backlog DROP CONSTRAINT operational_work_backlog_source_check');
        DB::statement("ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('webhook', 'communication', 'automation', 'email:account_invitation', 'email:school_communication'))");

        DB::unprepared($this->guardFunction('NEW.school_id <> OLD.school_id'));

        foreach (['email_messages', 'email_submission_attempts'] as $table) {
            TenantRls::disable($table);
            TenantRls::enable($table);
        }

        DB::statement('ALTER TABLE email_submission_attempts DROP CONSTRAINT IF EXISTS email_submission_attempts_message_fk');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT IF EXISTS email_messages_identity_level_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_source_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_kind_check');
        DB::statement('ALTER TABLE email_messages DROP CONSTRAINT email_messages_purpose_check');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_purpose_check CHECK (purpose IN ('account_invitation', 'school_communication'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_kind_check CHECK ((purpose = 'account_invitation' AND kind = 'critical') OR (purpose = 'school_communication' AND kind = 'standard'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_source_check CHECK ((purpose = 'account_invitation' AND source_type = 'guardian_account_invitation') OR (purpose = 'school_communication' AND source_type = 'communication_delivery'))");

        foreach (['email_messages', 'email_submission_attempts', 'email_provider_references'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN school_id SET NOT NULL");
        }
    }

    /** The ADR 0055 update guard, with the School comparison given. */
    private function guardFunction(string $schoolChanged): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION email_messages_guard_update() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.id <> OLD.id OR {$schoolChanged} OR NEW.purpose <> OLD.purpose OR NEW.kind <> OLD.kind
                    OR NEW.source_type <> OLD.source_type OR NEW.source_id <> OLD.source_id
                    OR NEW.recipient_encrypted <> OLD.recipient_encrypted
                    OR NEW.from_mailbox <> OLD.from_mailbox OR NEW.from_display_name <> OLD.from_display_name
                    OR NEW.subject <> OLD.subject OR NEW.expires_at <> OLD.expires_at OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION 'email_messages: identity columns are immutable (email_messages_immutable)';
                END IF;

                IF NOT email_messages_transition_allowed(OLD.status, NEW.status) THEN
                    RAISE EXCEPTION 'email_messages: state transition % -> % is not permitted (email_messages_transition)', OLD.status, NEW.status;
                END IF;

                IF OLD.rfc_message_id IS NOT NULL AND NEW.rfc_message_id IS DISTINCT FROM OLD.rfc_message_id THEN
                    RAISE EXCEPTION 'email_messages: the RFC Message-ID never changes (email_messages_rfc_message_id)';
                END IF;

                IF OLD.provider_message_id IS NOT NULL AND NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id THEN
                    RAISE EXCEPTION 'email_messages: a provider message id never changes (email_messages_provider_id)';
                END IF;

                IF NEW.attempts < OLD.attempts OR NEW.manual_retries < OLD.manual_retries THEN
                    RAISE EXCEPTION 'email_messages: attempt counters never decrease (email_messages_attempts)';
                END IF;

                IF OLD.sealed_content IS NULL AND NEW.sealed_content IS NOT NULL THEN
                    RAISE EXCEPTION 'email_messages: purged content is never restored (email_messages_content)';
                END IF;
                IF NEW.status NOT IN ('pending', 'submitting') THEN
                    NEW.sealed_content := NULL;
                END IF;
                IF NEW.sealed_content IS NULL AND NEW.content_purged_at IS NULL THEN
                    NEW.content_purged_at := now();
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
