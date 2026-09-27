<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.8A (ADR 0054 sections 3-4): `school_domains` becomes the custom
 * School domain lifecycle table. It evolves the existing table (no parallel
 * one) and stays platform data without RLS: a Host is resolved before any
 * School context exists (DatabaseRoleVerifier::NON_RLS_SCHOOL_TABLES).
 *
 * - `domain` is renamed `hostname`: canonical lowercase ASCII LDH, never an
 *   `xn--` label (CHECK; HostnameNormalizer is the application side).
 * - `state` is the ONLY lifecycle truth (`verified_at` becomes an event
 *   timestamp). Transitions are enforced by trg_school_domains_guard_update
 *   for every role; terminal rows (`revoked`, `expired`) are immutable.
 * - Uniqueness: one CLAIMING row per hostname (partial unique index over the
 *   five non-terminal states), so revoked/expired history never blocks a
 *   fresh claim. The old table-wide unique(domain) is dropped.
 * - At most 3 claiming rows per School: the INSERT guard serializes a
 *   School's inserts on a transaction advisory lock, then counts.
 * - Primary: at most one per School (partial unique index), only an
 *   `active` row (CHECK), and -- the cross-row half no CHECK can express --
 *   "a School with any active domain has exactly one primary", verified by
 *   a DEFERRABLE INITIALLY DEFERRED constraint trigger at COMMIT (only when
 *   `state` or `is_primary` changed) under the same per-School advisory lock,
 *   so two concurrent transactions can never each commit half of a swap.
 *   Lock order: every application writer takes the School's advisory lock
 *   BEFORE any row lock (SchoolDomainService::lockSchool()), so the
 *   commit-time re-acquisition is re-entrant and never a deadlock.
 * - `type` is retired to `custom` only (no platform-issued subdomains in v1).
 * - `school_id` is RESTRICT, no longer CASCADE: the runtime role can delete
 *   neither Schools (ADR 0047) nor domain rows (DELETE revoked here), so
 *   domain history survives; only an administrative School deletion (tests,
 *   TestCase::deleteSchoolAsAdmin()) must remove the School's domain rows
 *   first. No orphan is possible.
 *
 * Existing rows predate ownership proof: none was ever managed (ADR 0054
 * section 1.1), so any that exist become terminal `revoked` history
 * (revocation_source `legacy`) and can never resolve a School again.
 */
