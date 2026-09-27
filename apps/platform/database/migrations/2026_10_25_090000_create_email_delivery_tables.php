<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.9A (ADR 0055 sections 9, 11, 12): the durable email layer.
 *
 * - `email_messages` (tenant, forced RLS): one logical email to ONE
 *   recipient. The state graph, the immutability of its identity and the
 *   purge of its sealed content are enforced here, by trigger, so a direct
 *   write cannot bypass them.
 * - `email_submission_attempts` (tenant, forced RLS, append-only): one row
 *   per real provider call.
 * - `email_events` (platform, no RLS, no School, no person): normalized
 *   provider events, unique on (provider, event_key).
 * - `email_suppressions` (platform, no RLS): HMAC fingerprints only, never
 *   an address; history is kept (release, never delete).
 * - `email_provider_references` (platform routing index, no RLS; in
 *   DatabaseRoleVerifier::NON_RLS_SCHOOL_TABLES): provider message id ->
 *   message and School, written by the submitting worker, so a provider
 *   event finds its School from stored data -- never from its payload and
 *   never through an RLS bypass. Ids only; an implementation amendment to
 *   ADR 0055 section 9.1's four tables.
 *
 * Unfinished messages (`pending`, `submitting`) are mirrored into
 * `operational_work_backlog` as source `email:<purpose>`, so operations
 * status and the metrics scrape read email backlog without a School
 * context (the ADR 0051 pattern). `state_since` is the message's creation
 * time: "oldest pending" means how long an email has waited in total.
 */
