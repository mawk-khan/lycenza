<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.12B (ADR 0059 sections 6, 16, 18): a School's staff account
 * invitation. Identity-domain, SCHOOL-owned (BelongsToSchool + forced RLS):
 * one School can never list, read, revoke or accept another School's
 * invitations. Deliberately NOT `identity_account_invitations` (whose
 * Guardian/Student semantics and path token stay unchanged).
 *
 * - Bound to ONE canonical destination email in ONE School; at most one
 *   `pending` invitation per (School, email) (partial unique index).
 * - Only SHA-256(secret) is stored; the 256-bit secret travels in the
 *   link's URL fragment. Lifetime at most 7 days; "expired" is derived.
 * - `pending -> accepted | revoked`, one way; the credential's identity never
 *   changes (trigger).
 * - The invited School roles live in `staff_account_invitation_roles`
 *   (same School by composite FK, School-scope roles only by trigger,
 *   immutable once written).
 * - No User or membership exists until acceptance.
 *
 * The destination email is Sensitive contact data: never logged or put in
 * audit metadata. Rows are technical records, pruned 7 days after they end
 * (`platform:staff-account-credentials-prune`); the School audit ledger is
 * the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('destination_email', 254);
            $table->string('selector', 22)->unique();
            $table->char('secret_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 16)->nullable();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('email_message_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE staff_account_invitations ADD CONSTRAINT staff_account_invitations_email_check CHECK (destination_email = lower(btrim(destination_email)) AND destination_email <> '')");
        DB::statement("ALTER TABLE staff_account_invitations ADD CONSTRAINT staff_account_invitations_selector_check CHECK (selector ~ '^[A-Za-z0-9_-]{22}$' AND secret_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE staff_account_invitations ADD CONSTRAINT staff_account_invitations_lifetime_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '7 days')");
        DB::statement(<<<'SQL'
            ALTER TABLE staff_account_invitations ADD CONSTRAINT staff_account_invitations_state_check CHECK (
                (status = 'pending' AND accepted_at IS NULL AND accepted_user_id IS NULL AND revoked_at IS NULL AND revocation_reason IS NULL)
                OR (status = 'accepted' AND accepted_at IS NOT NULL AND revoked_at IS NULL AND revocation_reason IS NULL)
                OR (status = 'revoked' AND revoked_at IS NOT NULL AND accepted_at IS NULL AND revocation_reason IN ('revoked', 'reissued'))
            )
            SQL);
        DB::statement("CREATE UNIQUE INDEX staff_account_invitations_one_pending ON staff_account_invitations (school_id, destination_email) WHERE status = 'pending'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_account_invitations_guard_update() RETURNS trigger AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.school_id <> OLD.school_id OR NEW.destination_email <> OLD.destination_email
                    OR NEW.selector <> OLD.selector OR NEW.secret_hash <> OLD.secret_hash
                    OR NEW.expires_at <> OLD.expires_at OR NEW.created_at <> OLD.created_at
                    OR NEW.invited_by_user_id IS DISTINCT FROM OLD.invited_by_user_id THEN
                    RAISE EXCEPTION 'staff_account_invitations: an invitation never changes (staff_account_invitations_immutable)';
                END IF;
                IF OLD.status <> 'pending' AND (NEW.status <> OLD.status
                        OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at
                        OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at) THEN
                    RAISE EXCEPTION 'staff_account_invitations: an ended invitation stays ended (staff_account_invitations_ended)';
                END IF;
                IF OLD.email_message_id IS NOT NULL AND NEW.email_message_id IS DISTINCT FROM OLD.email_message_id THEN
                    RAISE EXCEPTION 'staff_account_invitations: the email link never changes (staff_account_invitations_email)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_staff_account_invitations_guard_update BEFORE UPDATE ON staff_account_invitations
                FOR EACH ROW EXECUTE FUNCTION staff_account_invitations_guard_update();
            SQL);

        TenantRls::enable('staff_account_invitations');

        Schema::create('staff_account_invitation_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('staff_account_invitation_id');
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['staff_account_invitation_id', 'role_id']);
            $table->foreign(['staff_account_invitation_id', 'school_id'])
                ->references(['id', 'school_id'])->on('staff_account_invitations')
                ->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_staff_account_invitation_role_scope() RETURNS trigger AS $$
            DECLARE
                role_scope text;
            BEGIN
                SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                IF role_scope IS DISTINCT FROM 'school' THEN
                    RAISE EXCEPTION 'staff_account_invitation_roles.role_id must reference a role with scope=school (got %)', role_scope;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_staff_account_invitation_roles_scope
                BEFORE INSERT OR UPDATE ON staff_account_invitation_roles
                FOR EACH ROW EXECUTE FUNCTION assert_staff_account_invitation_role_scope();
            SQL);

        TenantRls::enable('staff_account_invitation_roles');
        TenantRls::makeAppendOnly('staff_account_invitation_roles');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_staff_account_invitation_roles_scope ON staff_account_invitation_roles');
        DB::statement('DROP FUNCTION IF EXISTS assert_staff_account_invitation_role_scope()');
        TenantRls::disable('staff_account_invitation_roles');
        Schema::dropIfExists('staff_account_invitation_roles');

        DB::statement('DROP TRIGGER IF EXISTS trg_staff_account_invitations_guard_update ON staff_account_invitations');
        DB::statement('DROP FUNCTION IF EXISTS staff_account_invitations_guard_update()');
        TenantRls::disable('staff_account_invitations');
        Schema::dropIfExists('staff_account_invitations');
    }
};