return new class extends Migration
{
    private const STATES = "'pending_verification', 'verified', 'tls_pending', 'active', 'suspended', 'revoked', 'expired'";

    private const CLAIMING = "'pending_verification', 'verified', 'tls_pending', 'active', 'suspended'";

    private const HOSTNAME_RULE = "hostname = lower(hostname) AND length(hostname) <= 253 AND hostname ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{1,63}$' AND hostname !~ '(^|\\.)xn--'";

    public function up(): void
    {
        DB::statement('ALTER TABLE school_domains DROP CONSTRAINT school_domains_domain_unique');
        DB::statement('ALTER TABLE school_domains RENAME COLUMN domain TO hostname');
        DB::statement('ALTER TABLE school_domains ALTER COLUMN hostname TYPE varchar(253)');

        DB::statement(<<<'SQL'
            ALTER TABLE school_domains
                ADD COLUMN state varchar(32) NOT NULL DEFAULT 'pending_verification',
                ADD COLUMN challenge_token text NULL,
                ADD COLUMN challenge_generation integer NOT NULL DEFAULT 1,
                ADD COLUMN challenge_expires_at timestamp(0) NULL,
                ADD COLUMN ownership_checked_at timestamp(0) NULL,
                ADD COLUMN ownership_outcome varchar(16) NULL,
                ADD COLUMN routing_checked_at timestamp(0) NULL,
                ADD COLUMN routing_outcome varchar(16) NULL,
                ADD COLUMN tls_checked_at timestamp(0) NULL,
                ADD COLUMN tls_outcome varchar(32) NULL,
                ADD COLUMN certificate_not_after timestamp(0) NULL,
                ADD COLUMN certificate_fingerprint char(64) NULL,
                ADD COLUMN certificate_issuer varchar(255) NULL,
                ADD COLUMN activated_at timestamp(0) NULL,
                ADD COLUMN suspended_at timestamp(0) NULL,
                ADD COLUMN suspension_reason varchar(16) NULL,
                ADD COLUMN revoked_at timestamp(0) NULL,
                ADD COLUMN revocation_source varchar(16) NULL,
                ADD COLUMN expired_at timestamp(0) NULL,
                ADD COLUMN ownership_mismatch_count smallint NOT NULL DEFAULT 0,
                ADD COLUMN ownership_mismatch_since timestamp(0) NULL,
                ADD COLUMN ownership_absent_count smallint NOT NULL DEFAULT 0,
                ADD COLUMN ownership_absent_since timestamp(0) NULL,
                ADD COLUMN routing_fail_count smallint NOT NULL DEFAULT 0,
                ADD COLUMN routing_fail_since timestamp(0) NULL,
                ADD COLUMN tls_fail_count smallint NOT NULL DEFAULT 0,
                ADD COLUMN tls_fail_since timestamp(0) NULL,
                ADD COLUMN indeterminate_since timestamp(0) NULL,
                ADD COLUMN last_checked_at timestamp(0) NULL,
                ADD COLUMN next_check_at timestamp(0) NULL
            SQL);

        // Legacy rows (never managed, never ownership-proven): terminal history.
        DB::statement(<<<'SQL'
            UPDATE school_domains
               SET hostname = lower(hostname), state = 'revoked', is_primary = false, type = 'custom',
                   revoked_at = (now() AT TIME ZONE 'UTC'), revocation_source = 'legacy'
            SQL);

        DB::statement("ALTER TABLE school_domains ALTER COLUMN type SET DEFAULT 'custom'");
        DB::statement('ALTER TABLE school_domains ALTER COLUMN type TYPE varchar(32)');
        DB::statement("ALTER TABLE school_domains ADD CONSTRAINT school_domains_type_check CHECK (type = 'custom')");
        DB::statement('ALTER TABLE school_domains ADD CONSTRAINT school_domains_state_check CHECK (state IN ('.self::STATES.'))');
        // Validated for every row written from now on; a legacy row whose name
        // was never canonical stays as terminal history (NOT VALID only then).
        DB::statement('ALTER TABLE school_domains ADD CONSTRAINT school_domains_hostname_check CHECK ('.self::HOSTNAME_RULE.') NOT VALID');
        DB::unprepared('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM school_domains WHERE NOT ('.self::HOSTNAME_RULE.')) THEN ALTER TABLE school_domains VALIDATE CONSTRAINT school_domains_hostname_check; END IF; END $$;');

        DB::statement(<<<'SQL'
            ALTER TABLE school_domains
                ADD CONSTRAINT school_domains_primary_active_check CHECK (NOT is_primary OR state = 'active'),
                ADD CONSTRAINT school_domains_challenge_check CHECK (
                    state IN ('revoked', 'expired') OR (challenge_token IS NOT NULL AND challenge_expires_at IS NOT NULL)
                ),
                ADD CONSTRAINT school_domains_generation_check CHECK (challenge_generation >= 1),
                ADD CONSTRAINT school_domains_active_check CHECK (state <> 'active' OR activated_at IS NOT NULL),
                ADD CONSTRAINT school_domains_suspended_check CHECK (
                    state <> 'suspended' OR (suspended_at IS NOT NULL AND suspension_reason IS NOT NULL)
                ),
                ADD CONSTRAINT school_domains_suspension_reason_check CHECK (suspension_reason IS NULL OR suspension_reason IN ('ownership', 'routing', 'tls')),
                ADD CONSTRAINT school_domains_revoked_check CHECK (
                    (state = 'revoked') = (revoked_at IS NOT NULL AND revocation_source IN ('school', 'operator', 'legacy'))
                ),
                ADD CONSTRAINT school_domains_expired_check CHECK ((state = 'expired') = (expired_at IS NOT NULL)),
                ADD CONSTRAINT school_domains_ownership_outcome_check CHECK (ownership_outcome IS NULL OR ownership_outcome IN ('match', 'mismatch', 'absent', 'indeterminate')),
                ADD CONSTRAINT school_domains_routing_outcome_check CHECK (routing_outcome IS NULL OR routing_outcome IN ('pass', 'fail', 'indeterminate')),
                ADD CONSTRAINT school_domains_tls_outcome_check CHECK (tls_outcome IS NULL OR tls_outcome IN ('pass', 'tls_invalid', 'proof_mismatch', 'indeterminate')),
                ADD CONSTRAINT school_domains_fingerprint_check CHECK (certificate_fingerprint IS NULL OR certificate_fingerprint ~ '^[0-9a-f]{64}$')
            SQL);

        DB::statement('CREATE UNIQUE INDEX school_domains_hostname_claim_unique ON school_domains (hostname) WHERE state IN ('.self::CLAIMING.')');
        DB::statement('CREATE UNIQUE INDEX school_domains_one_primary_per_school ON school_domains (school_id) WHERE is_primary');
        DB::statement('CREATE INDEX school_domains_hostname_index ON school_domains (hostname)');
        DB::statement("CREATE INDEX school_domains_next_check_index ON school_domains (next_check_at) WHERE state IN ('pending_verification', 'verified', 'tls_pending', 'active', 'suspended')");

        DB::statement('ALTER TABLE school_domains DROP CONSTRAINT school_domains_school_id_foreign');
        DB::statement('ALTER TABLE school_domains ADD CONSTRAINT school_domains_school_id_foreign FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION school_domains_lock_school(p_school uuid) RETURNS void AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('school_domains:' || p_school::text, 0));
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION school_domains_guard_insert() RETURNS trigger AS $$
            DECLARE
                claiming integer;
            BEGIN
                IF NEW.state IS DISTINCT FROM 'pending_verification' OR NEW.is_primary OR NEW.challenge_generation <> 1 THEN
                    RAISE EXCEPTION 'school_domains: a new domain starts as a pending, non-primary claim (school_domains_insert_state)';
                END IF;

                PERFORM school_domains_lock_school(NEW.school_id);

                SELECT count(*) INTO claiming FROM school_domains
                 WHERE school_id = NEW.school_id
                   AND state IN ('pending_verification', 'verified', 'tls_pending', 'active', 'suspended');

                IF claiming >= 3 THEN
                    RAISE EXCEPTION 'school_domains: the School already holds the maximum number of domains (school_domains_limit_exceeded)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION school_domains_guard_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id
                   OR NEW.hostname IS DISTINCT FROM OLD.hostname OR NEW.type IS DISTINCT FROM OLD.type
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'school_domains: identity columns are immutable (school_domains_immutable)';
                END IF;

                IF OLD.state IN ('revoked', 'expired') THEN
                    RAISE EXCEPTION 'school_domains: % rows are terminal history (school_domains_terminal)', OLD.state;
                END IF;

                IF NEW.state IS DISTINCT FROM OLD.state AND (OLD.state, NEW.state) NOT IN (
                    ('pending_verification', 'verified'), ('pending_verification', 'expired'), ('pending_verification', 'revoked'),
                    ('verified', 'tls_pending'), ('verified', 'revoked'),
                    ('tls_pending', 'active'), ('tls_pending', 'revoked'),
                    ('active', 'suspended'), ('active', 'revoked'),
                    ('suspended', 'active'), ('suspended', 'revoked')
                ) THEN
                    RAISE EXCEPTION 'school_domains: state transition % -> % is not permitted (school_domains_transition)', OLD.state, NEW.state;
                END IF;

                -- The challenge changes only by regeneration while pending: one
                -- generation forward, never back, never after verification.
                IF NEW.challenge_token IS DISTINCT FROM OLD.challenge_token OR NEW.challenge_generation IS DISTINCT FROM OLD.challenge_generation THEN
                    IF OLD.state <> 'pending_verification' OR NEW.state <> 'pending_verification'
                       OR NEW.challenge_generation <> OLD.challenge_generation + 1 OR NEW.challenge_token IS NULL THEN
                        RAISE EXCEPTION 'school_domains: the challenge changes only by regeneration while pending (school_domains_challenge)';
                    END IF;
                END IF;

                -- Activation (and reactivation) records passing evidence in the same write.
                IF NEW.state = 'active' AND OLD.state <> 'active' AND (
                    NEW.ownership_outcome IS DISTINCT FROM 'match' OR NEW.routing_outcome IS DISTINCT FROM 'pass' OR NEW.tls_outcome IS DISTINCT FROM 'pass'
                ) THEN
                    RAISE EXCEPTION 'school_domains: activation requires passing ownership, routing and TLS evidence (school_domains_activation_evidence)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION school_domains_assert_primary() RETURNS trigger AS $$
            DECLARE
                active_count integer;
                primary_count integer;
            BEGIN
                PERFORM school_domains_lock_school(NEW.school_id);

                SELECT count(*) FILTER (WHERE state = 'active'), count(*) FILTER (WHERE is_primary)
                  INTO active_count, primary_count
                  FROM school_domains WHERE school_id = NEW.school_id;

                IF active_count > 0 AND primary_count <> 1 THEN
                    RAISE EXCEPTION 'school_domains: a School with an active domain has exactly one primary (school_domains_primary_invariant)';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_school_domains_guard_insert
                BEFORE INSERT ON school_domains
                FOR EACH ROW EXECUTE FUNCTION school_domains_guard_insert();

            CREATE TRIGGER trg_school_domains_guard_update
                BEFORE UPDATE ON school_domains
                FOR EACH ROW EXECUTE FUNCTION school_domains_guard_update();

            CREATE CONSTRAINT TRIGGER trg_school_domains_primary_invariant
                AFTER INSERT OR UPDATE OF state, is_primary ON school_domains
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION school_domains_assert_primary();
            SQL);

        TenantRls::revokeDelete('school_domains');
    }

    /**
     * Rolls the schema back to `domain` + `verified_at`. Only an `active` row
     * keeps a verified_at, so nothing else can resolve under the old code;
     * terminal history whose hostname repeats a newer row is dropped (the old
     * table-wide unique(domain) cannot hold it) -- a deliberate, documented
     * loss confined to rollback.
     */
    public function down(): void
    {
        DB::statement('GRANT DELETE ON school_domains TO school_os_app');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_school_domains_primary_invariant ON school_domains;
            DROP TRIGGER IF EXISTS trg_school_domains_guard_update ON school_domains;
            DROP TRIGGER IF EXISTS trg_school_domains_guard_insert ON school_domains;
            DROP FUNCTION IF EXISTS school_domains_assert_primary();
            DROP FUNCTION IF EXISTS school_domains_guard_update();
            DROP FUNCTION IF EXISTS school_domains_guard_insert();
            DROP FUNCTION IF EXISTS school_domains_lock_school(uuid);
            SQL);

        DB::statement('ALTER TABLE school_domains DROP CONSTRAINT school_domains_school_id_foreign');
        DB::statement('ALTER TABLE school_domains ADD CONSTRAINT school_domains_school_id_foreign FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE');

        DB::statement('DROP INDEX IF EXISTS school_domains_next_check_index');
        DB::statement('DROP INDEX IF EXISTS school_domains_hostname_index');
        DB::statement('DROP INDEX IF EXISTS school_domains_one_primary_per_school');
        DB::statement('DROP INDEX IF EXISTS school_domains_hostname_claim_unique');

        foreach (['fingerprint', 'tls_outcome', 'routing_outcome', 'ownership_outcome', 'expired', 'revoked', 'suspension_reason', 'suspended', 'active', 'generation', 'challenge', 'primary_active', 'hostname', 'state', 'type'] as $check) {
            DB::statement("ALTER TABLE school_domains DROP CONSTRAINT IF EXISTS school_domains_{$check}_check");
        }

        DB::statement("UPDATE school_domains SET verified_at = NULL WHERE state <> 'active'");
        DB::statement(<<<'SQL'
            DELETE FROM school_domains d
             WHERE EXISTS (
                SELECT 1 FROM school_domains n
                 WHERE n.hostname = d.hostname AND n.id <> d.id AND n.created_at >= d.created_at
                   AND (n.created_at > d.created_at OR n.id > d.id)
             )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE school_domains
                DROP COLUMN state, DROP COLUMN challenge_token, DROP COLUMN challenge_generation, DROP COLUMN challenge_expires_at,
                DROP COLUMN ownership_checked_at, DROP COLUMN ownership_outcome, DROP COLUMN routing_checked_at, DROP COLUMN routing_outcome,
                DROP COLUMN tls_checked_at, DROP COLUMN tls_outcome, DROP COLUMN certificate_not_after, DROP COLUMN certificate_fingerprint,
                DROP COLUMN certificate_issuer, DROP COLUMN activated_at, DROP COLUMN suspended_at, DROP COLUMN suspension_reason,
                DROP COLUMN revoked_at, DROP COLUMN revocation_source, DROP COLUMN expired_at,
                DROP COLUMN ownership_mismatch_count, DROP COLUMN ownership_mismatch_since, DROP COLUMN ownership_absent_count,
                DROP COLUMN ownership_absent_since, DROP COLUMN routing_fail_count, DROP COLUMN routing_fail_since,
                DROP COLUMN tls_fail_count, DROP COLUMN tls_fail_since, DROP COLUMN indeterminate_since,
                DROP COLUMN last_checked_at, DROP COLUMN next_check_at
            SQL);

        DB::statement('ALTER TABLE school_domains ALTER COLUMN type TYPE varchar(255)');
        DB::statement("ALTER TABLE school_domains ALTER COLUMN type SET DEFAULT 'platform_subdomain'");
        DB::statement('ALTER TABLE school_domains ALTER COLUMN hostname TYPE varchar(255)');
        DB::statement('ALTER TABLE school_domains RENAME COLUMN hostname TO domain');
        DB::statement('ALTER TABLE school_domains ADD CONSTRAINT school_domains_domain_unique UNIQUE (domain)');
    }
};