return new class extends Migration
{
    /** Only the IMPLEMENTED purposes; reserved ones need a migration of their own. */
    private const PURPOSES = ['account_invitation', 'school_communication'];

    private const STATES = ['pending', 'submitting', 'submitted', 'deferred', 'delivered', 'bounced', 'complained', 'failed', 'suppressed', 'cancelled'];

    private const BACKLOG_SOURCES = ['webhook', 'communication', 'automation', 'email:account_invitation', 'email:school_communication'];

    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('kind', 16);
            $table->string('source_type', 48);
            $table->uuid('source_id');
            $table->text('recipient_encrypted');
            $table->string('from_mailbox', 32);
            $table->string('from_display_name', 64);
            $table->string('subject', 200);
            $table->text('sealed_content')->nullable();
            $table->timestamp('content_purged_at')->nullable();
            $table->string('rfc_message_id', 255)->nullable();
            $table->string('status', 16);
            $table->string('status_code', 48)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_base')->default(0);
            $table->unsignedSmallInteger('manual_retries')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['source_type', 'source_id']);
            $table->unique('rfc_message_id');
            $table->index(['school_id', 'status', 'next_attempt_at']);
            $table->index(['school_id', 'status', 'processing_lease_expires_at']);
        });

        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement('ALTER TABLE email_messages ADD CONSTRAINT email_messages_purpose_check CHECK (purpose IN ('.$in(self::PURPOSES).'))');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_kind_check CHECK ((purpose = 'account_invitation' AND kind = 'critical') OR (purpose = 'school_communication' AND kind = 'standard'))");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_source_check CHECK ((purpose = 'account_invitation' AND source_type = 'guardian_account_invitation') OR (purpose = 'school_communication' AND source_type = 'communication_delivery'))");
        DB::statement('ALTER TABLE email_messages ADD CONSTRAINT email_messages_status_check CHECK (status IN ('.$in(self::STATES).'))');
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_from_mailbox_check CHECK (from_mailbox IN ('notifications'))");
        // Header safety, database-enforced (ADR 0055 section 13): no control
        // character (CR/LF included) can reach a header value.
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_header_safe_check CHECK (subject !~ '[[:cntrl:]]' AND from_display_name !~ '[[:cntrl:]]' AND char_length(subject) BETWEEN 1 AND 200 AND char_length(from_display_name) BETWEEN 1 AND 64)");
        DB::statement('ALTER TABLE email_messages ADD CONSTRAINT email_messages_attempts_check CHECK (attempts <= 12 AND retry_base <= attempts AND manual_retries <= 1)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION email_messages_guard_insert() RETURNS trigger AS $$
            BEGIN
                IF NEW.status <> 'pending' OR NEW.attempts <> 0 OR NEW.sealed_content IS NULL
                    OR NEW.provider_message_id IS NOT NULL OR NEW.content_purged_at IS NOT NULL THEN
                    RAISE EXCEPTION 'email_messages: a message starts pending, unattempted and sealed (email_messages_insert_state)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            -- ADR 0055 section 9.2 (as implemented in 0O.9A): the explicit
            -- transition graph. Same-state updates (lease renewal, deferral)
            -- are allowed; nothing ever moves backward.
            CREATE OR REPLACE FUNCTION email_messages_transition_allowed(old_state text, new_state text) RETURNS boolean AS $$
                SELECT old_state = new_state OR (old_state, new_state) IN (
                    ('pending', 'submitting'), ('pending', 'suppressed'), ('pending', 'cancelled'), ('pending', 'failed'),
                    ('submitting', 'submitted'), ('submitting', 'pending'), ('submitting', 'failed'),
                    ('submitted', 'deferred'), ('submitted', 'delivered'), ('submitted', 'bounced'), ('submitted', 'complained'), ('submitted', 'failed'),
                    ('deferred', 'delivered'), ('deferred', 'bounced'), ('deferred', 'complained'), ('deferred', 'failed'),
                    ('delivered', 'bounced'), ('delivered', 'complained')
                );
            $$ LANGUAGE sql IMMUTABLE;

            CREATE OR REPLACE FUNCTION email_messages_guard_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.school_id <> OLD.school_id OR NEW.purpose <> OLD.purpose OR NEW.kind <> OLD.kind
                    OR NEW.source_type <> OLD.source_type OR NEW.source_id <> OLD.source_id
                    OR NEW.recipient_encrypted <> OLD.recipient_encrypted
                    OR NEW.from_mailbox <> OLD.from_mailbox OR NEW.from_display_name <> OLD.from_display_name
                    OR NEW.subject <> OLD.subject OR NEW.expires_at <> OLD.expires_at OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION 'email_messages: identity columns are immutable (email_messages_immutable)';
                END IF;

                IF NOT email_messages_transition_allowed(OLD.status, NEW.status) THEN
                    RAISE EXCEPTION 'email_messages: state transition % -> % is not permitted (email_messages_transition)', OLD.status, NEW.status;
                END IF;

                -- The RFC Message-ID is fixed once (at creation, or at the first
                -- submission when the sending domain was not yet configured).
                IF OLD.rfc_message_id IS NOT NULL AND NEW.rfc_message_id IS DISTINCT FROM OLD.rfc_message_id THEN
                    RAISE EXCEPTION 'email_messages: the RFC Message-ID never changes (email_messages_rfc_message_id)';
                END IF;

                IF OLD.provider_message_id IS NOT NULL AND NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id THEN
                    RAISE EXCEPTION 'email_messages: a provider message id never changes (email_messages_provider_id)';
                END IF;

                IF NEW.attempts < OLD.attempts OR NEW.manual_retries < OLD.manual_retries THEN
                    RAISE EXCEPTION 'email_messages: attempt counters never decrease (email_messages_attempts)';
                END IF;

                -- Content is never re-sealed, and it is purged the moment the
                -- message leaves the pre-submission states (ADR 0055 section 9.4).
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
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_email_messages_guard_insert BEFORE INSERT ON email_messages
                FOR EACH ROW EXECUTE FUNCTION email_messages_guard_insert();
            CREATE TRIGGER trg_email_messages_guard_update BEFORE UPDATE ON email_messages
                FOR EACH ROW EXECUTE FUNCTION email_messages_guard_update();
            SQL);

        TenantRls::enable('email_messages');

        Schema::create('email_submission_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('email_message_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->string('outcome', 24);
            $table->string('failure_code', 48)->nullable();
            $table->string('provider', 32);
            $table->string('provider_message_id', 255)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign(['email_message_id', 'school_id'])->references(['id', 'school_id'])->on('email_messages')->cascadeOnDelete();
            $table->unique(['email_message_id', 'attempt_number']);
        });

        DB::statement("ALTER TABLE email_submission_attempts ADD CONSTRAINT email_submission_attempts_outcome_check CHECK (outcome IN ('accepted', 'transient_failure', 'permanent_failure', 'auth_failure'))");

        TenantRls::enable('email_submission_attempts');
        TenantRls::makeAppendOnly('email_submission_attempts');

        Schema::create('email_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('event_key', 128);
            $table->string('type', 24);
            $table->string('bounce_class', 32)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->uuid('email_message_id')->nullable();
            $table->string('result', 24)->default('received');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider', 'event_key']);
            $table->index(['result', 'received_at']);
            $table->index(['provider', 'provider_message_id']);
        });

        DB::statement("ALTER TABLE email_events ADD CONSTRAINT email_events_type_check CHECK (type IN ('delivered', 'deferred', 'bounce_transient', 'bounce_permanent', 'complaint', 'rejected', 'ignored'))");
        DB::statement("ALTER TABLE email_events ADD CONSTRAINT email_events_bounce_class_check CHECK (bounce_class IS NULL OR bounce_class IN ('mailbox_unknown', 'mailbox_full', 'domain_invalid', 'policy_rejected', 'content_rejected', 'provider_suppressed', 'other'))");
        DB::statement("ALTER TABLE email_events ADD CONSTRAINT email_events_result_check CHECK (result IN ('received', 'applied', 'stale', 'unknown_message', 'ignored'))");

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_id', 32);
            $table->char('address_fingerprint', 64);
            $table->string('scope', 16);
            $table->string('reason', 24);
            $table->foreignUuid('source_event_id')->nullable()->constrained('email_events')->nullOnDelete();
            $table->uuid('source_email_message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason', 64)->nullable();

            $table->index('address_fingerprint');
        });

        DB::statement("ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_scope_check CHECK (scope IN ('all', 'standard'))");
        DB::statement("ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_reason_check CHECK (reason IN ('hard_bounce', 'complaint', 'provider_suppressed', 'operator'))");
        DB::statement("ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_fingerprint_check CHECK (address_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement('CREATE UNIQUE INDEX email_suppressions_active_unique ON email_suppressions (key_id, address_fingerprint, scope) WHERE released_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION email_suppressions_guard_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.released_at IS NOT NULL THEN
                    RAISE EXCEPTION 'email_suppressions: a released suppression is history (email_suppressions_released)';
                END IF;
                IF NEW.id <> OLD.id OR NEW.key_id <> OLD.key_id OR NEW.address_fingerprint <> OLD.address_fingerprint
                    OR NEW.scope <> OLD.scope OR NEW.reason <> OLD.reason OR NEW.created_at <> OLD.created_at
                    OR NEW.source_event_id IS DISTINCT FROM OLD.source_event_id
                    OR NEW.source_email_message_id IS DISTINCT FROM OLD.source_email_message_id
                    OR NEW.released_at IS NULL OR NEW.release_reason IS NULL THEN
                    RAISE EXCEPTION 'email_suppressions: only a release may change a suppression (email_suppressions_immutable)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_email_suppressions_guard_update BEFORE UPDATE ON email_suppressions
                FOR EACH ROW EXECUTE FUNCTION email_suppressions_guard_update();
            SQL);

        DB::statement('REVOKE DELETE ON email_suppressions FROM school_os_app');

        Schema::create('email_provider_references', function (Blueprint $table) {
            $table->string('provider', 32);
            $table->string('provider_message_id', 255);
            $table->uuid('email_message_id');
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['provider', 'provider_message_id']);
            $table->index('email_message_id');
        });

        // Email backlog without a School context (ADR 0051 pattern).
        DB::statement('ALTER TABLE operational_work_backlog DROP CONSTRAINT operational_work_backlog_source_check');
        DB::statement('ALTER TABLE operational_work_backlog ALTER COLUMN source TYPE varchar(32)');
        DB::statement('ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('.$in(self::BACKLOG_SOURCES).'))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_email_work_backlog() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM operational_work_backlog WHERE source = 'email:' || OLD.purpose AND item_id = OLD.id;
                    RETURN OLD;
                END IF;

                IF NEW.status NOT IN ('pending', 'submitting') THEN
                    DELETE FROM operational_work_backlog WHERE source = 'email:' || NEW.purpose AND item_id = NEW.id;
                    RETURN NEW;
                END IF;

                INSERT INTO operational_work_backlog (source, item_id, state, state_since, next_attempt_at, lease_expires_at)
                VALUES ('email:' || NEW.purpose, NEW.id, NEW.status, NEW.created_at, NEW.next_attempt_at, NEW.processing_lease_expires_at)
                ON CONFLICT (source, item_id) DO UPDATE SET
                    state = EXCLUDED.state,
                    next_attempt_at = EXCLUDED.next_attempt_at,
                    lease_expires_at = EXCLUDED.lease_expires_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_email_messages_operational_backlog AFTER INSERT OR UPDATE OR DELETE ON email_messages
                FOR EACH ROW EXECUTE FUNCTION sync_email_work_backlog();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_email_messages_operational_backlog ON email_messages');
        DB::statement('DROP FUNCTION IF EXISTS sync_email_work_backlog()');
        DB::statement("DELETE FROM operational_work_backlog WHERE source LIKE 'email:%'");
        DB::statement('ALTER TABLE operational_work_backlog DROP CONSTRAINT operational_work_backlog_source_check');
        DB::statement('ALTER TABLE operational_work_backlog ALTER COLUMN source TYPE varchar(16)');
        DB::statement("ALTER TABLE operational_work_backlog ADD CONSTRAINT operational_work_backlog_source_check CHECK (source IN ('webhook', 'communication', 'automation'))");

        Schema::dropIfExists('email_provider_references');

        DB::statement('DROP TRIGGER IF EXISTS trg_email_suppressions_guard_update ON email_suppressions');
        DB::statement('DROP FUNCTION IF EXISTS email_suppressions_guard_update()');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_events');

        TenantRls::disable('email_submission_attempts');
        Schema::dropIfExists('email_submission_attempts');

        DB::statement('DROP TRIGGER IF EXISTS trg_email_messages_guard_update ON email_messages');
        DB::statement('DROP TRIGGER IF EXISTS trg_email_messages_guard_insert ON email_messages');
        DB::statement('DROP FUNCTION IF EXISTS email_messages_guard_update()');
        DB::statement('DROP FUNCTION IF EXISTS email_messages_transition_allowed(text, text)');
        DB::statement('DROP FUNCTION IF EXISTS email_messages_guard_insert()');
        TenantRls::disable('email_messages');
        Schema::dropIfExists('email_messages');
    }
};
